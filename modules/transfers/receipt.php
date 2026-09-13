<?php
// ================================================================
// FILE: modules/transfers/receipt.php
// WAKALA FINANCIAL SYSTEM - TRANSFER RECEIPT (Admin)
// BLUE THEME + LOGO + Print-friendly
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET TRANSFER ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid transfer.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET TRANSFER DETAILS (Admin can view ANY transfer)
// ============================================================
$stmt = $db->prepare("
    SELECT 
        t.*,
        p.icon_class, p.color_code, p.provider_type,
        e.full_name as employee_name, e.employee_id as employee_code,
        e.profile_pic as employee_avatar,
        b.branch_name as branch_display_name,
        b.branch_code as branch_display_code,
        b.location as branch_location,
        b.phone as branch_phone,
        b.email as branch_email
    FROM transfers t
    LEFT JOIN providers p ON t.provider_id = p.id
    LEFT JOIN employees e ON t.employee_id = e.id
    LEFT JOIN branches b ON t.branch_id = b.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    $_SESSION['error_message'] = 'Transfer not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET COMPANY SETTINGS
// ============================================================
$company_name = 'Wakala System';
$company_address = '';
$company_phone = '';
$company_email = '';

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $company_name = $settings['company_name'] ?? $company_name;
    $company_address = $settings['company_address'] ?? '';
    $company_phone = $settings['company_phone'] ?? '';
    $company_email = $settings['company_email'] ?? '';
} catch (Exception $e) {}

// ============================================================
// VARIABLES
// ============================================================
$is_cash_to_float = $transfer['transfer_type'] === 'cash_to_float';
$type_label = $is_cash_to_float ? 'Cash → Float' : 'Float → Cash';

$float_before = floatval($transfer['before_float']);
$float_after = floatval($transfer['after_float']);
$float_change = $float_after - $float_before;

$cash_before = floatval($transfer['before_cash']);
$cash_after = floatval($transfer['after_cash']);
$cash_change = $cash_after - $cash_before;

$employee_initial = strtoupper(substr($transfer['employee_name'] ?? 'N', 0, 1));
$employee_avatar = $transfer['employee_avatar'] ?? '';

// Logo
$logo_path = '../../assets/images/logo.PNG';
if (!file_exists($logo_path)) {
    $logo_path = '../../assets/images/default-logo.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Receipt - <?php echo htmlspecialchars($transfer['transfer_number']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================================
           RESET
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, sans-serif;
            background: #EFF6FF;
            color: #1F2937;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }
        
        .receipt-wrapper {
            max-width: 820px;
            width: 100%;
        }
        
        /* ============================================================
           ACTION BAR
           ============================================================ */
        .action-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 12px 22px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            text-decoration: none;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.35);
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5);
        }
        
        .btn-back {
            background: white;
            color: #374151;
            border: 1.5px solid #D1D5DB;
        }
        
        .btn-back:hover {
            background: #F3F4F6;
            transform: translateY(-2px);
        }
        
        /* ============================================================
           RECEIPT PAPER
           ============================================================ */
        .receipt-paper {
            background: #FFFFFF;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(30, 64, 175, 0.15);
            overflow: hidden;
            border: 2px solid #BFDBFE;
            position: relative;
        }
        
        /* ============================================================
           RECEIPT HEADER - BLUE
           ============================================================ */
        .receipt-header {
            background: linear-gradient(135deg, #1E40AF 0%, #2563EB 50%, #3B82F6 100%);
            color: #FFFFFF;
            padding: 30px 40px;
            position: relative;
            overflow: hidden;
        }
        
        .receipt-header::before {
            content: '';
            position: absolute;
            top: -50%; right: -10%;
            width: 300px; height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .receipt-header::after {
            content: '';
            position: absolute;
            bottom: -60%; left: 20%;
            width: 250px; height: 250px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .receipt-header-content {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }
        
        /* Logo Section */
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
            flex: 1;
        }
        
        .logo-wrapper {
            width: 80px;
            height: 80px;
            background: #FFFFFF;
            border-radius: 50%;
            padding: 6px;
            flex-shrink: 0;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            border: 3px solid rgba(255, 255, 255, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .company-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        
        .company-name {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 0.3px;
            line-height: 1.2;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            word-break: break-word;
        }
        
        .company-address,
        .company-contact {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
        
        .company-address i,
        .company-contact i {
            font-size: 11px;
            width: 14px;
            flex-shrink: 0;
        }
        
        /* Receipt Label */
        .receipt-label {
            text-align: right;
            flex-shrink: 0;
        }
        
        .receipt-label-title {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            line-height: 1.2;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .receipt-label-sub {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.85);
            letter-spacing: 1px;
            margin-top: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* Status Badge */
        .receipt-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
            padding: 8px 20px;
            background: rgba(255, 255, 255, 0.25);
            color: #FFFFFF;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            border: 2px solid rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(8px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        /* Admin Badge */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            padding: 5px 14px;
            background: rgba(255, 255, 255, 0.15);
            color: #FFFFFF;
            border-radius: 16px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            border: 1.5px solid rgba(255, 255, 255, 0.3);
        }
        
        /* ============================================================
           AMOUNT DISPLAY
           ============================================================ */
        .amount-display {
            padding: 36px 40px;
            text-align: center;
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            border-bottom: 2px dashed #93C5FD;
            position: relative;
        }
        
        .amount-display::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1E40AF 0%, #2563EB 50%, #3B82F6 100%);
        }
        
        .amount-label {
            font-size: 11px;
            font-weight: 800;
            color: #1E40AF;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            margin-bottom: 10px;
        }
        
        .amount-value {
            font-size: 48px;
            font-weight: 900;
            color: #1E40AF;
            font-family: 'Inter', 'Courier New', monospace;
            letter-spacing: -1.5px;
            line-height: 1.1;
            word-break: break-all;
            text-shadow: 0 2px 8px rgba(30, 64, 175, 0.15);
        }
        
        .amount-type {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 8px 22px;
            background: #FFFFFF;
            border: 2px solid #93C5FD;
            border-radius: 22px;
            font-size: 13px;
            font-weight: 800;
            color: #1E40AF;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);
        }
        
        /* ============================================================
           RECEIPT BODY
           ============================================================ */
        .receipt-body {
            padding: 32px 40px;
        }
        
        .info-section {
            margin-bottom: 26px;
        }
        
        .info-section-title {
            font-size: 11px;
            font-weight: 800;
            color: #1E40AF;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding-bottom: 8px;
            border-bottom: 2px solid #BFDBFE;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-section-title i {
            color: #2563EB;
            font-size: 14px;
            background: #DBEAFE;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 11px 14px;
            background: #F9FAFB;
            border-radius: 10px;
            border: 1.5px solid #E5E7EB;
            transition: all 0.2s ease;
        }
        
        .info-item:hover {
            border-color: #93C5FD;
            background: #EFF6FF;
        }
        
        .info-item-label {
            font-size: 10px;
            font-weight: 700;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        .info-item-value {
            font-size: 14px;
            font-weight: 700;
            color: #1F2937;
            word-break: break-word;
        }
        
        .info-item-value.mono {
            font-family: 'Courier New', monospace;
            color: #1E40AF;
        }
        
        /* ============================================================
           FINANCIAL SECTION
           ============================================================ */
        .financial-section {
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            border-radius: 12px;
            padding: 20px 22px;
            margin-bottom: 22px;
            border: 2px solid #93C5FD;
        }
        
        .financial-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 13px 0;
            border-bottom: 1px solid #BFDBFE;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .financial-row:last-child {
            border-bottom: none;
        }
        
        .financial-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .financial-label i {
            color: #1E40AF;
            font-size: 14px;
            width: 18px;
            text-align: center;
        }
        
        .financial-value {
            font-size: 16px;
            font-weight: 800;
            color: #1F2937;
            font-family: 'Courier New', monospace;
            text-align: right;
        }
        
        .financial-value.float-color { color: #1D4ED8; }
        .financial-value.cash-color { color: #059669; }
        .financial-value.highlight {
            color: #1E40AF;
            font-size: 20px;
            font-weight: 900;
        }
        
        .financial-divider {
            border-top: 2px dashed #93C5FD;
            margin: 14px 0;
        }
        
        /* Change Badge */
        .change-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 800;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }
        
        .change-badge.positive {
            background: #D1FAE5;
            color: #047857;
            border: 1.5px solid #6EE7B7;
        }
        
        .change-badge.negative {
            background: #FEE2E2;
            color: #DC2626;
            border: 1.5px solid #FECACA;
        }
        
        /* ============================================================
           SIGNATURE SECTION
           ============================================================ */
        .signature-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 40px;
            padding-top: 24px;
            border-top: 2px dashed #BFDBFE;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-bottom: 2px solid #1F2937;
            height: 50px;
            margin-bottom: 10px;
        }
        
        .signature-label {
            font-size: 11px;
            font-weight: 800;
            color: #1E40AF;
            text-transform: uppercase;
            letter-spacing: 1.2px;
        }
        
        .signature-name {
            font-size: 12px;
            color: #6B7280;
            margin-top: 6px;
            font-weight: 600;
        }
        
        /* ============================================================
           RECEIPT FOOTER - DARK BLUE
           ============================================================ */
        .receipt-footer {
            background: linear-gradient(135deg, #1E3A8A 0%, #1E40AF 100%);
            color: #FFFFFF;
            padding: 20px 40px;
            font-size: 11px;
            line-height: 1.6;
            position: relative;
            overflow: hidden;
        }
        
        .receipt-footer::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3B82F6 0%, #60A5FA 50%, #93C5FD 100%);
        }
        
        .receipt-footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            position: relative;
            z-index: 1;
        }
        
        .footer-info {
            display: flex;
            align-items: center;
            gap: 6px;
            color: rgba(255, 255, 255, 0.85);
            font-weight: 600;
        }
        
        .footer-info i {
            color: #93C5FD;
            font-size: 12px;
        }
        
        /* ============================================================
           WATERMARK
           ============================================================ */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 120px;
            font-weight: 900;
            color: rgba(37, 99, 235, 0.03);
            pointer-events: none;
            user-select: none;
            z-index: 0;
            letter-spacing: 10px;
        }
        
        /* ============================================================
           PRINT STYLES
           ============================================================ */
        @media print {
            @page {
                size: A4;
                margin: 8mm;
            }
            
            body {
                background: #FFFFFF !important;
                padding: 0;
                display: block;
            }
            
            .action-bar {
                display: none !important;
            }
            
            .receipt-wrapper {
                max-width: 100%;
            }
            
            .receipt-paper {
                box-shadow: none;
                border-radius: 0;
                border: 1px solid #BFDBFE;
            }
            
            .receipt-header {
                background: linear-gradient(135deg, #1E40AF 0%, #2563EB 50%, #3B82F6 100%) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .amount-display {
                background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .receipt-footer {
                background: linear-gradient(135deg, #1E3A8A 0%, #1E40AF 100%) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .receipt-status,
            .admin-badge {
                background: rgba(255, 255, 255, 0.25) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .info-item,
            .financial-section {
                background: #F9FAFB !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .financial-section {
                background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%) !important;
            }
            
            .info-section-title i {
                background: #DBEAFE !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 640px) {
            body { padding: 10px; }
            
            .receipt-header { padding: 24px 20px; }
            .receipt-body { padding: 20px 18px; }
            .amount-display { padding: 26px 20px; }
            .amount-value { font-size: 32px; }
            .receipt-footer { padding: 16px 20px; }
            
            .receipt-header-content {
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
            }
            
            .header-left {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .logo-wrapper {
                width: 64px;
                height: 64px;
            }
            
            .company-name {
                font-size: 18px;
            }
            
            .receipt-label {
                text-align: left;
            }
            
            .receipt-label-title {
                font-size: 16px;
            }
            
            .info-grid { grid-template-columns: 1fr; }
            .signature-section { grid-template-columns: 1fr; gap: 30px; }
            
            .receipt-footer-content {
                flex-direction: column;
                text-align: center;
            }
            
            .action-bar {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
            
            .financial-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            
            .financial-value {
                text-align: left;
            }
        }
    </style>
</head>
<body>

<div class="receipt-wrapper">
    
    <!-- ============================================================
    ACTION BAR (Hidden on Print)
    ============================================================ -->
    <div class="action-bar">
        <a href="view.php?id=<?php echo $id; ?>" class="btn-action btn-back">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Transfer</span>
        </a>
        <button onclick="window.print()" class="btn-action btn-print">
            <i class="fas fa-print"></i>
            <span>Print This Receipt</span>
        </button>
    </div>
    
    <!-- ============================================================
    RECEIPT PAPER
    ============================================================ -->
    <div class="receipt-paper">
        
        <!-- Watermark -->
        <div class="watermark">PAID</div>
        
        <!-- ============================================================
        HEADER - BLUE with Logo
        ============================================================ -->
        <div class="receipt-header">
            <div class="receipt-header-content">
                
                <!-- LEFT: Logo + Company Info -->
                <div class="header-left">
                    <div class="logo-wrapper">
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" 
                             alt="<?php echo htmlspecialchars($company_name); ?> Logo"
                             onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-building\' style=\'font-size:36px;color:#1E40AF;\'></i>';">
                    </div>
                    
                    <div class="company-info">
                        <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                        <?php if ($company_address): ?>
                        <div class="company-address">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($company_address); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($company_phone): ?>
                        <div class="company-contact">
                            <i class="fas fa-phone"></i>
                            <?php echo htmlspecialchars($company_phone); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($company_email): ?>
                        <div class="company-contact">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($company_email); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- RIGHT: Receipt Label + Status -->
                <div class="receipt-label">
                    <div class="receipt-label-title">TRANSFER RECEIPT</div>
                    <div class="receipt-label-sub">Official Payment Confirmation</div>
                    
                    <div class="receipt-status">
                        <i class="fas fa-check-circle"></i>
                        <?php echo ucfirst($transfer['status'] ?? 'Completed'); ?>
                    </div>
                    
                    <!-- Admin Badge -->
                    <div class="admin-badge">
                        <i class="fas fa-shield-alt"></i>
                        Admin Copy
                    </div>
                </div>
                
            </div>
        </div>
        
        <!-- ============================================================
        AMOUNT DISPLAY
        ============================================================ -->
        <div class="amount-display">
            <div class="amount-label">Transfer Amount</div>
            <div class="amount-value"><?php echo formatCurrency($transfer['amount']); ?></div>
            <div class="amount-type">
                <i class="fas fa-arrow-<?php echo $is_cash_to_float ? 'right' : 'left'; ?>"></i>
                <?php echo $type_label; ?>
            </div>
        </div>
        
        <!-- ============================================================
        RECEIPT BODY
        ============================================================ -->
        <div class="receipt-body">
            
            <!-- Transfer Information -->
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-info-circle"></i>
                    Transfer Information
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-item-label">Transfer Number</span>
                        <span class="info-item-value mono"><?php echo htmlspecialchars($transfer['transfer_number']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Transfer Type</span>
                        <span class="info-item-value"><?php echo $type_label; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Transfer Date</span>
                        <span class="info-item-value"><?php echo date('d M Y', strtotime($transfer['transfer_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Transfer Time</span>
                        <span class="info-item-value"><?php echo date('H:i:s', strtotime($transfer['transfer_time'] ?? $transfer['created_at'])); ?></span>
                    </div>
                    <?php if (!empty($transfer['reference_number'])): ?>
                    <div class="info-item">
                        <span class="info-item-label">Reference Number</span>
                        <span class="info-item-value mono"><?php echo htmlspecialchars($transfer['reference_number']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Provider Information -->
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-university"></i>
                    Provider Information
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-item-label">Provider Name</span>
                        <span class="info-item-value"><?php echo htmlspecialchars($transfer['provider_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Provider Code</span>
                        <span class="info-item-value mono"><?php echo htmlspecialchars($transfer['provider_code'] ?? '-'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Branch & Employee -->
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-store-alt"></i>
                    Branch & Employee
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-item-label">Branch</span>
                        <span class="info-item-value"><?php echo htmlspecialchars($transfer['branch_display_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Branch Code</span>
                        <span class="info-item-value mono"><?php echo htmlspecialchars($transfer['branch_display_code'] ?? '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Employee</span>
                        <span class="info-item-value"><?php echo htmlspecialchars($transfer['employee_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-item-label">Employee Code</span>
                        <span class="info-item-value mono"><?php echo htmlspecialchars($transfer['employee_code'] ?? '-'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Financial Breakdown -->
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-calculator"></i>
                    Financial Breakdown
                </div>
                
                <div class="financial-section">
                    
                    <!-- FLOAT -->
                    <div class="financial-row">
                        <span class="financial-label">
                            <i class="fas fa-coins"></i>
                            Float Before Transfer
                        </span>
                        <span class="financial-value float-color">
                            <?php echo formatCurrency($float_before); ?>
                        </span>
                    </div>
                    
                    <div class="financial-row">
                        <span class="financial-label">
                            <i class="fas fa-<?php echo $is_cash_to_float ? 'arrow-up' : 'arrow-down'; ?>"></i>
                            Float Change
                        </span>
                        <span class="change-badge <?php echo $float_change >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo $float_change >= 0 ? '+' : ''; ?>
                            <?php echo formatCurrency(abs($float_change)); ?>
                        </span>
                    </div>
                    
                    <div class="financial-row">
                        <span class="financial-label">
                            <i class="fas fa-coins"></i>
                            Float After Transfer
                        </span>
                        <span class="financial-value float-color">
                            <?php echo formatCurrency($float_after); ?>
                        </span>
                    </div>
                    
                    <!-- CASH -->
                    <div class="financial-divider"></div>
                    
                    <div class="financial-row">
                        <span class="financial-label">
                            <i class="fas fa-money-bill-wave"></i>
                            Cash Before Transfer
                        </span>
                        <span class="financial-value cash-color">
                            <?php echo formatCurrency($cash_before); ?>
                        </span>
                    </div>
                    
                    <div class="financial-row">
                        <span class="financial-label">
                            <i class="fas fa-<?php echo $is_cash_to_float ? 'arrow-down' : 'arrow-up'; ?>"></i>
                            Cash Change
                        </span>
                        <span class="change-badge <?php echo $cash_change >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo $cash_change >= 0 ? '+' : ''; ?>
                            <?php echo formatCurrency(abs($cash_change)); ?>
                        </span>
                    </div>
                    
                    <div class="financial-row">
                        <span class="financial-label">
                            <i class="fas fa-money-bill-wave"></i>
                            Cash After Transfer
                        </span>
                        <span class="financial-value cash-color">
                            <?php echo formatCurrency($cash_after); ?>
                        </span>
                    </div>
                    
                    <!-- TOTAL -->
                    <div class="financial-divider"></div>
                    
                    <div class="financial-row">
                        <span class="financial-label" style="font-weight: 900; color: #1E40AF; font-size: 14px;">
                            <i class="fas fa-exchange-alt"></i>
                            Total Transfer Amount
                        </span>
                        <span class="financial-value highlight">
                            <?php echo formatCurrency($transfer['amount']); ?>
                        </span>
                    </div>
                    
                </div>
            </div>
            
            <!-- Description -->
            <?php if (!empty($transfer['description']) || !empty($transfer['notes'])): ?>
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-sticky-note"></i>
                    Description & Notes
                </div>
                <div class="info-item" style="margin-bottom: 10px;">
                    <span class="info-item-label">Description</span>
                    <span class="info-item-value" style="font-weight: 500; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($transfer['description'] ?: 'No description provided')); ?>
                    </span>
                </div>
                <?php if (!empty($transfer['notes'])): ?>
                <div class="info-item">
                    <span class="info-item-label">Notes</span>
                    <span class="info-item-value" style="font-weight: 500; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($transfer['notes'])); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Signature Section -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Prepared By</div>
                    <div class="signature-name">
                        <?php echo htmlspecialchars($transfer['employee_name'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Approved By</div>
                    <div class="signature-name">_____________________</div>
                </div>
            </div>
            
        </div>
        
        <!-- ============================================================
        FOOTER - DARK BLUE
        ============================================================ -->
        <div class="receipt-footer">
            <div class="receipt-footer-content">
                <div class="footer-info">
                    <i class="fas fa-clock"></i>
                    <span>Generated: <?php echo date('d M Y H:i:s'); ?></span>
                </div>
                <div class="footer-info">
                    <i class="fas fa-check-circle"></i>
                    <span>Official Transfer Receipt</span>
                </div>
                <div class="footer-info">
                    <i class="fas fa-shield-alt"></i>
                    <span>Admin Copy · Verified</span>
                </div>
            </div>
        </div>
        
    </div>
    
</div>

</body>
</html>