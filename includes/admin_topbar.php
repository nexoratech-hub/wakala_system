<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\includes\admin_topbar.php
// WAKALA FINANCIAL SYSTEM - SHARED ADMIN TOP BAR
// WITH PROFILE PIC, DARK MODE, GLOBAL SEARCH, LIVE DATE/TIME
// ================================================================

$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'admin';
$profile_image = '../../assets/images/logo.PNG';
?>
<!-- ============================================================
ADMIN TOP BAR
============================================================ -->
<header class="admin-topbar">
    <div class="topbar-left">
        <!-- Mobile sidebar toggle -->
        <button class="topbar-toggle" id="topbarToggle" aria-label="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Page Title -->
        <h2 id="pageTitle">
            <i class="fas fa-chart-pie page-icon"></i>
            Dashboard
        </h2>
    </div>
    
    <div class="topbar-right">
        <!-- ============================================================
        GLOBAL SEARCH
        ============================================================ -->
        <div class="global-search">
            <i class="fas fa-search search-icon"></i>
            <input type="text" 
                   id="globalSearch" 
                   placeholder="Search anything..." 
                   autocomplete="off">
            <span class="search-shortcut">Ctrl+K</span>
            <div class="search-results" id="searchResults"></div>
        </div>
        
        <!-- ============================================================
        DARK MODE TOGGLE
        ============================================================ -->
        <button class="dark-mode-toggle" id="darkModeToggle" aria-label="Toggle Dark Mode">
            <i class="fas fa-moon" id="darkModeIcon"></i>
        </button>
        
        <!-- ============================================================
        NOTIFICATIONS
        ============================================================ -->
        <div class="notification-wrapper">
            <button class="notification-btn" id="notificationBtn">
                <i class="fas fa-bell"></i>
                <span class="notification-badge" id="notificationBadge">0</span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <h4>Notifications</h4>
                    <button class="mark-all-read">Mark all read</button>
                </div>
                <div class="notification-list" id="notificationList">
                    <div style="padding:20px;text-align:center;color:var(--text-light);">
                        <i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                        No notifications
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================================
        LIVE DATE & TIME
        ============================================================ -->
        <div class="live-datetime" id="liveDateTime">
            <i class="fas fa-clock" style="color:var(--text-light);"></i>
            <span id="liveTime">--:--:--</span>
            <span class="date-separator">|</span>
            <span id="liveDate">--/--/----</span>
        </div>
        
        <!-- ============================================================
        USER PROFILE
        ============================================================ -->
        <div class="user-profile">
            <img src="<?php echo $profile_image; ?>" alt="Profile" 
                 onerror="this.src='../../assets/images/default-avatar.png'">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <span class="user-role"><?php echo strtoupper($role); ?></span>
            </div>
            <button class="user-dropdown-btn" id="userDropdownBtn">
                <i class="fas fa-chevron-down"></i>
            </button>
            
            <!-- User Dropdown -->
            <div class="user-dropdown" id="userDropdown">
                <a href="../profile/index.php">
                    <i class="fas fa-user"></i> My Profile
                </a>
                <a href="../profile/change_password.php">
                    <i class="fas fa-key"></i> Change Password
                </a>
                <hr>
                <a href="../../logout.php" class="logout-dropdown">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
        
        <!-- ============================================================
        LAST UPDATED
        ============================================================ -->
        <div class="last-updated" id="lastUpdated">
            <i class="fas fa-check-circle" style="color:var(--success);"></i>
            <span>Updated: Just now</span>
        </div>
    </div>
</header>

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   ADMIN TOPBAR - COMPLETE
   ============================================================ */
.admin-topbar {
    background: var(--topbar-bg, #FFFFFF);
    padding: 12px 25px;
    border-radius: 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: background 0.3s ease, box-shadow 0.3s ease;
    position: sticky;
    top: 0;
    z-index: 500;
    border-bottom: 1px solid var(--border-color, #E5E7EB);
}

/* Topbar Left */
.topbar-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

.topbar-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 20px;
    color: var(--text-color, #1F2937);
    cursor: pointer;
    padding: 5px 8px;
    border-radius: 8px;
    transition: background 0.3s ease;
}

.topbar-toggle:hover {
    background: var(--bg-hover, #F3F4F6);
}

.topbar-left h2 {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-color, #1F2937);
    transition: color 0.3s ease;
}

.topbar-left h2 .page-icon {
    margin-right: 8px;
    color: var(--primary, #DC2626);
}

/* Topbar Right */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

/* ============================================================
   GLOBAL SEARCH
   ============================================================ */
.global-search {
    position: relative;
    display: flex;
    align-items: center;
}

.global-search .search-icon {
    position: absolute;
    left: 14px;
    color: var(--text-light, #9CA3AF);
    font-size: 14px;
}

.global-search input {
    width: 260px;
    padding: 9px 14px 9px 42px;
    border: 2px solid var(--border-color, #E5E7EB);
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    background: var(--bg-input, #F9FAFB);
    color: var(--text-color, #1F2937);
}

.global-search input:focus {
    outline: none;
    border-color: var(--primary, #DC2626);
    box-shadow: 0 0 0 4px rgba(220,38,38,0.08);
    background: var(--bg-card, #FFFFFF);
}

.global-search input::placeholder {
    color: var(--text-light, #9CA3AF);
}

.global-search .search-shortcut {
    position: absolute;
    right: 10px;
    font-size: 10px;
    color: var(--text-light, #9CA3AF);
    background: var(--border-color, #E5E7EB);
    padding: 1px 8px;
    border-radius: 4px;
    font-weight: 600;
}

/* Search Results */
.search-results {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    background: var(--bg-card, #FFFFFF);
    border-radius: 12px;
    box-shadow: var(--shadow-lg, 0 10px 40px rgba(0,0,0,0.12));
    border: 1px solid var(--border-color, #E5E7EB);
    max-height: 400px;
    overflow-y: auto;
    z-index: 1001;
    padding: 6px 0;
}

.search-results.active {
    display: block;
}

.search-results .result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    color: var(--text-color, #1F2937);
    text-decoration: none;
    transition: background 0.2s ease;
    cursor: pointer;
}

.search-results .result-item:hover {
    background: var(--bg-hover, #F3F4F6);
}

.search-results .result-item i {
    width: 18px;
    color: var(--text-light, #9CA3AF);
    font-size: 14px;
}

.search-results .result-item .result-title {
    font-weight: 500;
    font-size: 14px;
}

.search-results .result-empty {
    padding: 20px;
    text-align: center;
    color: var(--text-light, #9CA3AF);
}

/* ============================================================
   DARK MODE TOGGLE
   ============================================================ */
.dark-mode-toggle {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--text-secondary, #6B7280);
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.dark-mode-toggle:hover {
    background: var(--bg-hover, #F3F4F6);
    color: var(--text-color, #1F2937);
}

/* ============================================================
   LIVE DATE & TIME
   ============================================================ */
.live-datetime {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-secondary, #6B7280);
    font-weight: 500;
    padding: 4px 12px;
    background: var(--bg-hover, #F3F4F6);
    border-radius: 8px;
    border: 1px solid var(--border-color, #E5E7EB);
    white-space: nowrap;
}

.live-datetime i {
    font-size: 14px;
    color: var(--primary, #DC2626);
}

.live-datetime .date-separator {
    color: var(--text-light, #9CA3AF);
    margin: 0 2px;
}

/* ============================================================
   NOTIFICATIONS
   ============================================================ */
.notification-wrapper {
    position: relative;
}

.notification-btn {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--text-secondary, #6B7280);
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
    position: relative;
}

.notification-btn:hover {
    background: var(--bg-hover, #F3F4F6);
    color: var(--text-color, #1F2937);
}

.notification-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    background: var(--danger, #DC2626);
    color: white;
    border-radius: 50%;
    padding: 1px 6px;
    font-size: 10px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
    display: none;
}

.notification-badge.show {
    display: block;
}

.notification-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--bg-card, #FFFFFF);
    border-radius: 12px;
    box-shadow: var(--shadow-lg, 0 10px 40px rgba(0,0,0,0.12));
    border: 1px solid var(--border-color, #E5E7EB);
    width: 380px;
    max-height: 450px;
    overflow: hidden;
    z-index: 1001;
}

.notification-dropdown.show {
    display: block;
}

.notification-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-color, #E5E7EB);
}

.notification-header h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-color, #1F2937);
}

.notification-header .mark-all-read {
    background: none;
    border: none;
    color: var(--primary, #DC2626);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
}

.notification-list {
    max-height: 350px;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    gap: 12px;
    padding: 12px 18px;
    border-bottom: 1px solid var(--border-color, #F3F4F6);
    transition: background 0.2s ease;
    cursor: pointer;
}

.notification-item:hover {
    background: var(--bg-hover, #F9FAFB);
}

.notification-item .notif-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

.notification-item .notif-icon.info { background: #DBEAFE; color: #2563EB; }
.notification-item .notif-icon.success { background: #D1FAE5; color: #059669; }
.notification-item .notif-icon.warning { background: #FEF3C7; color: #D97706; }
.notification-item .notif-icon.danger { background: #FEE2E2; color: #DC2626; }

.notification-item .notif-content {
    flex: 1;
}

.notification-item .notif-title {
    font-weight: 500;
    font-size: 13px;
    color: var(--text-color, #1F2937);
}

.notification-item .notif-message {
    font-size: 12px;
    color: var(--text-secondary, #6B7280);
}

.notification-item .notif-time {
    font-size: 11px;
    color: var(--text-light, #9CA3AF);
}

/* ============================================================
   USER PROFILE
   ============================================================ */
.user-profile {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 12px 4px 4px;
    border-radius: 10px;
    cursor: pointer;
    transition: background 0.3s ease;
    position: relative;
}

.user-profile:hover {
    background: var(--bg-hover, #F3F4F6);
}

.user-profile img {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--primary, #DC2626);
    background: white;
    padding: 2px;
}

.user-profile .user-info {
    line-height: 1.3;
}

.user-profile .user-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-color, #1F2937);
}

.user-profile .user-role {
    font-size: 10px;
    font-weight: 600;
    color: var(--primary, #DC2626);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.user-dropdown-btn {
    background: none;
    border: none;
    color: var(--text-light, #9CA3AF);
    cursor: pointer;
    padding: 2px;
    font-size: 12px;
    transition: transform 0.3s ease;
}

.user-dropdown-btn.rotate {
    transform: rotate(180deg);
}

/* User Dropdown */
.user-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--bg-card, #FFFFFF);
    border-radius: 12px;
    box-shadow: var(--shadow-lg, 0 10px 40px rgba(0,0,0,0.12));
    border: 1px solid var(--border-color, #E5E7EB);
    min-width: 200px;
    padding: 6px 0;
    z-index: 1001;
}

.user-dropdown.show {
    display: block;
}

.user-dropdown a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    color: var(--text-color, #1F2937);
    text-decoration: none;
    font-size: 14px;
    transition: background 0.2s ease;
}

.user-dropdown a:hover {
    background: var(--bg-hover, #F3F4F6);
}

.user-dropdown a i {
    width: 18px;
    color: var(--text-light, #9CA3AF);
    font-size: 14px;
}

.user-dropdown hr {
    border: none;
    border-top: 1px solid var(--border-color, #E5E7EB);
    margin: 4px 12px;
}

.user-dropdown .logout-dropdown {
    color: var(--danger, #DC2626);
}

.user-dropdown .logout-dropdown i {
    color: var(--danger, #DC2626);
}

/* ============================================================
   LAST UPDATED
   ============================================================ */
.last-updated {
    font-size: 12px;
    color: var(--text-light, #9CA3AF);
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .global-search input {
        width: 180px;
    }
}

@media (max-width: 768px) {
    .admin-topbar {
        padding: 10px 15px;
        border-radius: 10px;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .topbar-toggle {
        display: block;
    }
    
    .topbar-left h2 {
        font-size: 16px;
    }
    
    .topbar-right {
        width: 100%;
        justify-content: space-between;
        flex-wrap: wrap;
    }
    
    .global-search {
        order: 10;
        width: 100%;
    }
    
    .global-search input {
        width: 100%;
    }
    
    .live-datetime {
        font-size: 12px;
        padding: 3px 10px;
    }
    
    .user-profile .user-info {
        display: none;
    }
    
    .notification-dropdown {
        width: 320px;
        right: -40px;
    }
    
    .last-updated {
        font-size: 10px;
    }
}

@media (max-width: 480px) {
    .admin-topbar {
        padding: 8px 12px;
    }
    
    .topbar-left h2 {
        font-size: 14px;
    }
    
    .topbar-left h2 .page-icon {
        display: none;
    }
    
    .global-search input {
        font-size: 13px;
        padding: 7px 12px 7px 36px;
    }
    
    .global-search .search-shortcut {
        display: none;
    }
    
    .live-datetime {
        font-size: 10px;
        padding: 2px 8px;
    }
    
    .live-datetime .date-separator {
        margin: 0 1px;
    }
    
    .user-profile img {
        width: 32px;
        height: 32px;
    }
    
    .notification-dropdown {
        width: 290px;
        right: -60px;
    }
    
    .last-updated {
        display: none;
    }
}
</style>

<script>
// ============================================================
// TOPBAR JAVASCRIPT
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // SIDEBAR TOGGLE (Mobile)
    // ============================================================
    const topbarToggle = document.getElementById('topbarToggle');
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (topbarToggle && sidebar && overlay) {
        topbarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }
    
    // ============================================================
    // DARK MODE TOGGLE - FIXED
    // ============================================================
    const darkToggle = document.getElementById('darkModeToggle');
    const darkIcon = document.getElementById('darkModeIcon');
    const htmlRoot = document.documentElement;
    
    // Load saved preference from localStorage
    const savedDarkMode = localStorage.getItem('darkMode') === 'true';
    
    // Apply dark mode if saved
    if (savedDarkMode) {
        htmlRoot.classList.add('dark-mode');
        darkIcon.className = 'fas fa-sun';
    }
    
    if (darkToggle) {
        darkToggle.addEventListener('click', function() {
            // Toggle dark mode class on html element
            htmlRoot.classList.toggle('dark-mode');
            
            // Update icon
            const isDark = htmlRoot.classList.contains('dark-mode');
            darkIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            
            // Save preference
            localStorage.setItem('darkMode', isDark);
            
            console.log('Dark mode:', isDark ? 'ON' : 'OFF');
        });
    }
    
    // ============================================================
    // USER DROPDOWN
    // ============================================================
    const userDropdownBtn = document.getElementById('userDropdownBtn');
    const userDropdown = document.getElementById('userDropdown');
    
    if (userDropdownBtn && userDropdown) {
        userDropdownBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdown.classList.toggle('show');
            this.classList.toggle('rotate');
        });
        
        document.addEventListener('click', function(e) {
            if (!userDropdown.contains(e.target) && !userDropdownBtn.contains(e.target)) {
                userDropdown.classList.remove('show');
                userDropdownBtn.classList.remove('rotate');
            }
        });
    }
    
    // ============================================================
    // NOTIFICATIONS
    // ============================================================
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBtn && notificationDropdown) {
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', function(e) {
            if (!notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
                notificationDropdown.classList.remove('show');
            }
        });
        
        // Mark all read
        const markAllRead = document.querySelector('.mark-all-read');
        if (markAllRead) {
            markAllRead.addEventListener('click', function() {
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    badge.textContent = '0';
                    badge.classList.remove('show');
                }
                const list = document.getElementById('notificationList');
                if (list) {
                    list.innerHTML = `
                        <div style="padding:20px;text-align:center;color:var(--text-light);">
                            <i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:var(--success);"></i>
                            All notifications read
                        </div>
                    `;
                }
                notificationDropdown.classList.remove('show');
            });
        }
    }
    
    // ============================================================
    // GLOBAL SEARCH
    // ============================================================
    const searchInput = document.getElementById('globalSearch');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput && searchResults) {
        const searchData = [
            { title: 'Dashboard', icon: 'fa-home', url: '#' },
            { title: 'Morning Report', icon: 'fa-sun', url: '../morning_report/index.php' },
            { title: 'Evening Stock', icon: 'fa-moon', url: '../evening_stock/index.php' },
            { title: 'Daily Report', icon: 'fa-file-alt', url: '../daily_report/index.php' },
            { title: 'Commissions', icon: 'fa-hand-holding-usd', url: '../commissions/index.php' },
            { title: 'Expenses', icon: 'fa-receipt', url: '../expenses/index.php' },
            { title: 'Store Cash Out', icon: 'fa-money-bill-wave', url: '../store_cash_out/index.php' },
            { title: 'Capital Management', icon: 'fa-building', url: '../capital_management/index.php' },
            { title: 'Salaries', icon: 'fa-wallet', url: '../salaries/index.php' },
            { title: 'Reports', icon: 'fa-chart-bar', url: '../reports/index.php' },
            { title: 'Employees', icon: 'fa-users', url: '../employees/index.php' },
            { title: 'Activity Logs', icon: 'fa-history', url: '../activity_logs/index.php' },
            { title: 'Settings', icon: 'fa-cog', url: '../settings/index.php' },
            { title: 'My Profile', icon: 'fa-user', url: '../profile/index.php' },
            { title: 'Change Password', icon: 'fa-key', url: '../profile/change_password.php' },
        ];
        
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            
            if (query.length === 0) {
                searchResults.classList.remove('active');
                return;
            }
            
            const results = searchData.filter(item => 
                item.title.toLowerCase().includes(query)
            );
            
            if (results.length === 0) {
                searchResults.innerHTML = `
                    <div class="result-empty">
                        <i class="fas fa-search"></i>
                        <p>No results found for "<strong>${query}</strong>"</p>
                    </div>
                `;
            } else {
                let html = '';
                results.forEach(item => {
                    html += `
                        <a href="${item.url}" class="result-item">
                            <i class="fas ${item.icon}"></i>
                            <span class="result-title">${item.title}</span>
                        </a>
                    `;
                });
                searchResults.innerHTML = html;
            }
            
            searchResults.classList.add('active');
        });
        
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove('active');
            }
        });
        
        // Keyboard shortcut: Ctrl+K
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }
    
    // ============================================================
    // LIVE DATE & TIME
    // ============================================================
    function updateLiveDateTime() {
        const now = new Date();
        
        // Time
        const timeEl = document.getElementById('liveTime');
        if (timeEl) {
            timeEl.textContent = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }
        
        // Date
        const dateEl = document.getElementById('liveDate');
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
    }
    
    // Update immediately and every second
    updateLiveDateTime();
    setInterval(updateLiveDateTime, 1000);
    
    // ============================================================
    // CONSOLE
    // ============================================================
    console.log('%c WAKALA ADMIN v2.0 ',
        'background:#8B0000; color:white; padding:8px 16px; border-radius:4px; font-size:14px; font-weight:bold;');
    console.log('%c 🌙 Dark Mode: ' + (localStorage.getItem('darkMode') === 'true' ? 'ON' : 'OFF'),
        'color:#6B7280; font-size:12px;');
    console.log('%c 🔍 Press Ctrl+K to search', 'color:#6B7280; font-size:12px;');
});
</script>