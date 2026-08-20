<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\modules\evening_stock\index.php
// WAKALA FINANCIAL SYSTEM - EVENING STOCK DASHBOARD
// FIXED: Changed 'created_at' to 'submitted_at'
// ================================================================

// ============================================================
// INCLUDE CONFIG BEFORE SESSION
// ============================================================
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK LOGIN
// ============================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$employee_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';
$profile_image = '../../assets/images/logo.PNG';
$is_admin = isAdmin();

// ============================================================
// GET DASHBOARD DATA
// ============================================================
$today = date('Y-m-d');

// --- Today's Stock ---
$stmt = $db->prepare("SELECT * FROM evening_stocks WHERE employee_id = ? AND stock_date = ?");
$stmt->execute([$employee_id, $today]);
$today_stock = $stmt->fetch();

// --- Statistics ---
// Total stocks this month
$stmt = $db->prepare("SELECT COUNT(*) as total, SUM(cumm_total) as total_amount 
                      FROM evening_stocks 
                      WHERE employee_id = ? AND MONTH(stock_date) = ? AND YEAR(stock_date) = ?");
$stmt->execute([$employee_id, date('m'), date('Y')]);
$stats = $stmt->fetch();

// --- Recent Stocks (Last 10) ---
// ===== FIX: Changed 'created_at' to 'submitted_at' =====
$stmt = $db->prepare("SELECT * FROM evening_stocks 
                      WHERE employee_id = ? 
                      ORDER BY submitted_at DESC LIMIT 10");
$stmt->execute([$employee_id]);
$recent_stocks = $stmt->fetchAll();

// --- Morning Reports for comparison ---
$stmt = $db->prepare("SELECT cumm_total FROM morning_reports 
                      WHERE employee_id = ? AND report_date = ?");
$stmt->execute([$employee_id, $today]);
$morning = $stmt->fetch();
$morning_total = $morning['cumm_total'] ?? 0;

$evening_total = $today_stock['cumm_total'] ?? 0;
$difference = $evening_total - $morning_total;

// --- Check if stock exists for today ---
$has_stock = ($today_stock !== false);

// ================================================================
// HTML STARTS HERE
// ================================================================
?>
<!DOCTYPE html>
<html lang="en" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evening Stock Dashboard - Wakala</title>
    
    <link rel="icon" href="../../assets/images/logo.PNG" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dark-mode.css">
    
    <style>
        /* ===== FULL STYLES ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
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
        }
        
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
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        /* ===== SIDEBAR ===== */
        .admin-sidebar {
            width: 250px;
            background: #8B0000;
            height: 100vh;
            padding: 0;
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            overflow-y: auto;
            color: #FFFFFF;
            z-index: 1000;
            transition: transform 0.3s ease;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.2) transparent;
        }
        .admin-sidebar::-webkit-scrollbar { width: 4px; }
        .admin-sidebar::-webkit-scrollbar-track { background: transparent; }
        .admin-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }
        
        .sidebar-brand {
            padding: 16px 20px 14px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 6px;
        }
        .sidebar-brand img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.25);
            background: white;
            padding: 3px;
        }
        .sidebar-brand h3 { font-size: 15px; font-weight: 700; color: #FFFFFF; margin-top: 8px; }
        .sidebar-brand small { font-size: 10px; color: rgba(255,255,255,0.6); display: block; margin-top: 1px; }
        
        .sidebar-menu {
            list-style: none;
            padding: 0 10px 20px;
            margin: 0;
        }
        .sidebar-menu li { margin-bottom: 1px; }
        .sidebar-menu .menu-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            padding: 12px 12px 4px;
            color: rgba(255,255,255,0.35);
            font-weight: 600;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 13px;
            font-weight: 500;
        }
        .sidebar-menu a i {
            width: 20px;
            font-size: 14px;
            margin-right: 12px;
            color: rgba(255,255,255,0.5);
            text-align: center;
        }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.12); color: #FFFFFF; }
        .sidebar-menu a:hover i { color: #FFFFFF; }
        .sidebar-menu a.active { background: rgba(255,255,255,0.18); color: #FFFFFF; }
        .sidebar-menu a.active i { color: #FFFFFF; }
        
        .sidebar-menu .logout-link {
            color: #FF6B6B !important;
            margin-top: 6px;
            border-top: 1px solid rgba(255,255,255,0.06);
            padding-top: 10px !important;
        }
        .sidebar-menu .logout-link:hover { background: rgba(255,0,0,0.15) !important; color: #FF4444 !important; }
        .sidebar-menu .logout-link i { color: #FF6B6B !important; }
        .sidebar-menu .logout-link:hover i { color: #FF4444 !important; }
        
        /* ===== MAIN WRAPPER ===== */
        .main-wrapper {
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* ===== TOPBAR ===== */
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
            min-height: 52px;
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .topbar-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 18px;
            color: var(--text-primary);
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
        }
        .topbar-toggle:hover { background: var(--bg-hover); }
        .topbar-left h2 { font-size: 16px; font-weight: 700; color: var(--text-primary); }
        .topbar-left h2 .page-icon { margin-right: 6px; color: #DC2626; font-size: 15px; }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .global-search { position: relative; }
        .global-search .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 12px;
        }
        .global-search input {
            width: 200px;
            padding: 6px 10px 6px 32px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
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
        
        .dark-mode-toggle {
            background: none;
            border: none;
            font-size: 17px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.3s ease;
        }
        .dark-mode-toggle:hover { background: var(--bg-hover); }
        
        .live-datetime {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 500;
            padding: 3px 10px;
            background: var(--bg-hover);
            border-radius: 6px;
            border: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .live-datetime i { color: #DC2626; font-size: 11px; }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 2px 8px 2px 2px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease;
            position: relative;
        }
        .user-profile:hover { background: var(--bg-hover); }
        .user-profile img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #DC2626;
            background: white;
            padding: 2px;
        }
        .user-profile .user-info { line-height: 1.2; }
        .user-profile .user-name { font-weight: 600; font-size: 12px; color: var(--text-primary); }
        .user-profile .user-role {
            font-size: 8px;
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
            font-size: 10px;
            transition: transform 0.3s ease;
        }
        .user-dropdown-btn.rotate { transform: rotate(180deg); }
        
        .user-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            background: var(--bg-card);
            border-radius: 10px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            border: 1px solid var(--border-color);
            min-width: 170px;
            padding: 4px 0;
            z-index: 1001;
        }
        .user-dropdown.show { display: block; }
        .user-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            transition: background 0.2s ease;
        }
        .user-dropdown a:hover { background: var(--bg-hover); }
        .user-dropdown a i { width: 16px; color: var(--text-light); font-size: 13px; }
        .user-dropdown hr { border: none; border-top: 1px solid var(--border-color); margin: 3px 10px; }
        .user-dropdown .logout-dropdown { color: #DC2626; }
        .user-dropdown .logout-dropdown i { color: #DC2626; }
        
        .logout-btn {
            color: var(--text-light);
            font-size: 16px;
            transition: color 0.3s ease;
            padding: 4px;
        }
        .logout-btn:hover { color: #DC2626; }
        
        /* ===== MAIN CONTENT ===== */
        .main-content {
            padding: 20px 24px;
            flex: 1;
        }
        
        /* ===== PAGE HEADER ===== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .page-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .page-header h1 small {
            font-size: 14px;
            font-weight: 400;
            color: var(--text-secondary);
            display: block;
            margin-top: 2px;
        }
        .page-header .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* ===== BUTTONS ===== */
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary {
            background: #DC2626;
            color: white;
        }
        .btn-primary:hover {
            background: #8B0000;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220,38,38,0.3);
        }
        .btn-secondary {
            background: var(--bg-hover);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover { background: var(--border-color); }
        .btn-success {
            background: #10B981;
            color: white;
        }
        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        .btn-outline {
            background: transparent;
            color: #DC2626;
            border: 2px solid #DC2626;
        }
        .btn-outline:hover {
            background: #DC2626;
            color: white;
        }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn-lg { padding: 12px 28px; font-size: 15px; }
        
        /* ===== ALERTS ===== */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .alert-success { background: #D1FAE5; border: 1px solid #A7F3D0; color: #065F46; }
        .alert-danger { background: #FEE2E2; border: 1px solid #FECACA; color: #991B1B; }
        .alert-warning { background: #FEF3C7; border: 1px solid #FDE68A; color: #92400E; }
        .alert-info { background: #DBEAFE; border: 1px solid #BFDBFE; color: #1E40AF; }
        
        /* ===== STATS CARDS ===== */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border-left: 4px solid #DC2626;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .stat-card .stat-icon {
            float: right;
            font-size: 28px;
            opacity: 0.1;
        }
        .stat-card .stat-label {
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-card .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 4px 0 2px;
        }
        .stat-card .stat-sub {
            font-size: 12px;
            color: var(--text-light);
        }
        .stat-card.positive .stat-value { color: #10B981; }
        .stat-card.negative .stat-value { color: #DC2626; }
        .stat-card.border-green { border-left-color: #10B981; }
        .stat-card.border-orange { border-left-color: #F59E0B; }
        .stat-card.border-blue { border-left-color: #3B82F6; }
        .stat-card.border-red { border-left-color: #DC2626; }
        
        /* ===== TODAY'S STOCK CARD ===== */
        .today-card {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            margin-bottom: 20px;
            border: 2px solid var(--border-color);
        }
        .today-card .today-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }
        .today-card .today-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .today-card .today-header .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .today-card .today-header .badge.success { background: #D1FAE5; color: #065F46; }
        .today-card .today-header .badge.warning { background: #FEF3C7; color: #92400E; }
        .today-card .today-header .badge.danger { background: #FEE2E2; color: #991B1B; }
        
        .today-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }
        .today-details .detail-item {
            padding: 8px 12px;
            background: var(--bg-input);
            border-radius: 6px;
        }
        .today-details .detail-item .label {
            font-size: 11px;
            color: var(--text-secondary);
        }
        .today-details .detail-item .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .today-details .detail-item .value.positive { color: #10B981; }
        .today-details .detail-item .value.negative { color: #DC2626; }
        
        /* ===== TABLE ===== */
        .table-container {
            background: var(--bg-card);
            border-radius: 10px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            overflow-x: auto;
        }
        .table-container .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-container .table-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        table th {
            text-align: left;
            padding: 10px 12px;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 2px solid var(--border-color);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        table tr:hover { background: var(--bg-hover); }
        table .text-center { text-align: center; }
        table .text-right { text-align: right; }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            .admin-sidebar.open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .topbar-toggle { display: block; }
            .admin-topbar { padding: 6px 14px; min-height: 48px; }
            .topbar-left h2 { font-size: 14px; }
            .topbar-right { gap: 6px; }
            .global-search { order: 10; width: 100%; }
            .global-search input { width: 100%; font-size: 12px; padding: 5px 10px 5px 30px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .main-content { padding: 14px 16px; }
            .page-header h1 { font-size: 18px; }
            .page-header h1 small { font-size: 12px; }
            .live-datetime { font-size: 10px; padding: 2px 8px; }
            .user-profile .user-info { display: none; }
            .table-container { padding: 14px 16px; }
            .today-card { padding: 16px 18px; }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
            .sidebar-overlay.active { display: block; }
        }
        
        @media (max-width: 480px) {
            .admin-topbar { padding: 4px 10px; min-height: 44px; }
            .topbar-left h2 { font-size: 13px; }
            .topbar-left h2 .page-icon { display: none; }
            .stats-row { grid-template-columns: 1fr; }
            .today-details { grid-template-columns: 1fr 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .header-actions { width: 100%; }
            .page-header .header-actions .btn { flex: 1; justify-content: center; }
            .table-container { padding: 10px 12px; }
            table { font-size: 12px; }
            table th, table td { padding: 6px 8px; }
            .today-card { padding: 12px 14px; }
        }
    </style>
</head>
<body>
    
    <!-- ===== SIDEBAR ===== -->
    <nav class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <img src="<?php echo $profile_image; ?>" alt="Wakala" 
                 onerror="this.src='../../assets/images/default-avatar.png'">
            <h3>Wakala</h3>
            <small>Financial System</small>
        </div>
        <ul class="sidebar-menu">
            <li class="menu-label">Main</li>
            <li><a href="../dashboard/admin.php"><i class="fas fa-home"></i> Dashboard</a></li>
            
            <li class="menu-label">Daily Operations</li>
            <li><a href="../morning_report/index.php"><i class="fas fa-sun"></i> Morning Report</a></li>
            <li><a href="#" class="active"><i class="fas fa-moon"></i> Evening Stock</a></li>
            <li><a href="../daily_report/index.php"><i class="fas fa-file-alt"></i> Daily Report</a></li>
            
            <li class="menu-label">Financial</li>
            <li><a href="../commissions/index.php"><i class="fas fa-hand-holding-usd"></i> Commissions</a></li>
            <li><a href="../expenses/index.php"><i class="fas fa-receipt"></i> Expenses</a></li>
            <li><a href="../store_cash_out/index.php"><i class="fas fa-money-bill-wave"></i> Cash Out</a></li>
            <li><a href="../capital_management/index.php"><i class="fas fa-building"></i> Capital</a></li>
            <li><a href="../salaries/index.php"><i class="fas fa-wallet"></i> Salaries</a></li>
            
            <li class="menu-label">Reports</li>
            <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i> All Reports</a></li>
            
            <?php if ($is_admin): ?>
            <li class="menu-label">Management</li>
            <li><a href="../employees/index.php"><i class="fas fa-users"></i> Employees</a></li>
            <li><a href="../activity_logs/index.php"><i class="fas fa-history"></i> Activity Logs</a></li>
            <li><a href="../settings/index.php"><i class="fas fa-cog"></i> Settings</a></li>
            <?php endif; ?>
            
            <li class="menu-label">Account</li>
            <li><a href="../profile/index.php"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="../../logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- ===== MAIN WRAPPER ===== -->
    <div class="main-wrapper">
        
        <!-- ===== TOPBAR ===== -->
        <header class="admin-topbar">
            <div class="topbar-left">
                <button class="topbar-toggle" id="topbarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>
                    <i class="fas fa-moon page-icon"></i>
                    Evening Stock
                </h2>
            </div>
            
            <div class="topbar-right">
                <div class="global-search">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="globalSearch" placeholder="Search..." autocomplete="off">
                </div>
                
                <button class="dark-mode-toggle" id="darkModeToggle">
                    <i class="fas fa-moon" id="darkModeIcon"></i>
                </button>
                
                <div class="live-datetime">
                    <i class="fas fa-clock"></i>
                    <span id="liveTime">--:--:--</span>
                    <span class="date-separator">|</span>
                    <span id="liveDate">--/--/----</span>
                </div>
                
                <div class="user-profile">
                    <img src="<?php echo $profile_image; ?>" alt="Profile">
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                        <span class="user-role"><?php echo strtoupper($role); ?></span>
                    </div>
                    <button class="user-dropdown-btn" id="userDropdownBtn">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    
                    <div class="user-dropdown" id="userDropdown">
                        <a href="../profile/index.php"><i class="fas fa-user"></i> My Profile</a>
                        <a href="../profile/change_password.php"><i class="fas fa-key"></i> Change Password</a>
                        <hr>
                        <a href="../../logout.php" class="logout-dropdown"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
                
                <a href="../../logout.php" class="logout-btn" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </header>
        
        <!-- ===== MAIN CONTENT ===== -->
        <main class="main-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas fa-moon" style="color:#DC2626;"></i> Evening Stock
                        <small>Manage evening float and cash balances</small>
                    </h1>
                </div>
                <div class="header-actions">
                    <a href="add.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Evening Stock
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </a>
                </div>
            </div>
            
            <!-- ===== STATS ROW ===== -->
            <div class="stats-row">
                <div class="stat-card border-red">
                    <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-label">Today's Stock</div>
                    <div class="stat-value"><?php echo $has_stock ? formatCurrency($evening_total) : 'No Data'; ?></div>
                    <div class="stat-sub"><?php echo $has_stock ? 'Submitted: ' . date('h:i A', strtotime($today_stock['submitted_at'])) : 'Not submitted yet'; ?></div>
                </div>
                
                <div class="stat-card <?php echo $has_stock ? 'border-green' : 'border-orange'; ?>">
                    <div class="stat-icon"><i class="fas fa-arrows-alt-h"></i></div>
                    <div class="stat-label">Float Difference</div>
                    <div class="stat-value <?php echo $difference > 0 ? 'positive' : ($difference < 0 ? 'negative' : ''); ?>">
                        <?php echo $has_stock ? ($difference >= 0 ? '+' : '') . formatCurrency($difference) : 'N/A'; ?>
                    </div>
                    <div class="stat-sub">Evening - Morning</div>
                </div>
                
                <div class="stat-card border-blue">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-label">Total This Month</div>
                    <div class="stat-value"><?php echo formatCurrency($stats['total_amount'] ?? 0); ?></div>
                    <div class="stat-sub"><?php echo $stats['total'] ?? 0; ?> entries</div>
                </div>
                
                <div class="stat-card border-orange">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-label">Last Submission</div>
                    <div class="stat-value" style="font-size:16px;">
                        <?php 
                        $last = $recent_stocks[0] ?? null;
                        echo $last ? date('d M Y', strtotime($last['stock_date'])) : 'No records';
                        ?>
                    </div>
                    <div class="stat-sub"><?php echo $last ? 'Amount: ' . formatCurrency($last['cumm_total']) : ''; ?></div>
                </div>
            </div>
            
            <!-- ===== TODAY'S STOCK DETAILS ===== -->
            <?php if ($has_stock): ?>
            <div class="today-card">
                <div class="today-header">
                    <h3>
                        <i class="fas fa-check-circle" style="color:#10B981;"></i> 
                        Today's Evening Stock - <?php echo date('d M Y'); ?>
                    </h3>
                    <span class="badge success">
                        <i class="fas fa-check"></i> Submitted
                    </span>
                </div>
                <div class="today-details">
                    <div class="detail-item">
                        <div class="label">Stock Number</div>
                        <div class="value"><?php echo $today_stock['stock_number']; ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Total Amount</div>
                        <div class="value"><?php echo formatCurrency($today_stock['cumm_total']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Cash Balance</div>
                        <div class="value"><?php echo formatCurrency($today_stock['cash_balance']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Submitted At</div>
                        <div class="value" style="font-size:14px;"><?php echo date('h:i A', strtotime($today_stock['submitted_at'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Morning Total</div>
                        <div class="value"><?php echo formatCurrency($morning_total); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Difference</div>
                        <div class="value <?php echo $difference > 0 ? 'positive' : ($difference < 0 ? 'negative' : ''); ?>">
                            <?php echo ($difference >= 0 ? '+' : '') . formatCurrency($difference); ?>
                        </div>
                    </div>
                </div>
                <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="view.php?id=<?php echo $today_stock['id']; ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                    <a href="edit.php?id=<?php echo $today_stock['id']; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><strong>No evening stock submitted today.</strong> Please add evening stock for <?php echo date('d M Y'); ?>.</span>
                <a href="add.php" class="btn btn-primary btn-sm" style="margin-left:auto;">
                    <i class="fas fa-plus"></i> Add Now
                </a>
            </div>
            <?php endif; ?>
            
            <!-- ===== RECENT STOCKS TABLE ===== -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-history" style="color:#DC2626;"></i> Recent Evening Stocks</h3>
                    <?php if (count($recent_stocks) > 0): ?>
                    <a href="index.php?view=all" class="btn btn-secondary btn-sm">View All</a>
                    <?php endif; ?>
                </div>
                
                <?php if (count($recent_stocks) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Stock Number</th>
                            <th>Date</th>
                            <th class="text-right">Cash</th>
                            <th class="text-right">Total</th>
                            <th>Submitted</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_stocks as $index => $stock): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <span style="font-weight:600;"><?php echo $stock['stock_number']; ?></span>
                                <?php if ($stock['stock_date'] == $today): ?>
                                    <span class="badge" style="background:#D1FAE5; color:#065F46; font-size:9px; padding:1px 8px; border-radius:10px;">Today</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($stock['stock_date'])); ?></td>
                            <td class="text-right"><?php echo formatCurrency($stock['cash_balance']); ?></td>
                            <td class="text-right" style="font-weight:600;"><?php echo formatCurrency($stock['cumm_total']); ?></td>
                            <td><?php echo date('h:i A', strtotime($stock['submitted_at'])); ?></td>
                            <td class="text-center">
                                <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                                    <a href="view.php?id=<?php echo $stock['id']; ?>" class="btn btn-secondary btn-sm" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $stock['id']; ?>" class="btn btn-primary btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="padding:40px; text-align:center; color:var(--text-light);">
                    <i class="fas fa-inbox" style="font-size:40px; display:block; margin-bottom:10px; opacity:0.3;"></i>
                    <p>No evening stock records found.</p>
                    <a href="add.php" class="btn btn-primary" style="margin-top:10px;">
                        <i class="fas fa-plus"></i> Add First Evening Stock
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
        </main>
        
    </div>
    
    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // ===== SIDEBAR TOGGLE =====
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
        
        // ===== DARK MODE =====
        const darkToggle = document.getElementById('darkModeToggle');
        const darkIcon = document.getElementById('darkModeIcon');
        const htmlRoot = document.documentElement;
        
        const savedDark = localStorage.getItem('darkMode') === 'true';
        if (savedDark) {
            htmlRoot.classList.add('dark-mode');
            darkIcon.className = 'fas fa-sun';
        }
        
        if (darkToggle) {
            darkToggle.addEventListener('click', function() {
                htmlRoot.classList.toggle('dark-mode');
                const isDark = htmlRoot.classList.contains('dark-mode');
                darkIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                localStorage.setItem('darkMode', isDark);
            });
        }
        
        // ===== USER DROPDOWN =====
        const userBtn = document.getElementById('userDropdownBtn');
        const userDropdown = document.getElementById('userDropdown');
        
        if (userBtn && userDropdown) {
            userBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                userDropdown.classList.toggle('show');
                this.classList.toggle('rotate');
            });
            document.addEventListener('click', function(e) {
                if (!userDropdown.contains(e.target) && !userBtn.contains(e.target)) {
                    userDropdown.classList.remove('show');
                    userBtn.classList.remove('rotate');
                }
            });
        }
        
        // ===== LIVE DATE/TIME =====
        function updateLiveDateTime() {
            const now = new Date();
            const timeEl = document.getElementById('liveTime');
            const dateEl = document.getElementById('liveDate');
            if (timeEl) {
                timeEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
            if (dateEl) {
                dateEl.textContent = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            }
        }
        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000);
        
        // ===== SEARCH =====
        const searchInput = document.getElementById('globalSearch');
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    const query = this.value.trim();
                    if (query.length > 0) {
                        window.location.href = 'index.php?search=' + encodeURIComponent(query);
                    }
                }
            });
        }
        
        console.log('%c EVENING STOCK DASHBOARD v2.0 ',
            'background:#8B0000; color:white; padding:6px 12px; border-radius:4px; font-size:13px; font-weight:bold;');
    </script>
</body>
</html>