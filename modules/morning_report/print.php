<?php
// ================================================================
// FILE: modules/morning_report/print.php
// WAKALA FINANCIAL SYSTEM - PRINT MORNING REPORT
// 
// ✅ Print-friendly design
// ✅ Works for both admin & employee
// ✅ Employee can only print own branch reports
// ✅ Full English UI
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
$role    = $_SESSION['role'] ?? 'employee';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid morning report.';
    $redirect = ($role === 'admin' || $role === 'super_admin') ? 'index.php' : 'index_employee.php';
    header('Location: ' . $redirect);
    exit();
}

// ============================================================
// FETCH REPORT (Admin sees all, Employee sees own branch)
// ============================================================
$sql = "
    SELECT 
        mr.*,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        b.location AS branch_location,
        b.phone AS branch_phone,
        b.email AS branch_email,
        es.stock_number AS source_stock_number,
        es.stock_date AS source_stock_date
    FROM morning_reports mr
    LEFT JOIN employees e ON mr.employee_id = e.id
    LEFT JOIN branches b ON mr.branch_id = b.id
    LEFT JOIN evening_stocks es ON mr.source_evening_stock_id = es.id
    WHERE mr.id = ?
";

$params = [$id];

// Employee can only see own branch
if ($role !== 'admin' && $role !== 'super_admin') {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $employee_branch = intval($emp['branch_id'] ?? 0);
    
    $sql .= " AND mr.branch_id = ?";
    $params[] = $employee_branch;
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['error_message'] = 'Morning report not found.';
    $redirect = ($role === 'admin' || $role === 'super_admin') ? 'index.php' : 'index_employee.php';
    header('Location: ' . $redirect);
    exit();
}

// ============================================================
// FETCH PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        mrp.*,
        p.icon_class, p.color_code, p.provider_type
    FROM morning_report_providers mrp
    LEFT JOIN providers p ON mrp.provider_id = p.id
    WHERE mrp.report_id = ?
    ORDER BY p.display_order, mrp.provider_name
");
$stmt->execute([$id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// COMPANY SETTINGS
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
// TOTALS
// ============================================================
$total_float = 0;
foreach ($providers as $p) $total_float += floatval($p['float_balance']);
$cash_balance = floatval($report['cash_balance']);
$cumm_total = $total_float + $cash_balance;

$logo_path = '../../assets/images/logo.PNG';
if (!file_exists($logo_path)) {
    $logo_path = '../../assets/images/default-logo.png';
}

// Back link depending on role
$back_link = ($role === 'admin' || $role === 'super_admin') 
             ? 'view.php?id=' . $id 
             : 'view_employee.php?id=' . $id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morning Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: #FFF7ED;
            color: #1F2937;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }
        .print-wrapper { max-width: 850px; width: 100%; }
        
        /* ============================================================
           ACTION BAR
           ============================================================ */
        .action-bar { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .btn-action {
            padding: 12px 22px; border: none; border-radius: 10px;
            font-weight: 700; font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 8px;
            text-decoration: none; transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }
        .btn-print {
            background: linear-gradient(135deg, #1E40AF, #2563EB);
            color: white;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.35);
        }
        .btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5); }
        .btn-back {
            background: white; color: #374151;
            border: 1.5px solid #D1D5DB;
        }
        .btn-back:hover { background: #F3F4F6; transform: translateY(-2px); }

        /* ============================================================
           PRINT PAPER
           ============================================================ */
        .print-paper {
            background: #FFF;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(30, 64, 175, 0.15);
            overflow: hidden;
            border: 2px solid #BFDBFE;
            position: relative;
        }

        /* ============================================================
           HEADER - BLUE THEME
           ============================================================ */
        .print-header {
            background: linear-gradient(135deg, #1E40AF 0%, #2563EB 50%, #3B82F6 100%);
            color: #FFF;
            padding: 30px 40px;
            position: relative;
            overflow: hidden;
        }
        .print-header::before {
            content: '';
            position: absolute;
            top: -50%; right: -10%;
            width: 300px; height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        .header-content {
            position: relative; z-index: 1;
            display: flex; align-items: center;
            justify-content: space-between;
            gap: 24px; flex-wrap: wrap;
        }
        .header-left { display: flex; align-items: center; gap: 16px; flex: 1; min-width: 0; }
        .logo-wrapper {
            width: 80px; height: 80px;
            background: #FFF; border-radius: 50%;
            padding: 6px; flex-shrink: 0;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            border: 3px solid rgba(255,255,255,0.5);
            display: flex; align-items: center; justify-content: center;
        }
        .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; border-radius: 50%; }
        .company-info { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .company-name { font-size: 22px; font-weight: 900; }
        .company-detail { font-size: 12px; color: rgba(255,255,255,0.9); display: flex; align-items: center; gap: 6px; }
        .receipt-label { text-align: right; flex-shrink: 0; }
        .receipt-label-title { font-size: 20px; font-weight: 900; letter-spacing: 2.5px; text-transform: uppercase; }
        .receipt-label-sub { font-size: 11px; color: rgba(255,255,255,0.85); letter-spacing: 1px; margin-top: 4px; font-weight: 600; }
        .receipt-status {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 12px; padding: 8px 20px;
            background: rgba(255,255,255,0.25); color: #FFF;
            border-radius: 20px; font-size: 11px; font-weight: 900;
            letter-spacing: 1.5px; text-transform: uppercase;
            border: 2px solid rgba(255,255,255,0.4);
        }

        /* ============================================================
           REPORT NUMBER DISPLAY
           ============================================================ */
        .amount-display {
            padding: 32px 40px;
            text-align: center;
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            border-bottom: 2px dashed #3B82F6;
        }
        .amount-label { font-size: 11px; font-weight: 800; color: #1E40AF; text-transform: uppercase; letter-spacing: 2.5px; margin-bottom: 10px; }
        .amount-value { font-size: 44px; font-weight: 900; color: #1E3A8A; font-family: 'Courier New', monospace; letter-spacing: -1.5px; line-height: 1.1; word-break: break-all; }
        .amount-sub { font-size: 13px; color: #1E40AF; font-weight: 600; margin-top: 8px; }

        /* ============================================================
           BODY
           ============================================================ */
        .print-body { padding: 32px 40px; }
        .info-section { margin-bottom: 24px; }
        .info-section-title {
            font-size: 11px; font-weight: 800; color: #1E40AF;
            text-transform: uppercase; letter-spacing: 1.5px;
            padding-bottom: 8px; border-bottom: 2px solid #BFDBFE;
            margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .info-section-title i {
            color: #2563EB; font-size: 14px;
            background: #DBEAFE; width: 26px; height: 26px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
        .info-item {
            display: flex; flex-direction: column; gap: 4px;
            padding: 11px 14px; background: #F9FAFB;
            border-radius: 10px; border: 1.5px solid #E5E7EB;
        }
        .info-label { font-size: 10px; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.8px; }
        .info-value { font-size: 14px; font-weight: 700; color: #1F2937; word-break: break-word; }
        .info-value.mono { font-family: 'Courier New', monospace; color: #1E40AF; }

        /* ============================================================
           PROVIDER TABLE
           ============================================================ */
        .provider-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .provider-table thead tr { background: linear-gradient(135deg, #1E40AF, #2563EB); color: #FFF; }
        .provider-table thead th {
            padding: 12px 14px; text-align: left;
            font-weight: 700; font-size: 10px;
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .provider-table thead th.text-right { text-align: right; }
        .provider-table tbody tr { border-bottom: 1px solid #E5E7EB; }
        .provider-table tbody tr:nth-child(even) { background: #EFF6FF; }
        .provider-table tbody td { padding: 12px 14px; color: #1F2937; vertical-align: middle; }
        .provider-table tbody td.text-right { text-align: right; }
        .provider-table tfoot tr { background: linear-gradient(135deg, #DBEAFE, #BFDBFE); border-top: 2px solid #2563EB; }
        .provider-table tfoot td { padding: 14px; font-weight: 900; color: #1E3A8A; }
        .provider-table tfoot td.text-right { text-align: right; }

        .provider-cell { display: flex; align-items: center; gap: 10px; }
        .provider-icon {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #FFF; font-size: 12px; flex-shrink: 0;
        }
        .code-badge {
            display: inline-block; padding: 3px 10px;
            background: #FEF3C7; color: #B45309;
            border-radius: 8px; font-size: 10px; font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        .amount-cell { font-family: 'Courier New', monospace; font-weight: 800; color: #1E40AF; }

        /* ============================================================
           FINANCIAL SUMMARY
           ============================================================ */
        .financial-summary {
            background: linear-gradient(135deg, #EFF6FF, #DBEAFE);
            border: 2px solid #2563EB;
            border-radius: 12px;
            padding: 20px 24px;
            margin-top: 24px;
        }
        .financial-row {
            display: flex; justify-content: space-between;
            align-items: center; padding: 12px 0;
            border-bottom: 1px solid #93C5FD;
            font-size: 14px; color: #1E3A8A; font-weight: 600;
            gap: 12px; flex-wrap: wrap;
        }
        .financial-row:last-child { border-bottom: none; }
        .financial-label { display: flex; align-items: center; gap: 8px; }
        .financial-label i { color: #2563EB; }
        .financial-value { font-family: 'Courier New', monospace; font-weight: 800; color: #1E40AF; font-size: 16px; }
        .financial-divider { border-top: 2px dashed #2563EB; margin: 8px 0; }
        .financial-row-total { font-size: 18px !important; padding-top: 12px; }
        .financial-row-total .financial-value { font-size: 24px; color: #1E3A8A; }

        /* ============================================================
           SIGNATURES
           ============================================================ */
        .signature-section {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 40px; margin-top: 40px; padding-top: 24px;
            border-top: 2px dashed #93C5FD;
        }
        .signature-box { text-align: center; }
        .signature-line { border-bottom: 2px solid #1F2937; height: 50px; margin-bottom: 10px; }
        .signature-label { font-size: 11px; font-weight: 800; color: #1E40AF; text-transform: uppercase; letter-spacing: 1.2px; }
        .signature-name { font-size: 12px; color: #6B7280; margin-top: 6px; font-weight: 600; }

        /* ============================================================
           FOOTER - BLUE THEME
           ============================================================ */
        .print-footer {
            background: linear-gradient(135deg, #1E3A8A, #1E40AF);
            color: #FFF; padding: 20px 40px;
            font-size: 11px; line-height: 1.6;
        }
        .footer-content {
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 14px;
        }
        .footer-info { display: flex; align-items: center; gap: 6px; color: rgba(255,255,255,0.85); font-weight: 600; }
        .footer-info i { color: #93C5FD; font-size: 12px; }

        /* ============================================================
           WATERMARK
           ============================================================ */
        .watermark {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 120px; font-weight: 900;
            color: rgba(37, 99, 235, 0.04);
            pointer-events: none; user-select: none;
            z-index: 0; letter-spacing: 10px;
        }

        /* ============================================================
           PRINT STYLES
           ============================================================ */
        @media print {
            @page { size: A4; margin: 8mm; }
            body { background: #FFF !important; padding: 0; display: block; }
            .action-bar { display: none !important; }
            .print-wrapper { max-width: 100%; }
            .print-paper { box-shadow: none; border-radius: 0; border: none; }
            .print-header, .amount-display, .financial-summary, .print-footer,
            .provider-table thead tr, .provider-table tfoot tr {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 640px) {
            body { padding: 10px; }
            .print-header { padding: 24px 20px; }
            .print-body { padding: 20px 18px; }
            .amount-display { padding: 26px 20px; }
            .amount-value { font-size: 32px; }
            .print-footer { padding: 16px 20px; }
            .header-content { flex-direction: column; align-items: flex-start; }
            .receipt-label { text-align: left; }
            .info-grid { grid-template-columns: 1fr; }
            .signature-section { grid-template-columns: 1fr; gap: 30px; }
            .action-bar { flex-direction: column; }
            .btn-action { width: 100%; justify-content: center; }
            .financial-row { flex-direction: column; align-items: flex-start; gap: 4px; }
        }
    </style>
</head>
<body>

<div class="print-wrapper">
    
    <!-- ACTION BAR -->
    <div class="action-bar">
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="btn-action btn-back">
            <i class="fas fa-arrow-left"></i> Back to Report
        </a>
        <button onclick="window.print()" class="btn-action btn-print">
            <i class="fas fa-print"></i> Print This Report
        </button>
    </div>

    <div class="print-paper">
        <div class="watermark">MORNING</div>

        <!-- HEADER -->
        <div class="print-header">
            <div class="header-content">
                <div class="header-left">
                    <div class="logo-wrapper">
                        <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Logo"
                             onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-building\' style=\'font-size:36px;color:#1E40AF;\'></i>';">
                    </div>
                    <div class="company-info">
                        <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                        <?php if ($company_address): ?>
                        <div class="company-detail"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($company_address); ?></div>
                        <?php endif; ?>
                        <?php if ($company_phone): ?>
                        <div class="company-detail"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($company_phone); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="receipt-label">
                    <div class="receipt-label-title">MORNING REPORT</div>
                    <div class="receipt-label-sub">Daily Opening Balance</div>
                    <div class="receipt-status">
                        <i class="fas fa-check-circle"></i>
                        <?php echo intval($report['is_locked']) === 1 ? 'Locked' : 'Open'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- REPORT NUMBER -->
        <div class="amount-display">
            <div class="amount-label">Report Number</div>
            <div class="amount-value"><?php echo htmlspecialchars($report['report_number']); ?></div>
            <div class="amount-sub">
                <i class="fas fa-calendar"></i>
                <?php echo date('d M Y', strtotime($report['report_date'])); ?>
            </div>
        </div>

        <!-- BODY -->
        <div class="print-body">

            <!-- REPORT INFO -->
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-info-circle"></i>
                    Report Information
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Branch</span>
                        <span class="info-value"><?php echo htmlspecialchars($report['branch_display_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Branch Code</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($report['branch_display_code'] ?? '-'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Report Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($report['report_date'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Submitted At</span>
                        <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($report['submitted_at'])); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Prepared By</span>
                        <span class="info-value"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Employee Code</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($report['employee_code'] ?? '-'); ?></span>
                    </div>
                    <?php if (!empty($report['source_stock_number'])): ?>
                    <div class="info-item">
                        <span class="info-label">Source Stock</span>
                        <span class="info-value mono"><?php echo htmlspecialchars($report['source_stock_number']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Source Stock Date</span>
                        <span class="info-value"><?php echo date('d M Y', strtotime($report['source_stock_date'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PROVIDERS -->
            <div class="info-section">
                <div class="info-section-title">
                    <i class="fas fa-university"></i>
                    Provider Floats
                </div>
                <table class="provider-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Provider</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th class="text-right">Float Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($providers as $p):
                            $color = $p['color_code'] ?? '#1E40AF';
                            $icon = $p['icon_class'] ?? 'fas fa-university';
                        ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <div class="provider-cell">
                                        <div class="provider-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                            <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                        </div>
                                        <span><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                    </div>
                                </td>
                                <td><span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $p['provider_type'] ?? 'bank')); ?></td>
                                <td class="text-right">
                                    <span class="amount-cell"><?php echo formatCurrency($p['float_balance']); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right">TOTAL FLOAT:</td>
                            <td class="text-right"><?php echo formatCurrency($total_float); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- FINANCIAL SUMMARY -->
            <div class="financial-summary">
                <div class="financial-row">
                    <span class="financial-label"><i class="fas fa-coins"></i> Total Float</span>
                    <span class="financial-value"><?php echo formatCurrency($total_float); ?></span>
                </div>
                <div class="financial-row">
                    <span class="financial-label"><i class="fas fa-plus"></i> Cash Balance</span>
                    <span class="financial-value"><?php echo formatCurrency($cash_balance); ?></span>
                </div>
                <div class="financial-divider"></div>
                <div class="financial-row financial-row-total">
                    <span class="financial-label"><i class="fas fa-equals"></i> CUMM. TOTAL</span>
                    <span class="financial-value"><?php echo formatCurrency($cumm_total); ?></span>
                </div>
            </div>

            <!-- NOTES -->
            <?php if (!empty($report['notes'])): ?>
            <div class="info-section" style="margin-top: 24px;">
                <div class="info-section-title">
                    <i class="fas fa-sticky-note"></i>
                    Notes
                </div>
                <div class="info-item">
                    <span class="info-value" style="font-weight: 500; line-height: 1.6;">
                        <?php echo nl2br(htmlspecialchars($report['notes'])); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <!-- SIGNATURES -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Prepared By</div>
                    <div class="signature-name"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Approved By</div>
                    <div class="signature-name">_____________________</div>
                </div>
            </div>

        </div>

        <!-- FOOTER -->
        <div class="print-footer">
            <div class="footer-content">
                <div class="footer-info">
                    <i class="fas fa-clock"></i>
                    <span>Generated: <?php echo date('d M Y H:i:s'); ?></span>
                </div>
                <div class="footer-info">
                    <i class="fas fa-check-circle"></i>
                    <span>Official Morning Report</span>
                </div>
                <div class="footer-info">
                    <i class="fas fa-shield-alt"></i>
                    <span>Verified</span>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>