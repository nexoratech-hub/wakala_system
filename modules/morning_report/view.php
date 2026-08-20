<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\modules\morning_report\view.php
// WAKALA FINANCIAL SYSTEM - VIEW MORNING REPORT
// WITH EXPORT PDF BUTTON
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
// GET REPORT ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET REPORT DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM morning_reports WHERE id = ?");
$stmt->execute([$id]);
$report = $stmt->fetch();

if (!$report) {
    header('Location: index.php');
    exit();
}

// Check if user has permission to view this report
if (!$is_admin && $report['employee_id'] != $employee_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDER NAMES
// ============================================================
$stmt = $db->prepare("SELECT provider_code, provider_name, color_code FROM providers WHERE is_active = 1");
$stmt->execute();
$providers_list = $stmt->fetchAll();

// Create provider name map
$provider_names = [];
foreach ($providers_list as $p) {
    $provider_names[$p['provider_code']] = [
        'name' => $p['provider_name'],
        'color' => $p['color_code'] ?? '#0B5ED7'
    ];
}

// ============================================================
// DECODE PROVIDER DATA
// ============================================================
$provider_data = json_decode($report['provider_data'], true) ?? [];

// ============================================================
// GET EMPLOYEE NAME
// ============================================================
$stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
$stmt->execute([$report['employee_id']]);
$employee = $stmt->fetch();
$employee_name = $employee['full_name'] ?? 'Unknown';

// ============================================================
// CALCULATE PROVIDER TOTAL
// ============================================================
$provider_total = 0;
foreach ($provider_data as $amount) {
    $provider_total += $amount;
}

// ================================================================
// HTML STARTS HERE
// ================================================================
?>
<!DOCTYPE html>
<html lang="en" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Morning Report - Wakala</title>
    
    <link rel="icon" href="../../assets/images/logo.PNG" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dark-mode.css">
    
    <!-- jsPDF library for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    
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
        .btn-danger {
            background: #DC2626;
            color: white;
        }
        .btn-danger:hover {
            background: #8B0000;
            transform: translateY(-1px);
        }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn-lg { padding: 12px 28px; font-size: 15px; }
        
        /* PDF Button Special */
        .btn-pdf {
            background: #DC2626;
            color: white;
        }
        .btn-pdf:hover {
            background: #8B0000;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220,38,38,0.3);
        }
        
        /* ===== VIEW CARD ===== */
        .view-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 24px 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            max-width: 820px;
        }
        
        .view-card .view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .view-card .view-header .report-number {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .view-card .view-header .report-number small {
            font-size: 14px;
            font-weight: 400;
            color: var(--text-secondary);
            display: block;
            margin-top: 2px;
        }
        
        .view-card .view-header .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.success { background: #D1FAE5; color: #065F46; }
        .status-badge.warning { background: #FEF3C7; color: #92400E; }
        .status-badge.danger { background: #FEE2E2; color: #991B1B; }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .info-item {
            padding: 12px 16px;
            background: var(--bg-input);
            border-radius: 8px;
        }
        
        .info-item .info-label {
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-item .info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-top: 2px;
        }
        
        /* Provider Table */
        .provider-table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }
        
        .provider-table th {
            text-align: left;
            padding: 10px 12px;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 2px solid var(--border-color);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .provider-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .provider-table tr:hover { background: var(--bg-hover); }
        .provider-table .text-right { text-align: right; }
        .provider-table .text-center { text-align: center; }
        
        .provider-table .provider-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        /* Total Row */
        .total-row {
            background: #8B0000;
            color: white;
            border-radius: 8px;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
        }
        
        .total-row .total-label {
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.8;
        }
        
        .total-row .total-value {
            font-size: 22px;
            font-weight: 700;
        }
        
        /* Notes */
        .notes-section {
            margin-top: 20px;
            padding: 16px 18px;
            background: var(--bg-input);
            border-radius: 8px;
            border-left: 4px solid #DC2626;
        }
        
        .notes-section .notes-label {
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .notes-section .notes-content {
            font-size: 14px;
            color: var(--text-primary);
            margin-top: 4px;
        }
        
        /* ===== PDF LOADING OVERLAY ===== */
        .pdf-loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 20px;
        }
        
        .pdf-loading.active {
            display: flex;
        }
        
        .pdf-loading .spinner-box {
            background: white;
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        
        .pdf-loading .spinner-box .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #F3F4F6;
            border-top: 4px solid #DC2626;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        
        .pdf-loading .spinner-box p {
            font-size: 16px;
            color: #1F2937;
            font-weight: 600;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
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
            .main-content { padding: 14px 16px; }
            .view-card { padding: 16px 18px; }
            .page-header h1 { font-size: 18px; }
            .page-header h1 small { font-size: 12px; }
            .live-datetime { font-size: 10px; padding: 2px 8px; }
            .user-profile .user-info { display: none; }
            .info-grid { grid-template-columns: 1fr 1fr; }
            
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
            .info-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .header-actions { width: 100%; }
            .page-header .header-actions .btn { flex: 1; justify-content: center; }
            .view-card { padding: 12px 14px; }
            .total-row { flex-direction: column; text-align: center; gap: 6px; padding: 12px 14px; }
            .total-row .total-value { font-size: 18px; }
            .provider-table { font-size: 12px; }
            .provider-table th, .provider-table td { padding: 6px 8px; }
            .view-card .view-header .report-number { font-size: 16px; }
        }
    </style>
</head>
<body>
    
    <!-- ===== PDF LOADING OVERLAY ===== -->
    <div class="pdf-loading" id="pdfLoading">
        <div class="spinner-box">
            <div class="spinner"></div>
            <p><i class="fas fa-file-pdf" style="color:#DC2626;"></i> Generating PDF...</p>
            <small style="color:#6B7280;">Please wait</small>
        </div>
    </div>
    
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
            <li><a href="index.php" class="active"><i class="fas fa-sun"></i> Morning Report</a></li>
            <li><a href="../evening_stock/index.php"><i class="fas fa-moon"></i> Evening Stock</a></li>
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
                    <i class="fas fa-sun page-icon"></i>
                    Morning Report Details
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
                        <i class="fas fa-file-alt" style="color:#DC2626;"></i> Morning Report Details
                        <small>View complete morning report information</small>
                    </h1>
                </div>
                <div class="header-actions">
                    <!-- ===== PDF EXPORT BUTTON ===== -->
                    <button onclick="exportPDF()" class="btn btn-pdf">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <a href="edit.php?id=<?php echo $report['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
            
            <!-- ===== VIEW CARD ===== -->
            <div class="view-card" id="reportContent">
                
                <!-- Header -->
                <div class="view-header">
                    <div class="report-number">
                        <?php echo htmlspecialchars($report['report_number']); ?>
                        <small>Morning Report for <?php echo date('d M Y', strtotime($report['report_date'])); ?></small>
                    </div>
                    <span class="status-badge success">
                        <i class="fas fa-check-circle"></i> Submitted
                    </span>
                </div>
                
                <!-- Info Grid -->
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calendar-day"></i> Date</div>
                        <div class="info-value"><?php echo date('l, d M Y', strtotime($report['report_date'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-user"></i> Employee</div>
                        <div class="info-value"><?php echo htmlspecialchars($employee_name); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-building"></i> Branch</div>
                        <div class="info-value"><?php echo htmlspecialchars($report['branch'] ?? 'Main'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-clock"></i> Submitted At</div>
                        <div class="info-value"><?php echo date('h:i A', strtotime($report['submitted_at'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-money-bill-wave"></i> Cash Balance</div>
                        <div class="info-value"><?php echo formatCurrency($report['cash_balance']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label"><i class="fas fa-calculator"></i> CUMM. TOTAL</div>
                        <div class="info-value" style="color:#DC2626;"><?php echo formatCurrency($report['cumm_total']); ?></div>
                    </div>
                </div>
                
                <!-- Provider Table -->
                <h4 style="margin-bottom:12px; color:var(--text-primary);">
                    <i class="fas fa-university" style="color:#DC2626;"></i> Provider Balances (OFB)
                </h4>
                
                <table class="provider-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Provider</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($provider_data as $code => $amount):
                            $name = $provider_names[$code]['name'] ?? $code;
                            $color = $provider_names[$code]['color'] ?? '#0B5ED7';
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td>
                                    <span class="provider-dot" style="background:<?php echo $color; ?>;"></span>
                                    <?php echo htmlspecialchars($name); ?>
                                </td>
                                <td class="text-right"><?php echo formatCurrency($amount); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($provider_data)): ?>
                            <tr>
                                <td colspan="3" style="text-align:center; color:var(--text-light); padding:20px;">
                                    <i class="fas fa-inbox"></i> No provider data available
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Total Row -->
                <div class="total-row">
                    <span class="total-label">
                        <i class="fas fa-calculator"></i> CUMM. TOTAL
                    </span>
                    <span class="total-value"><?php echo formatCurrency($report['cumm_total']); ?></span>
                </div>
                
                <!-- Notes -->
                <?php if (!empty($report['notes'])): ?>
                <div class="notes-section">
                    <div class="notes-label"><i class="fas fa-pencil-alt"></i> Notes</div>
                    <div class="notes-content"><?php echo nl2br(htmlspecialchars($report['notes'])); ?></div>
                </div>
                <?php endif; ?>
                
                <!-- Footer for PDF -->
                <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border-color); font-size:11px; color:var(--text-light); text-align:center;">
                    <p>Generated on <?php echo date('d M Y h:i A'); ?> | Wakala Financial Management System</p>
                </div>
                
            </div>
            
        </main>
        
    </div>
    
    <!-- ===== JAVASCRIPT ===== -->
    <script>
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
        
        // ============================================================
        // USER DROPDOWN
        // ============================================================
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
        
        // ============================================================
        // LIVE DATE/TIME
        // ============================================================
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
        
        // ============================================================
        // SEARCH
        // ============================================================
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
        
        // ============================================================
        // EXPORT PDF FUNCTION
        // ============================================================
        function exportPDF() {
            // Show loading overlay
            document.getElementById('pdfLoading').classList.add('active');
            
            // Get the content to export
            const content = document.getElementById('reportContent');
            
            // Use html2canvas to capture the content
            html2canvas(content, {
                scale: 2,
                backgroundColor: '#FFFFFF',
                logging: false,
                useCORS: true,
                allowTaint: true
            }).then(function(canvas) {
                const imgData = canvas.toDataURL('image/png');
                
                // Create PDF using jsPDF
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                
                // Get page dimensions
                const pdfWidth = pdf.internal.pageSize.getWidth();
                const pdfHeight = pdf.internal.pageSize.getHeight();
                
                // Calculate image dimensions to fit page
                const imgWidth = pdfWidth - 20; // 10mm margin on each side
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                
                // Add image to PDF
                pdf.addImage(imgData, 'PNG', 10, 10, imgWidth, imgHeight);
                
                // Add footer
                pdf.setFontSize(8);
                pdf.setTextColor(150);
                pdf.text('Generated by Wakala Financial Management System', pdfWidth / 2, pdfHeight - 10, { align: 'center' });
                
                // Save PDF
                pdf.save('Morning_Report_' + '<?php echo $report['report_number']; ?>' + '.pdf');
                
                // Hide loading overlay
                document.getElementById('pdfLoading').classList.remove('active');
                
            }).catch(function(error) {
                console.error('PDF Export Error:', error);
                document.getElementById('pdfLoading').classList.remove('active');
                alert('Failed to generate PDF. Please try again.');
            });
        }
        
        // ============================================================
        // PRINT
        // ============================================================
        function printReport() {
            window.print();
        }
        
        console.log('%c MORNING REPORT - VIEW v2.0 ',
            'background:#8B0000; color:white; padding:6px 12px; border-radius:4px; font-size:13px; font-weight:bold;');
        console.log('%c 📄 Report: <?php echo $report['report_number']; ?> ',
            'color:#6B7280; font-size:12px;');
        console.log('%c 📄 PDF Export: Click the PDF button ',
            'color:#6B7280; font-size:12px;');
    </script>
</body>
</html>