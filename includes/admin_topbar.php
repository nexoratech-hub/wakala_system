<?php
// ================================================================
// FILE: includes/admin_topbar.php
// WAKALA FINANCIAL SYSTEM - SHARED ADMIN TOP BAR
// WITH COMPLETE DARK MODE SUPPORT
// FIXED: Uses 'branch_id' consistently + no variable collision
// ================================================================

// ============================================================
// GET COMPANY NAME FROM DATABASE
// ============================================================
$site_name = 'Wakala System'; // Default name

try {
    global $db;
    if (isset($db)) {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['setting_value'])) {
            $site_name = $result['setting_value'];
        }
    }
} catch (Exception $e) {
    $site_name = 'Wakala System';
}

// ============================================================
// GET BRANCHES FOR DROPDOWN
// ============================================================
$topbar_branches = [];
try {
    global $db;
    if (isset($db)) {
        $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
        $stmt->execute();
        $topbar_branches = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $topbar_branches = [];
}

// ============================================================
// GET CURRENT BRANCH - FROM URL ONLY (USES branch_id)
// ============================================================
// Read from 'branch_id' first, then fallback to 'branch' for compatibility
$current_branch = 0;

if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $current_branch = intval($_GET['branch_id']);
} 
elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0' && intval($_GET['branch']) > 0) {
    $current_branch = intval($_GET['branch']);
}

// Always clear old session memory
unset($_SESSION['selected_branch']);

// ============================================================
// GET USER DATA - INCLUDING PROFILE PICTURE
// ============================================================
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Get profile picture from database
$profile_image = '../../assets/images/logo.PNG'; // Default

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
        $profile_image = '../../assets/images/logo.PNG';
    }
}

// ============================================================
// GET CURRENT PAGE INFO
// ============================================================
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$current_page_name = basename($_SERVER['PHP_SELF']);

$page_titles = [
    'dashboard' => 'Dashboard',
    'morning_report' => 'Morning Report',
    'evening_stock' => 'Evening Stock',
    'daily_report' => 'Daily Report',
    'commissions' => 'Commissions',
    'expenses' => 'Expenses',
    'store_cash_out' => 'Store Cash Out',
    'capital_management' => 'Capital Management',
    'salaries' => 'Salaries',
    'reports' => 'Reports',
    'branches' => 'Branches',
    'providers' => 'Providers',
    'employees' => 'Employees',
    'activity_logs' => 'Activity Logs',
    'settings' => 'Settings',
    'profile' => 'Profile'
];
$page_title = $page_titles[$current_dir] ?? 'Dashboard';

$page_icons = [
    'dashboard' => 'fa-chart-pie',
    'morning_report' => 'fa-sun',
    'evening_stock' => 'fa-moon',
    'daily_report' => 'fa-file-alt',
    'commissions' => 'fa-hand-holding-usd',
    'expenses' => 'fa-receipt',
    'store_cash_out' => 'fa-money-bill-wave',
    'capital_management' => 'fa-building',
    'salaries' => 'fa-wallet',
    'reports' => 'fa-chart-bar',
    'branches' => 'fa-store',
    'providers' => 'fa-university',
    'employees' => 'fa-users',
    'activity_logs' => 'fa-history',
    'settings' => 'fa-cog',
    'profile' => 'fa-user'
];
$page_icon = $page_icons[$current_dir] ?? 'fa-chart-pie';

// ============================================================
// GET BRANCH NAME FOR DISPLAY (using $br - NOT $branch)
// ============================================================
$topbar_branch_name = 'All Branches';
if ($current_branch > 0) {
    foreach ($topbar_branches as $br) {
        if ($br['id'] == $current_branch) {
            $topbar_branch_name = $br['branch_name'];
            break;
        }
    }
}
?>

<!-- ============================================================
ADMIN TOP BAR
============================================================ -->
<header class="admin-topbar" id="adminTopbar">
    <div class="topbar-left">
        <button class="topbar-toggle" id="topbarToggle" aria-label="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-brand">
            <i class="fas fa-store brand-icon"></i>
            <span class="brand-page">
                <i class="fas <?php echo $page_icon; ?>"></i>
                <?php echo $page_title; ?>
            </span>
        </div>
    </div>
    
    <div class="topbar-right">
        <!-- Branch Selector - USES branch_id, uses $br (NOT $branch) -->
        <div class="branch-selector">
            <i class="fas fa-store branch-icon"></i>
            <select id="branchFilter" onchange="switchBranch(this.value)">
                <option value="0" <?php echo ($current_branch == 0) ? 'selected' : ''; ?>>All Branches</option>
                <?php foreach ($topbar_branches as $br): ?>
                    <option value="<?php echo $br['id']; ?>" <?php echo ($current_branch == $br['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($br['branch_name']); ?>
                        <?php if ($br['branch_code']): ?>
                            (<?php echo htmlspecialchars($br['branch_code']); ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($current_branch > 0): ?>
                <span class="branch-badge show">
                    <i class="fas fa-check-circle"></i>
                </span>
            <?php endif; ?>
        </div>
        
        <div class="global-search">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="globalSearch" placeholder="Search..." autocomplete="off">
            <span class="search-shortcut">⌘K</span>
            <div class="search-results" id="searchResults"></div>
        </div>
        
        <button class="dark-mode-toggle" id="darkModeToggle" aria-label="Toggle Dark Mode">
            <i class="fas fa-moon" id="darkModeIcon"></i>
        </button>
        
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
                    <div style="padding:16px;text-align:center;color:var(--topbar-text-light);">
                        <i class="fas fa-bell-slash" style="font-size:20px;display:block;margin-bottom:6px;"></i>
                        No notifications
                    </div>
                </div>
            </div>
        </div>
        
        <div class="live-datetime" id="liveDateTime">
            <i class="fas fa-clock"></i>
            <span id="liveTime">--:--:--</span>
            <span class="date-separator">|</span>
            <span id="liveDate">--/--/----</span>
        </div>
        
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
    </div>
</header>

<!-- ============================================================
TOPBAR STYLES
============================================================ -->
<style>
:root {
    --topbar-bg: #FFFFFF;
    --topbar-text: #1F2937;
    --topbar-text-secondary: #6B7280;
    --topbar-text-light: #9CA3AF;
    --topbar-border: #E5E7EB;
    --topbar-input-bg: #F9FAFB;
    --topbar-hover: #F3F4F6;
    --topbar-card-bg: #FFFFFF;
    --topbar-shadow: rgba(0,0,0,0.06);
    --topbar-shadow-lg: rgba(0,0,0,0.12);
    --topbar-dropdown-bg: #FFFFFF;
    --topbar-dropdown-border: #E5E7EB;
    --topbar-icon-color: #6B7280;
}

html.dark-mode {
    --topbar-bg: #1F2937;
    --topbar-text: #F9FAFB;
    --topbar-text-secondary: #9CA3AF;
    --topbar-text-light: #6B7280;
    --topbar-border: #374151;
    --topbar-input-bg: #374151;
    --topbar-hover: #374151;
    --topbar-card-bg: #1F2937;
    --topbar-shadow: rgba(0,0,0,0.3);
    --topbar-shadow-lg: rgba(0,0,0,0.4);
    --topbar-dropdown-bg: #1F2937;
    --topbar-dropdown-border: #374151;
    --topbar-icon-color: #9CA3AF;
}

.admin-topbar {
    position: fixed;
    top: 0;
    left: 260px;
    right: 0;
    height: 72px;
    background: var(--topbar-bg);
    padding: 0 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px var(--topbar-shadow);
    border-bottom: 1px solid var(--topbar-border);
    transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    z-index: 999;
    overflow: hidden;
    max-width: 100%;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    min-width: 0;
}

.topbar-toggle {
    display: none;
    background: none;
    border: none;
    font-size: 20px;
    color: var(--topbar-text);
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.3s ease, color 0.3s ease;
}

.topbar-toggle:hover {
    background: var(--topbar-hover);
}

.topbar-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: var(--topbar-text);
    flex-shrink: 0;
}

.topbar-brand .brand-icon {
    color: #DC2626;
    font-size: 20px;
}

.topbar-brand .brand-page {
    font-size: 16px;
    font-weight: 600;
    color: var(--topbar-text);
    display: flex;
    align-items: center;
    gap: 6px;
}

.topbar-brand .brand-page i {
    color: #DC2626;
    font-size: 14px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: nowrap;
    flex: 1;
    justify-content: flex-end;
    min-width: 0;
    overflow: hidden;
}

.branch-selector {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px 4px 4px;
    background: var(--topbar-input-bg);
    border-radius: 8px;
    border: 1.5px solid var(--topbar-border);
    transition: all 0.3s ease;
    flex-shrink: 1;
    min-width: 0;
    max-width: 200px;
}

.branch-selector:hover {
    border-color: #DC2626;
}

.branch-selector .branch-icon {
    color: #DC2626;
    font-size: 13px;
    padding: 3px 4px;
    flex-shrink: 0;
}

.branch-selector select {
    background: transparent;
    border: none;
    padding: 5px 2px 5px 0;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    color: var(--topbar-text);
    cursor: pointer;
    outline: none;
    min-width: 60px;
    max-width: 110px;
    transition: color 0.3s ease;
    text-overflow: ellipsis;
}

.branch-selector select option {
    background: var(--topbar-dropdown-bg);
    color: var(--topbar-text);
    padding: 4px 8px;
}

.branch-selector .branch-badge {
    font-size: 8px;
    font-weight: 600;
    color: #10B981;
    display: none;
    flex-shrink: 0;
}

.branch-selector .branch-badge.show {
    display: inline-block;
}

.global-search {
    position: relative;
    display: flex;
    align-items: center;
    flex-shrink: 1;
    min-width: 100px;
    max-width: 180px;
}

.global-search .search-icon {
    position: absolute;
    left: 10px;
    color: var(--topbar-text-light);
    font-size: 12px;
}

.global-search input {
    width: 100%;
    padding: 6px 10px 6px 32px;
    border: 1.5px solid var(--topbar-border);
    border-radius: 8px;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    background: var(--topbar-input-bg);
    color: var(--topbar-text);
}

.global-search input::placeholder {
    color: var(--topbar-text-light);
    font-size: 11px;
}

.global-search input:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
    background: var(--topbar-card-bg);
}

.global-search .search-shortcut {
    position: absolute;
    right: 8px;
    font-size: 9px;
    color: var(--topbar-text-light);
    background: var(--topbar-border);
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
}

.search-results {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    background: var(--topbar-dropdown-bg);
    border-radius: 10px;
    box-shadow: 0 10px 40px var(--topbar-shadow-lg);
    border: 1px solid var(--topbar-dropdown-border);
    max-height: 300px;
    overflow-y: auto;
    z-index: 1001;
}

.search-results.active {
    display: block;
}

.result-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 14px;
    color: var(--topbar-text);
    text-decoration: none;
    font-size: 12px;
    transition: background 0.2s ease;
}

.result-item:hover {
    background: var(--topbar-hover);
}

.result-item i {
    width: 16px;
    color: #DC2626;
}

.result-empty {
    padding: 16px;
    text-align: center;
    color: var(--topbar-text-light);
    font-size: 12px;
}

.dark-mode-toggle {
    background: none;
    border: none;
    font-size: 16px;
    color: var(--topbar-icon-color);
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.dark-mode-toggle:hover {
    background: var(--topbar-hover);
    color: var(--topbar-text);
}

.live-datetime {
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    color: var(--topbar-text-secondary);
    font-weight: 500;
    padding: 3px 10px;
    background: var(--topbar-hover);
    border-radius: 8px;
    border: 1px solid var(--topbar-border);
    white-space: nowrap;
    flex-shrink: 0;
}

.live-datetime i {
    font-size: 10px;
    color: #DC2626;
}

.live-datetime .date-separator {
    color: var(--topbar-text-light);
    margin: 0 1px;
}

.notification-wrapper {
    position: relative;
    flex-shrink: 0;
}

.notification-btn {
    background: none;
    border: none;
    font-size: 16px;
    color: var(--topbar-icon-color);
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    transition: all 0.3s ease;
    position: relative;
}

.notification-btn:hover {
    background: var(--topbar-hover);
    color: var(--topbar-text);
}

.notification-badge {
    position: absolute;
    top: 0px;
    right: 0px;
    background: #DC2626;
    color: #FFFFFF;
    border-radius: 50%;
    padding: 1px 4px;
    font-size: 7px;
    font-weight: 700;
    min-width: 14px;
    text-align: center;
    display: none;
}

.notification-badge.show {
    display: block;
}

.notification-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: var(--topbar-dropdown-bg);
    border-radius: 10px;
    box-shadow: 0 10px 40px var(--topbar-shadow-lg);
    border: 1px solid var(--topbar-dropdown-border);
    width: 300px;
    max-height: 400px;
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
    padding: 8px 14px;
    border-bottom: 1px solid var(--topbar-border);
}

.notification-header h4 {
    font-size: 12px;
    font-weight: 600;
    color: var(--topbar-text);
}

.notification-header .mark-all-read {
    background: none;
    border: none;
    color: #DC2626;
    font-size: 10px;
    font-weight: 500;
    cursor: pointer;
}

.notification-list {
    max-height: 320px;
    overflow-y: auto;
}

.user-profile {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 8px 2px 2px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.3s ease;
    position: relative;
    flex-shrink: 0;
}

.user-profile:hover {
    background: var(--topbar-hover);
}

.user-profile img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #DC2626;
    background: white;
    padding: 2px;
}

.user-profile .user-info {
    line-height: 1.2;
}

.user-profile .user-name {
    font-weight: 600;
    font-size: 11px;
    color: var(--topbar-text);
}

.user-profile .user-role {
    font-size: 7px;
    font-weight: 600;
    color: #DC2626;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.user-dropdown-btn {
    background: none;
    border: none;
    color: var(--topbar-text-light);
    cursor: pointer;
    padding: 1px;
    font-size: 9px;
    transition: transform 0.3s ease, color 0.3s ease;
}

.user-dropdown-btn.rotate {
    transform: rotate(180deg);
}

.user-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: var(--topbar-dropdown-bg);
    border-radius: 10px;
    box-shadow: 0 10px 40px var(--topbar-shadow-lg);
    border: 1px solid var(--topbar-dropdown-border);
    min-width: 160px;
    padding: 4px 0;
    z-index: 1001;
}

.user-dropdown.show {
    display: block;
}

.user-dropdown a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    color: var(--topbar-text);
    text-decoration: none;
    font-size: 12px;
    transition: background 0.2s ease, color 0.2s ease;
}

.user-dropdown a:hover {
    background: var(--topbar-hover);
}

.user-dropdown a i {
    width: 14px;
    color: var(--topbar-text-light);
    font-size: 12px;
}

.user-dropdown hr {
    border: none;
    border-top: 1px solid var(--topbar-border);
    margin: 2px 8px;
}

.user-dropdown .logout-dropdown {
    color: #DC2626;
}

.user-dropdown .logout-dropdown i {
    color: #DC2626;
}

.main-wrapper {
    margin-left: 260px;
    padding-top: 72px;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

@media (max-width: 1200px) {
    .global-search { min-width: 80px; max-width: 140px; }
    .branch-selector select { min-width: 50px; max-width: 80px; font-size: 11px; }
}

@media (max-width: 1024px) {
    .global-search { min-width: 70px; max-width: 120px; }
    .branch-selector select { min-width: 50px; max-width: 70px; font-size: 11px; }
    .topbar-brand .brand-page { font-size: 14px; }
    .topbar-brand .brand-page i { font-size: 12px; }
}

@media (max-width: 768px) {
    .admin-topbar {
        left: 0;
        height: 64px;
        padding: 0 12px;
        flex-wrap: wrap;
        gap: 4px;
        align-items: center;
    }
    .topbar-toggle { display: block; font-size: 16px; padding: 4px 6px; }
    .topbar-brand .brand-page { font-size: 13px; }
    .topbar-brand .brand-page i { font-size: 11px; }
    .topbar-brand .brand-icon { font-size: 16px; }
    .topbar-right { 
        gap: 4px; 
        flex-wrap: nowrap; 
        overflow-x: auto; 
        padding: 2px 0;
        flex: 1 1 100%;
        justify-content: flex-start;
    }
    .global-search { min-width: 60px; max-width: 100px; }
    .global-search input { font-size: 10px; padding: 4px 6px 4px 28px; }
    .global-search .search-shortcut { display: none; }
    .global-search .search-icon { font-size: 10px; left: 6px; }
    .branch-selector { padding: 2px 4px 2px 2px; max-width: 120px; }
    .branch-selector select { font-size: 10px; min-width: 40px; max-width: 60px; }
    .branch-selector .branch-badge { display: none !important; }
    .branch-selector .branch-icon { font-size: 10px; padding: 2px 3px; }
    .live-datetime { font-size: 9px; padding: 2px 6px; }
    .live-datetime .date-separator { margin: 0 1px; }
    .live-datetime i { display: none; }
    .user-profile .user-info { display: none; }
    .user-profile img { width: 28px; height: 28px; }
    .user-profile { padding: 2px 4px 2px 2px; }
    .dark-mode-toggle { font-size: 14px; padding: 3px 4px; }
    .notification-btn { font-size: 14px; padding: 3px 4px; }
    .notification-dropdown { width: 260px; right: -20px; }
    .main-wrapper { margin-left: 0; padding-top: 64px; }
}

@media (max-width: 480px) {
    .admin-topbar { height: 60px; padding: 0 8px; }
    .topbar-brand .brand-page { font-size: 11px; }
    .topbar-brand .brand-page i { font-size: 10px; }
    .topbar-brand .brand-icon { font-size: 14px; }
    .topbar-toggle { font-size: 14px; padding: 3px 5px; }
    .topbar-right { gap: 3px; }
    .global-search { min-width: 50px; max-width: 80px; }
    .global-search input { font-size: 9px; padding: 3px 4px 3px 22px; }
    .global-search .search-icon { font-size: 9px; left: 5px; }
    .branch-selector select { font-size: 9px; min-width: 35px; max-width: 50px; }
    .branch-selector .branch-icon { font-size: 9px; padding: 2px 2px; }
    .branch-selector { padding: 2px 3px 2px 2px; max-width: 80px; }
    .live-datetime { font-size: 8px; padding: 1px 4px; }
    .live-datetime i { display: none; }
    .user-profile img { width: 24px; height: 24px; }
    .dark-mode-toggle { font-size: 12px; padding: 2px 3px; }
    .notification-btn { font-size: 12px; padding: 2px 3px; }
    .notification-dropdown { width: 240px; right: -40px; }
    .main-wrapper { padding-top: 60px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // BRANCH SWITCH FUNCTION - USES branch_id CONSISTENTLY
    // ============================================================
    window.switchBranch = function(branchId) {
        var currentUrl = window.location.href;
        var url = new URL(currentUrl);
        
        // Always remove both params first - clean slate
        url.searchParams.delete('branch');
        url.searchParams.delete('branch_id');
        
        // Add branch_id only if not 0 (All Branches)
        if (branchId != 0) {
            url.searchParams.set('branch_id', branchId);
        }
        
        // Navigate
        window.location.href = url.toString();
    };
    
    // ============================================================
    // SIDEBAR TOGGLE
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
    // DARK MODE
    // ============================================================
    const darkToggle = document.getElementById('darkModeToggle');
    const darkIcon = document.getElementById('darkModeIcon');
    const htmlRoot = document.documentElement;
    
    const savedDarkMode = localStorage.getItem('darkMode');
    if (savedDarkMode === 'true') {
        htmlRoot.classList.add('dark-mode');
        if (darkIcon) darkIcon.className = 'fas fa-sun';
    } else {
        htmlRoot.classList.remove('dark-mode');
        if (darkIcon) darkIcon.className = 'fas fa-moon';
    }
    
    if (darkToggle) {
        darkToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            htmlRoot.classList.toggle('dark-mode');
            const isDark = htmlRoot.classList.contains('dark-mode');
            
            if (darkIcon) {
                darkIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
            
            localStorage.setItem('darkMode', isDark);
            document.dispatchEvent(new CustomEvent('darkModeChanged', { detail: { isDark: isDark } }));
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
    }
    
    // ============================================================
    // GLOBAL SEARCH
    // ============================================================
    const searchInput = document.getElementById('globalSearch');
    const searchResults = document.getElementById('searchResults');
    
    if (searchInput && searchResults) {
        const searchData = [
            { title: 'Dashboard', icon: 'fa-home', url: '../dashboard/admin.php' },
            { title: 'Morning Report', icon: 'fa-sun', url: '../morning_report/index.php' },
            { title: 'Evening Stock', icon: 'fa-moon', url: '../evening_stock/index.php' },
            { title: 'Daily Report', icon: 'fa-file-alt', url: '../daily_report/index.php' },
            { title: 'Commissions', icon: 'fa-hand-holding-usd', url: '../commissions/index.php' },
            { title: 'Expenses', icon: 'fa-receipt', url: '../expenses/index.php' },
            { title: 'Store Cash Out', icon: 'fa-money-bill-wave', url: '../store_cash_out/index.php' },
            { title: 'Capital Management', icon: 'fa-building', url: '../capital_management/index.php' },
            { title: 'Salaries', icon: 'fa-wallet', url: '../salaries/index.php' },
            { title: 'Reports', icon: 'fa-chart-bar', url: '../reports/index.php' },
            { title: 'Branches', icon: 'fa-store', url: '../branches/index.php' },
            { title: 'Providers', icon: 'fa-university', url: '../providers/index.php' },
            { title: 'Employees', icon: 'fa-users', url: '../employees/index.php' },
            { title: 'Activity Logs', icon: 'fa-history', url: '../activity_logs/index.php' },
            { title: 'Settings', icon: 'fa-cog', url: '../settings/index.php' },
            { title: 'My Profile', icon: 'fa-user', url: '../profile/index.php' },
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
                searchResults.innerHTML = `<div class="result-empty">No results found for "<strong>${query}</strong>"</div>`;
            } else {
                let html = '';
                results.forEach(item => {
                    html += `<a href="${item.url}" class="result-item">
                        <i class="fas ${item.icon}"></i>
                        <span class="result-title">${item.title}</span>
                    </a>`;
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
        
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
    }
    
    // ============================================================
    // LIVE DATE/TIME
    // ============================================================
    function updateLiveDateTime() {
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
    updateLiveDateTime();
    setInterval(updateLiveDateTime, 1000);
});
</script>