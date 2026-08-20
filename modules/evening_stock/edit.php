<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\modules\evening_stock\edit.php
// WAKALA FINANCIAL SYSTEM - EDIT EVENING STOCK
// ================================================================

// ============================================================
// ENABLE ERROR REPORTING
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
// GET STOCK ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET STOCK DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM evening_stocks WHERE id = ?");
$stmt->execute([$id]);
$stock = $stmt->fetch();

if (!$stock) {
    header('Location: index.php');
    exit();
}

// Check if user has permission to edit this stock
if (!$is_admin && $stock['employee_id'] != $employee_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET ACTIVE PROVIDERS WITH ICONS
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order");
$stmt->execute();
$providers = $stmt->fetchAll();

// ============================================================
// DECODE PROVIDER DATA
// ============================================================
$provider_data = json_decode($stock['provider_data'], true) ?? [];

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $provider_data_new = [];
    
    // Get cash balance (remove commas)
    $cash_balance_raw = $_POST['cash_balance'] ?? '0';
    $cash_balance = floatval(str_replace(',', '', $cash_balance_raw));
    $total = $cash_balance;
    
    // Collect provider data (remove commas)
    foreach ($providers as $provider) {
        $code = $provider['provider_code'];
        $amount_raw = $_POST['provider_' . $code] ?? '0';
        $amount = floatval(str_replace(',', '', $amount_raw));
        $provider_data_new[$code] = $amount;
        $total += $amount;
    }
    
    // Validate - at least one amount > 0
    $has_value = false;
    foreach ($provider_data_new as $amount) {
        if ($amount > 0) {
            $has_value = true;
            break;
        }
    }
    
    if ($cash_balance <= 0 && !$has_value) {
        $error = 'Please enter at least one value (cash or provider balance)';
    } else {
        try {
            // Convert provider data to JSON
            $provider_json = json_encode($provider_data_new);
            
            // Update database
            $stmt = $db->prepare("
                UPDATE evening_stocks SET
                    provider_data = :provider_data,
                    cash_balance = :cash_balance,
                    cumm_total = :cumm_total,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $notes = $_POST['notes'] ?? '';
            
            $result = $stmt->execute([
                ':provider_data' => $provider_json,
                ':cash_balance' => $cash_balance,
                ':cumm_total' => $total,
                ':notes' => $notes,
                ':id' => $id
            ]);
            
            if ($result) {
                // Log activity
                logActivity($employee_id, 'Edit Evening Stock', 'Evening Stock', $id, '', 'Evening stock updated');
                
                $success = 'Evening Stock updated successfully!';
                
                // Redirect after 2 seconds
                echo '<meta http-equiv="refresh" content="2;url=view.php?id=' . $id . '">';
            } else {
                $error = 'Failed to update data. Please check your input.';
            }
            
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
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
    <title>Edit Evening Stock - Wakala</title>
    
    <link rel="icon" href="../../assets/images/logo.PNG" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dark-mode.css">
    
    <style>
        /* ===== COMPLETE STYLES ===== */
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
        
        /* ===== FORM ===== */
        .form-container {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 24px 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            max-width: 820px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .form-group label .required { color: #DC2626; margin-left: 2px; }
        .form-group .form-text {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 2px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            background: var(--bg-input);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: #DC2626;
            box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
        }
        .form-control::placeholder { color: var(--text-light); }
        textarea.form-control { min-height: 80px; resize: vertical; }
        
        .provider-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin: 12px 0 16px;
        }
        
        .provider-item {
            background: var(--bg-input);
            border-radius: 8px;
            padding: 12px 14px;
            border: 1.5px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .provider-item:hover { border-color: #DC2626; }
        .provider-item .provider-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .provider-item .provider-label .color-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .provider-item .form-control {
            padding: 6px 10px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .total-box {
            background: #8B0000;
            color: white;
            border-radius: 10px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
        }
        .total-box .total-label {
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.8;
        }
        .total-box .total-value { font-size: 24px; font-weight: 700; }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
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
            .provider-grid { grid-template-columns: 1fr 1fr; }
            .main-content { padding: 14px 16px; }
            .form-container { padding: 16px 18px; }
            .page-header h1 { font-size: 18px; }
            .page-header h1 small { font-size: 12px; }
            .live-datetime { font-size: 10px; padding: 2px 8px; }
            .user-profile .user-info { display: none; }
            
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
            .provider-grid { grid-template-columns: 1fr; }
            .form-container { padding: 12px 14px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header .header-actions { width: 100%; }
            .page-header .header-actions .btn { flex: 1; justify-content: center; }
            .total-box { flex-direction: column; text-align: center; gap: 8px; padding: 14px 16px; }
            .total-box .total-value { font-size: 20px; }
            .form-actions { flex-direction: column; }
            .form-actions .btn { width: 100%; justify-content: center; }
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
            <li><a href="index.php" class="active"><i class="fas fa-moon"></i> Evening Stock</a></li>
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
                    <i class="fas fa-edit page-icon"></i>
                    Edit Evening Stock
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
                        <i class="fas fa-edit" style="color:#DC2626;"></i> Edit Evening Stock
                        <small>Update evening stock information for <?php echo date('d M Y', strtotime($stock['stock_date'])); ?></small>
                    </h1>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
            
            <!-- Alerts -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- ===== FORM ===== -->
            <div class="form-container">
                <form method="POST" action="" id="eveningStockForm" autocomplete="off">
                    
                    <!-- Info Box -->
                    <div style="background:var(--bg-hover); border-radius:8px; padding:12px 16px; margin-bottom:16px; border-left:4px solid #DC2626;">
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; font-size:13px;">
                            <div>
                                <span style="color:var(--text-secondary);">Stock Number</span><br>
                                <strong style="color:var(--text-primary);"><?php echo htmlspecialchars($stock['stock_number']); ?></strong>
                            </div>
                            <div>
                                <span style="color:var(--text-secondary);">Date</span><br>
                                <strong style="color:var(--text-primary);"><?php echo date('d M Y', strtotime($stock['stock_date'])); ?></strong>
                            </div>
                            <div>
                                <span style="color:var(--text-secondary);">Submitted</span><br>
                                <strong style="color:var(--text-primary);"><?php echo date('h:i A', strtotime($stock['submitted_at'])); ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Provider Fields -->
                    <div class="form-group">
                        <label>Provider Balances <span class="required">*</span></label>
                        <div class="form-text">Enter the closing float balance (CFB) for each provider</div>
                        
                        <div class="provider-grid">
                            <?php foreach ($providers as $provider): 
                                $code = $provider['provider_code'];
                                $value = $provider_data[$code] ?? 0;
                            ?>
                                <div class="provider-item">
                                    <div class="provider-label">
                                        <span class="color-dot" style="background:<?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;"></span>
                                        <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>" 
                                           style="color:<?php echo $provider['color_code'] ?? '#0B5ED7'; ?>; font-size:14px;"></i>
                                        <?php echo htmlspecialchars($provider['provider_name']); ?>
                                    </div>
                                    <input type="text" 
                                           name="provider_<?php echo $code; ?>" 
                                           id="provider_<?php echo $code; ?>"
                                           class="form-control provider-input amount-input"
                                           placeholder="0"
                                           value="<?php echo number_format($value, 0, '.', ','); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Cash Balance -->
                    <div class="form-group">
                        <label for="cash_balance">Cash Balance <span class="required">*</span></label>
                        <input type="text" 
                               name="cash_balance" 
                               id="cash_balance"
                               class="form-control amount-input"
                               placeholder="Enter cash balance"
                               value="<?php echo number_format($stock['cash_balance'], 0, '.', ','); ?>"
                               required>
                        <div class="form-text">Physical cash available in the till</div>
                    </div>
                    
                    <!-- Total Box - Auto Calculated -->
                    <div class="total-box">
                        <span class="total-label">
                            <i class="fas fa-calculator"></i> CUMM. TOTAL
                        </span>
                        <span class="total-value" id="totalDisplay"><?php echo formatCurrency($stock['cumm_total']); ?></span>
                    </div>
                    
                    <!-- Notes -->
                    <div class="form-group" style="margin-top:16px;">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" class="form-control" placeholder="Additional notes (optional)"><?php echo htmlspecialchars($stock['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                            <i class="fas fa-save"></i> Update Evening Stock
                        </button>
                        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                    
                </form>
            </div>
            
        </main>
        
    </div>
    
    <!-- ===== JAVASCRIPT ===== -->
    <script src="../../assets/js/number-format.js"></script>
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
        // AUTO CALCULATE CUMM. TOTAL (with commas)
        // ============================================================
        function calculateTotal() {
            const providerInputs = document.querySelectorAll('.provider-input');
            const cashBalance = document.getElementById('cash_balance');
            const totalDisplay = document.getElementById('totalDisplay');
            
            let total = 0;
            
            providerInputs.forEach(input => {
                let value = getRawNumberValue(input);
                total += value;
            });
            
            let cash = getRawNumberValue(cashBalance);
            total += cash;
            
            // Update display with commas
            updateTotalDisplay(total, 'totalDisplay');
        }
        
        // ============================================================
        // FORM VALIDATION
        // ============================================================
        document.getElementById('eveningStockForm').addEventListener('submit', function(e) {
            const providerInputs = document.querySelectorAll('.provider-input');
            const cashBalance = document.getElementById('cash_balance');
            let hasValue = false;
            
            providerInputs.forEach(input => {
                let value = getRawNumberValue(input);
                if (value > 0) {
                    hasValue = true;
                }
            });
            
            if (getRawNumberValue(cashBalance) > 0) {
                hasValue = true;
            }
            
            if (!hasValue) {
                e.preventDefault();
                alert('Please enter at least one value (cash or provider balance)');
                return false;
            }
            
            // Show loading state
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            btn.disabled = true;
        });
        
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
        // AUTO CALCULATE ON LOAD
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotal();
            
            // Add event listeners to all inputs
            const inputs = document.querySelectorAll('.provider-input, #cash_balance');
            inputs.forEach(input => {
                input.addEventListener('input', calculateTotal);
                input.addEventListener('change', calculateTotal);
            });
        });
        
        console.log('%c EVENING STOCK - EDIT FORM v2.0 ',
            'background:#8B0000; color:white; padding:6px 12px; border-radius:4px; font-size:13px; font-weight:bold;');
        console.log('%c 📄 Stock: <?php echo $stock['stock_number']; ?> ',
            'color:#6B7280; font-size:12px;');
        console.log('%c 📊 Auto-format: 1,000,000 | Auto-calculate: CUMM. TOTAL ',
            'color:#6B7280; font-size:12px;');
    </script>
</body>
</html>