<?php
// ================================================================
// FILE: includes/employee_topbar.php
// WAKALA SYSTEM - EMPLOYEE TOPBAR
// ✅ HEIGHT 70px, TOPBAR TOUCHES SIDEBAR EDGE
// ✅ Search button (no ⌘K shortcut)
// ✅ Avatar-only profile (clickable → profile page)
// ✅ FIXED: Dark mode applies to whole page (html.dark-mode)
// ✅ FIXED: Topbar dynamically positions at sidebar edge (JS-based)
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
   ⭐ Position DYNAMICALLY set via JavaScript based on sidebar width
   ============================================================ */
:root {
    --topbar-height: 70px;
    --sidebar-width: 220px;  /* Default - will be overridden by JS */
    
    /* TOPBAR THEME VARIABLES - LIGHT */
    --topbar-bg: #ffffff;
    --topbar-text: #1f2937;
    --topbar-text-muted: #6b7280;
    --topbar-text-light: #9ca3af;
    --topbar-border: #e5e7eb;
    --topbar-input-bg: #f9fafb;
    --topbar-hover: #f3f4f6;
    --topbar-shadow: rgba(0,0,0,0.06);
    --topbar-accent: #bb0404;
}

/* DARK MODE */
html.dark-mode {
    --topbar-bg: #1e293b;
    --topbar-text: #f1f5f9;
    --topbar-text-muted: #94a3b8;
    --topbar-text-light: #64748b;
    --topbar-border: #334155;
    --topbar-input-bg: #334155;
    --topbar-hover: #334155;
    --topbar-shadow: rgba(0,0,0,0.3);
    --topbar-accent: #FCD34D;
}

/* ============================================================
   TOPBAR - Position set dynamically
   ⭐ NO left property - JavaScript will set it
   ============================================================ */
.employee-topbar {
    position: fixed;
    top: 0;
    /* left: SET BY JS */
    right: 0;
    /* width: SET BY JS */
    z-index: 1000;
    background: var(--topbar-bg);
    padding: 10px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--topbar-border);
    border-left: 1px solid var(--topbar-border);  /* ⭐ Separator line from sidebar */
    min-height: var(--topbar-height);
    height: var(--topbar-height);
    transition: background 0.3s ease, border-color 0.3s ease;
    box-shadow: 0 2px 8px var(--topbar-shadow);
    box-sizing: border-box;
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
    font-size: 20px;
    color: var(--topbar-text);
    cursor: pointer;
    padding: 6px 8px;
    border-radius: 8px;
    transition: background 0.3s ease;
}

.employee-topbar .topbar-left .menu-toggle:hover {
    background: var(--topbar-hover);
}

.employee-topbar .topbar-left .page-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--topbar-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.employee-topbar .topbar-left .page-title i {
    color: var(--topbar-accent);
    font-size: 18px;
}

/* ============================================================
   TOPBAR CENTER
   ============================================================ */
.employee-topbar .topbar-center {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    max-width: 620px;
    margin: 0 20px;
}

.employee-topbar .branch-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;
    background: #bb0404;
    color: #ffffff;
    border-radius: 22px;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
    border: 1px solid rgba(255,255,255,0.1);
    box-shadow: 0 2px 8px rgba(187,4,4,0.2);
}

.employee-topbar .branch-badge i {
    font-size: 14px;
    opacity: 0.9;
}

.employee-topbar .branch-badge .branch-code {
    font-size: 10px;
    opacity: 0.8;
    text-transform: uppercase;
    font-weight: 800;
}

/* ============================================================
   SEARCH WRAPPER - With Search Button
   ============================================================ */
.employee-topbar .search-wrapper {
    position: relative;
    flex: 1;
    min-width: 140px;
    max-width: 360px;
}

.employee-topbar .search-wrapper input {
    width: 100%;
    padding: 9px 44px 9px 16px;
    border: 1.5px solid var(--topbar-border);
    border-radius: 22px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    background: var(--topbar-input-bg);
    color: var(--topbar-text);
    transition: all 0.3s ease;
    outline: none;
    height: 42px;
}

.employee-topbar .search-wrapper input::placeholder {
    color: var(--topbar-text-light);
    font-size: 12px;
}

.employee-topbar .search-wrapper input:focus {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187, 4, 4, 0.1);
    background: var(--topbar-bg);
}

.employee-topbar .search-wrapper .search-btn {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: linear-gradient(135deg, #bb0404, #8a0303);
    border: none;
    color: #FFFFFF;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 6px rgba(187, 4, 4, 0.35);
}

.employee-topbar .search-wrapper .search-btn:hover {
    background: linear-gradient(135deg, #8a0303, #6b0202);
    transform: translateY(-50%) scale(1.08);
    box-shadow: 0 4px 12px rgba(187, 4, 4, 0.5);
}

.employee-topbar .search-wrapper .search-btn:active {
    transform: translateY(-50%) scale(0.95);
}

/* ============================================================
   TOPBAR RIGHT
   ============================================================ */
.employee-topbar .topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.employee-topbar .live-datetime {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--topbar-text-muted);
    padding: 8px 16px;
    background: var(--topbar-hover);
    border-radius: 22px;
    border: 1px solid var(--topbar-border);
    white-space: nowrap;
    font-weight: 600;
}

.employee-topbar .live-datetime i {
    color: var(--topbar-accent);
    font-size: 13px;
}

.employee-topbar .live-datetime .date-separator {
    color: var(--topbar-text-light);
    margin: 0 3px;
}

.employee-topbar .dark-mode-toggle {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--topbar-text-muted);
    cursor: pointer;
    padding: 8px 10px;
    border-radius: 10px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    min-height: 40px;
}

.employee-topbar .dark-mode-toggle:hover {
    background: var(--topbar-hover);
    color: var(--topbar-accent);
    transform: scale(1.08);
}

/* ============================================================
   USER PROFILE - Avatar only
   ============================================================ */
.employee-topbar .user-profile-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.employee-topbar .user-avatar-link {
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: transform 0.3s ease;
    border-radius: 50%;
    padding: 2px;
}

.employee-topbar .user-avatar-link:hover {
    transform: scale(1.06);
}

.employee-topbar .user-avatar-link img {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    object-fit: cover;
    border: 2.5px solid #bb0404;
    background: var(--topbar-bg);
    cursor: pointer;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
    display: block;
}

.employee-topbar .user-avatar-link:hover img {
    border-color: #8a0303;
    box-shadow: 0 0 0 4px rgba(187, 4, 4, 0.15);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .employee-topbar .topbar-center {
        max-width: 480px;
        gap: 10px;
    }
    .employee-topbar .search-wrapper {
        max-width: 260px;
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
        max-width: 380px;
    }
    .employee-topbar .search-wrapper {
        max-width: 220px;
    }
    .employee-topbar .live-datetime {
        display: none;
    }
}

@media (max-width: 768px) {
    .employee-topbar {
        left: 0 !important;
        width: 100% !important;
        border-left: none;
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
        display: none;
    }

    .employee-topbar .branch-badge {
        font-size: 11px;
        padding: 6px 12px;
    }

    .employee-topbar .user-avatar-link img {
        width: 36px;
        height: 36px;
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

    .employee-topbar .user-avatar-link img {
        width: 32px;
        height: 32px;
        border-width: 2px;
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
            <input type="text" id="globalSearch" placeholder="Search..." autocomplete="off">
            <button type="button" class="search-btn" id="searchBtn" aria-label="Search">
                <i class="fas fa-search"></i>
            </button>
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
        
        <div class="user-profile-wrapper">
            <a href="../profile/index_employee.php" class="user-avatar-link" 
               title="<?php echo htmlspecialchars($full_name); ?> (<?php echo strtoupper($role); ?>)">
                <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" 
                     onerror="this.src='../../assets/images/default-avatar.png'">
            </a>
        </div>
    </div>
</header>

<script>
(function() {
    // ============================================================
    // ⭐ DYNAMIC TOPBAR POSITIONING
    // Reads sidebar width and positions topbar at its RIGHT edge
    // ============================================================
    function positionTopbar() {
        var topbar = document.getElementById('employeeTopbar');
        if (!topbar) return;
        
        // Try to find sidebar
        var sidebar = document.querySelector('.employee-sidebar, .sidebar, aside.sidebar, #employeeSidebar, #adminSidebar, .admin-sidebar');
        
        var sidebarWidth = 220; // Default fallback
        
        if (sidebar) {
            var rect = sidebar.getBoundingClientRect();
            sidebarWidth = rect.width || sidebarWidth;
            
            // Also try computed style
            var computed = window.getComputedStyle(sidebar);
            if (computed.width && computed.width !== 'auto') {
                var cssWidth = parseFloat(computed.width);
                if (cssWidth > 0) {
                    sidebarWidth = cssWidth;
                }
            }
        } else {
            // Try reading from CSS variable
            var cssVar = getComputedStyle(document.documentElement).getPropertyValue('--sidebar-width');
            if (cssVar) {
                var parsed = parseFloat(cssVar);
                if (parsed > 0) sidebarWidth = parsed;
            }
        }
        
        // Handle mobile (sidebar hidden)
        var isMobile = window.innerWidth <= 768;
        
        if (isMobile) {
            topbar.style.left = '0px';
            topbar.style.width = '100%';
            topbar.style.borderLeft = 'none';
        } else {
            topbar.style.left = sidebarWidth + 'px';
            topbar.style.width = 'calc(100% - ' + sidebarWidth + 'px)';
            topbar.style.borderLeft = '1px solid var(--topbar-border)';
        }
    }
    
    // Run immediately
    positionTopbar();
    
    // Re-run on resize
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(positionTopbar, 50);
    });
    
    // Re-run when DOM is ready
    document.addEventListener('DOMContentLoaded', positionTopbar);
    
    // ⭐ Also run after sidebar loads/mutates (it might load after topbar)
    setTimeout(positionTopbar, 100);
    setTimeout(positionTopbar, 300);
    setTimeout(positionTopbar, 500);
    
    // Use MutationObserver to detect sidebar appearing
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function() {
            positionTopbar();
        });
        observer.observe(document.body, { 
            childList: true, 
            subtree: true 
        });
        
        // Stop observing after 2 seconds (perf optimization)
        setTimeout(function() {
            observer.disconnect();
        }, 2000);
    }
    
    // ============================================================
    // DARK MODE
    // ============================================================
    var darkToggle = document.getElementById('darkModeToggle');
    var darkIcon = document.getElementById('darkModeIcon');
    var htmlRoot = document.documentElement;

    var savedDarkMode = localStorage.getItem('darkMode');
    var isDarkMode = (savedDarkMode === 'true' || savedDarkMode === 'enabled');
    
    if (isDarkMode) {
        htmlRoot.classList.add('dark-mode');
        document.body.classList.add('dark-mode');
    }

    document.addEventListener('DOMContentLoaded', function() {
        
        if (darkIcon) {
            darkIcon.className = isDarkMode ? 'fas fa-sun' : 'fas fa-moon';
        }

        if (darkToggle) {
            darkToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                htmlRoot.classList.toggle('dark-mode');
                document.body.classList.toggle('dark-mode');

                var nowDark = htmlRoot.classList.contains('dark-mode');

                if (darkIcon) {
                    darkIcon.className = nowDark ? 'fas fa-sun' : 'fas fa-moon';
                }

                localStorage.setItem('darkMode', nowDark ? 'true' : 'false');

                document.dispatchEvent(new CustomEvent('darkModeChanged', {
                    detail: { isDark: nowDark }
                }));
            });
        }

        // ============================================================
        // LIVE DATE/TIME
        // ============================================================
        function updateDateTime() {
            var now = new Date();
            var timeEl = document.getElementById('liveTime');
            var dateEl = document.getElementById('liveDate');
            
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

        // ============================================================
        // SEARCH FUNCTIONALITY
        // ============================================================
        var searchInput = document.getElementById('globalSearch');
        var searchBtn = document.getElementById('searchBtn');
        
        function performSearch() {
            var query = searchInput ? searchInput.value.trim() : '';
            if (query.length === 0) return;
            
            var currentUrl = window.location.href.split('?')[0];
            window.location.href = currentUrl + '?search=' + encodeURIComponent(query);
        }
        
        if (searchBtn) {
            searchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                performSearch();
            });
        }
        
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    performSearch();
                }
            });
        }
    });
})();
</script>