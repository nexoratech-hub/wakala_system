<?php
// ================================================================
// FILE: includes/admin_header.php
// WAKALA FINANCIAL SYSTEM - SHARED ADMIN HEADER
// WITH PROFILE PICTURE FROM DATABASE
// ================================================================

// ============================================================
// GET BRANCHES FOR DROPDOWN
// ============================================================
$branches = [];
try {
    global $db;
    if (isset($db)) {
        $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
        $stmt->execute();
        $branches = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $branches = [];
}

// Get current branch from session or GET
$current_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
if ($current_branch == 0 && isset($_SESSION['selected_branch'])) {
    $current_branch = $_SESSION['selected_branch'];
}

// Get user data
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'] ?? 0;

// ============================================================
// GET PROFILE PICTURE FROM DATABASE
// ============================================================
$profile_image = '../../assets/images/logo.PNG'; // Default fallback

if ($user_id > 0) {
    try {
        $stmt = $db->prepare("SELECT profile_pic FROM employees WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch();
        
        if ($user_data && !empty($user_data['profile_pic'])) {
            $profile_pic = $user_data['profile_pic'];
            
            // Check if file exists in different possible paths
            $paths_to_check = [
                '../../' . $profile_pic,
                $profile_pic,
                '../../uploads/profiles/' . basename($profile_pic)
            ];
            
            foreach ($paths_to_check as $path) {
                if (file_exists($path)) {
                    $profile_image = '../../' . $profile_pic;
                    break;
                }
            }
            
            // If still not found, try direct path
            if ($profile_image == '../../assets/images/logo.PNG' && file_exists($profile_pic)) {
                $profile_image = $profile_pic;
            }
        }
    } catch (Exception $e) {
        // If error, use default
        $profile_image = '../../assets/images/logo.PNG';
    }
}

// Store in session for quick access
$_SESSION['profile_pic'] = $profile_image;

// Get current page for title
$page_title = 'Dashboard';
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Set page title based on directory
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

if (isset($page_titles[$current_dir])) {
    $page_title = $page_titles[$current_dir];
}

// Check if user is admin
$is_admin = ($role === 'admin' || $role === 'super_admin');
?>
<!DOCTYPE html>
<html lang="en" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $page_title; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/images/logo.PNG" type="image/png">
    <link rel="shortcut icon" href="../../assets/images/logo.PNG" type="image/png">
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../../assets/css/dark-mode.css">
    
    <style>
        /* ============================================================
           BASE STYLES
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-body, #F3F4F6);
            color: var(--text-primary, #1F2937);
            display: flex;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        :root {
            --bg-body: #F3F4F6;
            --bg-card: #FFFFFF;
            --topbar-bg: #FFFFFF;
            --text-primary: #1F2937;
            --text-secondary: #6B7280;
            --text-light: #9CA3AF;
            --border-color: #E5E7EB;
            --bg-input: #F9FAFB;
            --bg-hover: #F3F4F6;
            --shadow: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-hover: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        /* ============================================================
           DARK MODE
           ============================================================ */
        html.dark-mode {
            --bg-body: #111827;
            --bg-card: #1F2937;
            --topbar-bg: #1F2937;
            --text-primary: #F9FAFB;
            --text-secondary: #9CA3AF;
            --text-light: #6B7280;
            --border-color: #374151;
            --bg-input: #374151;
            --bg-hover: #374151;
        }
        
        /* ============================================================
           MAIN CONTENT WRAPPER
           ============================================================ */
        .main-wrapper {
            margin-left: 250px;
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .main-content {
            padding: 20px 24px;
            flex: 1;
        }
        
        /* ============================================================
           TOPBAR - SMALLER & COMPACT
           ============================================================ */
        .admin-topbar {
            background: var(--topbar-bg);
            padding: 6px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 500;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border-bottom: 1px solid var(--border-color);
            transition: background 0.3s ease;
            min-height: 50px;
            gap: 8px;
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        
        .topbar-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 17px;
            color: var(--text-primary);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.3s ease;
        }
        
        .topbar-toggle:hover {
            background: var(--bg-hover);
        }
        
        .topbar-left h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            transition: color 0.3s ease;
            white-space: nowrap;
        }
        
        .topbar-left h2 .page-icon {
            margin-right: 6px;
            color: #DC2626;
            font-size: 15px;
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            flex: 1;
            justify-content: flex-end;
        }
        
        /* ============================================================
           BRANCH DROPDOWN - SMALLER WIDTH
           ============================================================ */
        .branch-selector {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px 3px 3px;
            background: var(--bg-input);
            border-radius: 6px;
            border: 1.5px solid var(--border-color);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .branch-selector:hover {
            border-color: #DC2626;
        }
        
        .branch-selector .branch-icon {
            color: #DC2626;
            font-size: 12px;
            padding: 3px 4px;
        }
        
        .branch-selector select {
            background: transparent;
            border: none;
            padding: 4px 2px 4px 0;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            cursor: pointer;
            outline: none;
            min-width: 90px;
            max-width: 130px;
        }
        
        .branch-selector select option {
            background: var(--bg-card);
            color: var(--text-primary);
            padding: 3px 6px;
        }
        
        .branch-selector .branch-badge {
            font-size: 8px;
            font-weight: 600;
            color: #DC2626;
            background: rgba(220,38,38,0.1);
            padding: 1px 6px;
            border-radius: 10px;
            white-space: nowrap;
            display: none;
        }
        
        .branch-selector .branch-badge.show {
            display: inline-block;
        }
        
        /* ============================================================
           SEARCH - COMPACT
           ============================================================ */
        .global-search {
            position: relative;
            flex-shrink: 1;
            min-width: 120px;
            max-width: 180px;
        }
        
        .global-search .search-icon {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 11px;
        }
        
        .global-search input {
            width: 100%;
            padding: 5px 8px 5px 28px;
            border: 1.5px solid var(--border-color);
            border-radius: 6px;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            background: var(--bg-input);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .global-search input:focus {
            outline: none;
            border-color: #DC2626;
            box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
        }
        
        .global-search input::placeholder {
            color: var(--text-light);
            font-size: 11px;
        }
        
        .global-search .search-shortcut {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 8px;
            color: var(--text-light);
            background: var(--border-color);
            padding: 1px 5px;
            border-radius: 3px;
            font-weight: 600;
        }
        
        .search-results {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: var(--bg-card);
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1001;
            padding: 4px 0;
        }
        
        .search-results.active {
            display: block;
        }
        
        .search-results .result-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            color: var(--text-primary);
            text-decoration: none;
            transition: background 0.2s ease;
            font-size: 12px;
        }
        
        .search-results .result-item:hover {
            background: var(--bg-hover);
        }
        
        .search-results .result-item i {
            width: 14px;
            color: var(--text-light);
            font-size: 12px;
        }
        
        .search-results .result-empty {
            padding: 12px;
            text-align: center;
            color: var(--text-light);
            font-size: 12px;
        }
        
        /* ============================================================
           DARK MODE TOGGLE - COMPACT
           ============================================================ */
        .dark-mode-toggle {
            background: none;
            border: none;
            font-size: 15px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .dark-mode-toggle:hover {
            background: var(--bg-hover);
        }
        
        /* ============================================================
           LIVE DATE/TIME - COMPACT
           ============================================================ */
        .live-datetime {
            display: flex;
            align-items: center;
            gap: 3px;
            font-size: 10px;
            color: var(--text-secondary);
            font-weight: 500;
            padding: 2px 8px;
            background: var(--bg-hover);
            border-radius: 6px;
            border: 1px solid var(--border-color);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .live-datetime i {
            color: #DC2626;
            font-size: 10px;
        }
        
        .live-datetime .date-separator {
            color: var(--text-light);
            margin: 0 1px;
        }
        
        /* ============================================================
           NOTIFICATIONS - COMPACT
           ============================================================ */
        .notification-wrapper {
            position: relative;
            flex-shrink: 0;
        }
        
        .notification-btn {
            background: none;
            border: none;
            font-size: 15px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .notification-btn:hover {
            background: var(--bg-hover);
        }
        
        .notification-badge {
            position: absolute;
            top: 0px;
            right: 0px;
            background: #DC2626;
            color: white;
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
            top: calc(100% + 4px);
            right: 0;
            background: var(--bg-card);
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            width: 280px;
            max-height: 350px;
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
            border-bottom: 1px solid var(--border-color);
        }
        
        .notification-header h4 {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
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
            max-height: 280px;
            overflow-y: auto;
        }
        
        .notification-item {
            display: flex;
            gap: 8px;
            padding: 8px 14px;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s ease;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: var(--bg-hover);
        }
        
        .notification-item .notif-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
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
            font-size: 11px;
            color: var(--text-primary);
        }
        
        .notification-item .notif-message {
            font-size: 10px;
            color: var(--text-secondary);
        }
        
        .notification-item .notif-time {
            font-size: 9px;
            color: var(--text-light);
        }
        
        /* ============================================================
           USER PROFILE - COMPACT
           ============================================================ */
        .user-profile {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 2px 6px 2px 2px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s ease;
            position: relative;
            flex-shrink: 0;
            text-decoration: none;
        }
        
        .user-profile:hover {
            background: var(--bg-hover);
        }
        
        .user-profile .profile-img-wrapper {
            position: relative;
            width: 32px;
            height: 32px;
            flex-shrink: 0;
        }
        
        .user-profile img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #DC2626;
            background: #ffffff;
            display: block;
        }
        
        .user-profile .online-dot {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 10px;
            height: 10px;
            background: #10B981;
            border-radius: 50%;
            border: 2px solid var(--topbar-bg);
        }
        
        .user-profile .user-info {
            line-height: 1.2;
        }
        
        .user-profile .user-name {
            font-weight: 600;
            font-size: 11px;
            color: var(--text-primary);
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
            color: var(--text-light);
            cursor: pointer;
            padding: 1px;
            font-size: 9px;
            transition: transform 0.3s ease;
        }
        
        .user-dropdown-btn.rotate {
            transform: rotate(180deg);
        }
        
        .user-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            background: var(--bg-card);
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            min-width: 180px;
            padding: 4px 0;
            z-index: 1001;
        }
        
        .user-dropdown.show {
            display: block;
        }
        
        .user-dropdown .dropdown-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .user-dropdown .dropdown-header img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #DC2626;
        }
        
        .user-dropdown .dropdown-header .dd-user-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .user-dropdown .dropdown-header .dd-user-role {
            font-size: 10px;
            color: var(--text-secondary);
        }
        
        .user-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 12px;
            transition: background 0.2s ease;
        }
        
        .user-dropdown a:hover {
            background: var(--bg-hover);
        }
        
        .user-dropdown a i {
            width: 16px;
            color: var(--text-light);
            font-size: 13px;
            text-align: center;
        }
        
        .user-dropdown hr {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 4px 8px;
        }
        
        .user-dropdown .logout-dropdown {
            color: #DC2626;
        }
        
        .user-dropdown .logout-dropdown i {
            color: #DC2626;
        }
        
        .logout-btn {
            color: var(--text-light);
            font-size: 15px;
            transition: color 0.3s ease;
            padding: 4px;
        }
        
        .logout-btn:hover {
            color: #DC2626;
        }
        
        .last-updated {
            font-size: 9px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 3px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1024px) {
            .global-search {
                min-width: 80px;
                max-width: 130px;
            }
            
            .branch-selector select {
                min-width: 70px;
                max-width: 100px;
            }
            
            .topbar-left h2 {
                font-size: 14px;
            }
        }
        
        @media (max-width: 768px) {
            .admin-topbar {
                padding: 4px 12px;
                min-height: 44px;
                flex-wrap: wrap;
                gap: 4px;
            }
            
            .topbar-toggle {
                display: block;
                font-size: 15px;
                padding: 3px 6px;
            }
            
            .topbar-left h2 {
                font-size: 13px;
            }
            
            .topbar-left h2 .page-icon {
                display: none;
            }
            
            .topbar-right {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 4px;
            }
            
            .global-search {
                order: 10;
                width: 100%;
                min-width: 100%;
                max-width: 100%;
            }
            
            .global-search input {
                font-size: 11px;
                padding: 4px 8px 4px 26px;
            }
            
            .global-search .search-shortcut {
                display: none;
            }
            
            .branch-selector {
                padding: 2px 6px 2px 2px;
            }
            
            .branch-selector select {
                font-size: 11px;
                min-width: 70px;
                max-width: 100px;
            }
            
            .branch-selector .branch-badge {
                display: none !important;
            }
            
            .live-datetime {
                font-size: 9px;
                padding: 2px 6px;
            }
            
            .live-datetime .date-separator {
                margin: 0 1px;
            }
            
            .user-profile .user-info {
                display: none;
            }
            
            .user-profile .profile-img-wrapper {
                width: 28px;
                height: 28px;
            }
            
            .user-profile img {
                width: 28px;
                height: 28px;
            }
            
            .user-profile .online-dot {
                width: 8px;
                height: 8px;
            }
            
            .notification-dropdown {
                width: 260px;
                right: -20px;
            }
            
            .last-updated {
                display: none;
            }
            
            .main-wrapper {
                margin-left: 0;
            }
            
            .main-content {
                padding: 12px 14px;
            }
        }
        
        @media (max-width: 480px) {
            .admin-topbar {
                padding: 3px 8px;
                min-height: 40px;
            }
            
            .topbar-left h2 {
                font-size: 12px;
            }
            
            .topbar-toggle {
                font-size: 14px;
                padding: 2px 5px;
            }
            
            .global-search input {
                font-size: 10px;
                padding: 3px 6px 3px 24px;
            }
            
            .global-search .search-icon {
                font-size: 9px;
                left: 6px;
            }
            
            .branch-selector select {
                font-size: 10px;
                min-width: 55px;
                max-width: 80px;
            }
            
            .branch-selector .branch-icon {
                font-size: 10px;
                padding: 2px 3px;
            }
            
            .live-datetime {
                font-size: 8px;
                padding: 1px 4px;
            }
            
            .live-datetime i {
                display: none;
            }
            
            .user-profile .profile-img-wrapper {
                width: 24px;
                height: 24px;
            }
            
            .user-profile img {
                width: 24px;
                height: 24px;
            }
            
            .user-profile .online-dot {
                width: 6px;
                height: 6px;
            }
            
            .dark-mode-toggle {
                font-size: 13px;
                padding: 2px 4px;
            }
            
            .notification-btn {
                font-size: 13px;
                padding: 2px 4px;
            }
            
            .notification-dropdown {
                width: 240px;
                right: -40px;
            }
            
            .main-content {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>