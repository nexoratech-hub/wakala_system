<?php
// ================================================================
// FILE: modules/morning_report/print.php
// WAKALA FINANCIAL SYSTEM - PRINT MORNING REPORT
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

$user_id = $_SESSION['user_id'];

// ============================================================
// GET REPORT ID
// ============================================================
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($report_id <= 0) {
    die('Invalid report ID.');
}

// ============================================================
// GET REPORT DATA
// ============================================================
$stmt = $db->prepare("SELECT 
                        mr.*,
                        e.full_name as employee_name,
                        e.employee_id as employee_code,
                        b.branch_name as branch_name,
                        b.branch_code,
                        b.location as branch_location,
                        b.phone as branch_phone,
                        b.email as branch_email
                      FROM morning_reports mr
                      LEFT JOIN employees e ON mr.employee_id = e.id
                      LEFT JOIN branches b ON mr.branch_id = b.id
                      WHERE mr.id = ?");
$stmt->execute([$report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die('Report not found.');
}

// ============================================================
// DECODE PROVIDER DATA
// ============================================================
$provider_data = json_decode($report['provider_data'], true);
$providers = [];

if (!empty($provider_data)) {
    $placeholders = implode(',', array_fill(0, count($provider_data), '?'));
    $stmt = $db->prepare("SELECT id, provider_code, provider_name, icon_class, color_code FROM providers WHERE id IN ($placeholders) ORDER BY display_order");
    $stmt->execute(array_keys($provider_data));
    $providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($providers_list as $p) {
        $p['amount'] = $provider_data[$p['id']] ?? 0;
        $providers[] = $p;
    }
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_float = $report['cumm_total'] ?? 0;
$cash_balance = $report['cash_balance'] ?? 0;
$grand_total = $total_float + $cash_balance;

// ============================================================
// COMPANY SETTINGS
// ============================================================
$company_name = 'Wakala Financial System';
$company_address = 'Dar es Salaam, Tanzania';
$company_phone = '+255 700 000 000';
$company_email = 'info@wakala.com';

// Try to get from settings
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($settings['company_name'])) $company_name = $settings['company_name'];
    if (isset($settings['company_address'])) $company_address = $settings['company_address'];
    if (isset($settings['company_phone'])) $company_phone = $settings['company_phone'];
    if (isset($settings['company_email'])) $company_email = $settings['company_email'];
} catch (Exception $e) {
    // Use defaults
}

// ============================================================
// LOGO PATH
// ============================================================
$logo_path = '../../assets/images/logo.PNG';
$logo_base64 = '';

// Check if logo exists and convert to base64 for print
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
} else {
    // Try alternative path
    $logo_path_alt = '../assets/images/logo.PNG';
    if (file_exists($logo_path_alt)) {
        $logo_data = file_get_contents($logo_path_alt);
        $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morning Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Print Styles */
        @media print {
            body { background: white !important; }
            .no-print { display: none !important; }
            .print-container { 
                margin: 0 !important; 
                padding: 20px !important;
                box-shadow: none !important;
                border: none !important;
            }
            .page-break { page-break-after: always; }
            .report-header { background: #f8f9fa !important; }
        }
        
        /* Main Styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: #f3f4f6;
            padding: 20px;
            color: #1F2937;
        }
        
        .print-container {
            max-width: 1000px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 30px 40px;
            position: relative;
        }
        
        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            font-weight: 900;
            color: rgba(220, 38, 38, 0.05);
            pointer-events: none;
            z-index: 0;
            white-space: nowrap;
            letter-spacing: 10px;
        }
        
        /* Report Header */
        .report-header {
            border-bottom: 3px solid #DC2626;
            padding-bottom: 20px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .header-logo {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid #DC2626;
            flex-shrink: 0;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .header-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .header-logo .logo-placeholder {
            width: 100%;
            height: 100%;
            background: #DC2626;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 800;
        }
        
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1F2937;
        }
        
        .header-title .report-number {
            font-size: 14px;
            color: #DC2626;
            font-weight: 600;
        }
        
        .header-title .report-date {
            font-size: 13px;
            color: #6B7280;
        }
        
        .header-right {
            text-align: right;
        }
        
        .header-right .company-name {
            font-size: 16px;
            font-weight: 700;
            color: #1F2937;
        }
        
        .header-right .company-detail {
            font-size: 12px;
            color: #6B7280;
            display: block;
        }
        
        /* Report Meta */
        .report-meta {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
            padding: 16px 20px;
            background: #F9FAFB;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
            position: relative;
            z-index: 1;
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .meta-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6B7280;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .meta-value {
            font-size: 14px;
            font-weight: 600;
            color: #1F2937;
        }
        
        /* Section Title */
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1F2937;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #E5E7EB;
            position: relative;
            z-index: 1;
        }
        
        .section-title i {
            color: #DC2626;
            margin-right: 8px;
        }
        
        /* Providers Table */
        .providers-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }
        
        .providers-table thead {
            background: #DC2626;
        }
        
        .providers-table thead th {
            padding: 10px 14px;
            text-align: left;
            color: white;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .providers-table thead th:last-child {
            text-align: right;
        }
        
        .providers-table tbody tr {
            border-bottom: 1px solid #E5E7EB;
        }
        
        .providers-table tbody tr:last-child {
            border-bottom: none;
        }
        
        .providers-table tbody td {
            padding: 10px 14px;
            font-size: 13px;
            color: #1F2937;
        }
        
        .providers-table tbody td:last-child {
            text-align: right;
            font-weight: 600;
        }
        
        .provider-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            color: white;
            font-size: 12px;
            margin-right: 8px;
        }
        
        .provider-name-cell {
            display: flex;
            align-items: center;
        }
        
        .provider-code-badge {
            font-size: 10px;
            color: #6B7280;
            background: #F3F4F6;
            padding: 1px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }
        
        /* Totals */
        .totals-section {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 2px solid #E5E7EB;
            position: relative;
            z-index: 1;
        }
        
        .total-box {
            padding: 12px 16px;
            border-radius: 8px;
            text-align: center;
        }
        
        .total-box .total-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #6B7280;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .total-box .total-value {
            font-size: 20px;
            font-weight: 800;
        }
        
        .total-box.total-float {
            background: #DBEAFE;
        }
        
        .total-box.total-float .total-value {
            color: #1D4ED8;
        }
        
        .total-box.total-cash {
            background: #D1FAE5;
        }
        
        .total-box.total-cash .total-value {
            color: #065F46;
        }
        
        .total-box.total-grand {
            background: #FEF3C7;
        }
        
        .total-box.total-grand .total-value {
            color: #D97706;
        }
        
        /* Footer */
        .report-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #6B7280;
            position: relative;
            z-index: 1;
        }
        
        .report-footer .signatures {
            display: flex;
            gap: 40px;
        }
        
        .report-footer .signature-line {
            text-align: center;
        }
        
        .report-footer .signature-line .line {
            width: 120px;
            border-bottom: 1px solid #1F2937;
            margin-bottom: 4px;
        }
        
        .report-footer .signature-line .label {
            font-size: 11px;
            color: #6B7280;
        }
        
        /* Print Button */
        .print-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #DC2626;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }
        
        .print-btn:hover {
            background: #B91C1C;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220,38,38,0.3);
        }
        
        .print-btn i {
            font-size: 16px;
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #6B7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-back:hover {
            background: #4B5563;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .print-container {
                padding: 20px;
            }
            
            .report-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .header-right {
                text-align: left;
                width: 100%;
            }
            
            .report-meta {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .totals-section {
                grid-template-columns: 1fr;
            }
            
            .report-footer {
                flex-direction: column;
                gap: 16px;
            }
            
            .report-footer .signatures {
                flex-direction: column;
                gap: 16px;
                width: 100%;
            }
            
            .report-footer .signature-line .line {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="print-container">
    <!-- Watermark -->
    <div class="watermark">MORNING REPORT</div>
    
    <!-- Report Header -->
    <div class="report-header">
        <div class="header-left">
            <div class="header-logo">
                <?php if (!empty($logo_base64)): ?>
                    <img src="<?php echo $logo_base64; ?>" alt="Company Logo">
                <?php else: ?>
                    <div class="logo-placeholder">
                        <i class="fas fa-sun"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-title">
                <h1>Morning Report</h1>
                <span class="report-number"><?php echo htmlspecialchars($report['report_number']); ?></span>
                <span class="report-date">| <?php echo date('d M Y, H:i', strtotime($report['created_at'] ?? $report['submitted_at'])); ?></span>
            </div>
        </div>
        <div class="header-right">
            <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
            <span class="company-detail"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($company_address); ?></span>
            <span class="company-detail"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($company_phone); ?></span>
            <span class="company-detail"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($company_email); ?></span>
        </div>
    </div>
    
    <!-- Report Meta -->
    <div class="report-meta">
        <div class="meta-item">
            <span class="meta-label">Branch</span>
            <span class="meta-value">
                <i class="fas fa-store-alt" style="color:#DC2626; font-size:12px;"></i>
                <?php echo htmlspecialchars($report['branch_name'] ?? $report['branch'] ?? 'Main'); ?>
                <?php if (!empty($report['branch_code'])): ?>
                    <span style="font-size:12px; color:#6B7280; font-weight:400;">(<?php echo htmlspecialchars($report['branch_code']); ?>)</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Report Date</span>
            <span class="meta-value">
                <i class="fas fa-calendar-alt" style="color:#DC2626; font-size:12px;"></i>
                <?php echo date('d M Y', strtotime($report['report_date'])); ?>
            </span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Prepared By</span>
            <span class="meta-value">
                <i class="fas fa-user" style="color:#DC2626; font-size:12px;"></i>
                <?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?>
            </span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Employee ID</span>
            <span class="meta-value">
                <i class="fas fa-id-badge" style="color:#DC2626; font-size:12px;"></i>
                <?php echo htmlspecialchars($report['employee_code'] ?? 'N/A'); ?>
            </span>
        </div>
    </div>
    
    <!-- Providers Table -->
    <h4 class="section-title"><i class="fas fa-university"></i> Provider Balances</h4>
    
    <table class="providers-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Provider</th>
                <th style="text-align:right;">Amount (TSh)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($providers)): ?>
                <?php $counter = 1; ?>
                <?php foreach ($providers as $provider): ?>
                    <tr>
                        <td style="width:40px; color:#6B7280;"><?php echo $counter++; ?></td>
                        <td>
                            <div class="provider-name-cell">
                                <span class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                                    <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                </span>
                                <?php echo htmlspecialchars($provider['provider_name']); ?>
                                <span class="provider-code-badge"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                            </div>
                        </td>
                        <td style="text-align:right; font-weight:600;">
                            <?php echo number_format($provider['amount'], 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3" style="text-align:center; color:#6B7280; padding:20px;">
                        <i class="fas fa-info-circle"></i> No provider data available
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Totals -->
    <div class="totals-section">
        <div class="total-box total-float">
            <div class="total-label">Total Float</div>
            <div class="total-value"><?php echo number_format($total_float, 2); ?></div>
        </div>
        <div class="total-box total-cash">
            <div class="total-label">Cash Balance</div>
            <div class="total-value"><?php echo number_format($cash_balance, 2); ?></div>
        </div>
        <div class="total-box total-grand">
            <div class="total-label">Grand Total</div>
            <div class="total-value"><?php echo number_format($grand_total, 2); ?></div>
        </div>
    </div>
    
    <!-- Notes -->
    <?php if (!empty($report['notes'])): ?>
        <div style="margin-top: 16px; padding: 12px 16px; background: #F9FAFB; border-radius: 8px; border-left: 4px solid #DC2626; position:relative; z-index:1;">
            <span style="font-size:12px; color:#6B7280; font-weight:600; text-transform:uppercase;">Notes:</span>
            <p style="margin-top:4px; font-size:13px; color:#1F2937;"><?php echo nl2br(htmlspecialchars($report['notes'])); ?></p>
        </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <div class="report-footer">
        <div>
            <span>Generated on: <?php echo date('d M Y, H:i:s'); ?></span>
            <span style="margin-left:16px;">| Version 2.0.0</span>
        </div>
        <div class="signatures">
            <div class="signature-line">
                <div class="line"></div>
                <div class="label">Prepared By</div>
                <div style="font-weight:500; color:#1F2937;"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></div>
            </div>
            <div class="signature-line">
                <div class="line"></div>
                <div class="label">Approved By</div>
                <div style="font-weight:500; color:#1F2937;">_________________</div>
            </div>
            <div class="signature-line">
                <div class="line"></div>
                <div class="label">Date</div>
                <div style="font-weight:500; color:#1F2937;"><?php echo date('d M Y'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Actions -->
<div style="max-width:1000px; margin:20px auto; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;" class="no-print">
    <button onclick="window.print()" class="print-btn">
        <i class="fas fa-print"></i> Print / PDF
    </button>
    <a href="view.php?id=<?php echo $report_id; ?>" class="btn-back">
        <i class="fas fa-eye"></i> View Report
    </a>
    <a href="index.php" class="btn-back">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-print if print parameter is set
    if (window.location.search.includes('print=1')) {
        setTimeout(function() {
            window.print();
        }, 500);
    }
});
</script>

</body>
</html>