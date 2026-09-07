<?php
// ================================================================
// FILE: modules/morning_report/export.php
// WAKALA FINANCIAL SYSTEM - EXPORT MORNING REPORTS
// WITH LOGO SUPPORT
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
$role = $_SESSION['role'] ?? 'employee';

// ============================================================
// GET PARAMETERS
// ============================================================
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'pdf';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$branch_id = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

// ============================================================
// GET COMPANY SETTINGS
// ============================================================
$company_name = 'Wakala Financial System';
$company_address = 'Dar es Salaam, Tanzania';
$company_phone = '+255 700 000 000';
$company_email = 'info@wakala.com';

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
// GET LOGO - Convert to Base64
// ============================================================
$logo_base64 = '';
$logo_paths = [
    '../../assets/images/logo.PNG',
    '../assets/images/logo.PNG',
    'assets/images/logo.PNG',
    '../../assets/images/logo.png',
    '../assets/images/logo.png',
    'assets/images/logo.png'
];

foreach ($logo_paths as $path) {
    if (file_exists($path)) {
        $logo_data = file_get_contents($path);
        $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
        break;
    }
}

// ============================================================
// BUILD QUERY
// ============================================================
$sql = "SELECT 
            mr.*,
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code
        FROM morning_reports mr
        LEFT JOIN employees e ON mr.employee_id = e.id
        LEFT JOIN branches b ON mr.branch_id = b.id
        WHERE DATE(mr.report_date) BETWEEN ? AND ?";
$params = [$from_date, $to_date];

if ($branch_id > 0) {
    $sql .= " AND mr.branch_id = ?";
    $params[] = $branch_id;
}

if ($role === 'employee') {
    $sql .= " AND mr.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY mr.report_date DESC, mr.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_float = 0;
$total_cash = 0;
$total_grand = 0;
$total_reports = count($reports);

foreach ($reports as $r) {
    $total_float += floatval($r['cumm_total'] ?? 0);
    $total_cash += floatval($r['cash_balance'] ?? 0);
}
$total_grand = $total_float + $total_cash;

// ============================================================
// GET BRANCH NAME
// ============================================================
$branch_name_display = 'All Branches';
if ($branch_id > 0) {
    $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
    $stmt->execute([$branch_id]);
    $b = $stmt->fetch();
    if ($b) {
        $branch_name_display = $b['branch_name'];
    }
}

// ============================================================
// EXPORT FUNCTIONS
// ============================================================
if ($format === 'csv') {
    exportCSV($reports);
} elseif ($format === 'excel') {
    exportExcel($reports, $company_name);
} elseif ($format === 'pdf') {
    exportPDF($reports, $company_name, $company_address, $company_phone, $company_email, $from_date, $to_date, $branch_name_display, $total_float, $total_cash, $total_grand, $logo_base64);
} elseif ($format === 'print') {
    exportPrint($reports, $company_name, $company_address, $company_phone, $company_email, $from_date, $to_date, $branch_name_display, $total_float, $total_cash, $total_grand, $logo_base64);
} else {
    die('Invalid export format.');
}

// ============================================================
// EXPORT CSV
// ============================================================
function exportCSV($reports) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="morning_reports_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Report No', 'Date', 'Branch', 'Employee', 'Total Float', 'Cash Balance', 'Grand Total', 'Notes']);
    
    foreach ($reports as $r) {
        fputcsv($output, [
            $r['report_number'],
            date('d-m-Y', strtotime($r['report_date'])),
            $r['branch_name'] ?? 'Main',
            $r['employee_name'] ?? 'N/A',
            number_format($r['cumm_total'] ?? 0, 2),
            number_format($r['cash_balance'] ?? 0, 2),
            number_format((floatval($r['cumm_total'] ?? 0) + floatval($r['cash_balance'] ?? 0)), 2),
            $r['notes'] ?? ''
        ]);
    }
    
    fclose($output);
    exit();
}

// ============================================================
// EXPORT EXCEL
// ============================================================
function exportExcel($reports, $company_name) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="morning_reports_' . date('Y-m-d') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>Morning Reports Export</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; padding: 20px; }';
    echo 'h1 { color: #DC2626; }';
    echo 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    echo 'th { background: #DC2626; color: #FFFFFF; padding: 10px; text-align: left; }';
    echo 'td { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }';
    echo '.total-row { background: #FEF3C7; font-weight: bold; }';
    echo '</style>';
    echo '</head><body>';
    
    echo '<h1>' . htmlspecialchars($company_name) . '</h1>';
    echo '<h2>Morning Reports Export</h2>';
    echo '<p>Generated: ' . date('d M Y, H:i:s') . '</p>';
    echo '<hr>';
    
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>#</th>';
    echo '<th>Report No</th>';
    echo '<th>Date</th>';
    echo '<th>Branch</th>';
    echo '<th>Employee</th>';
    echo '<th>Total Float</th>';
    echo '<th>Cash Balance</th>';
    echo '<th>Grand Total</th>';
    echo '</tr></thead><tbody>';
    
    $counter = 1;
    $total_float = 0;
    $total_cash = 0;
    $total_grand = 0;
    
    foreach ($reports as $r) {
        $float = floatval($r['cumm_total'] ?? 0);
        $cash = floatval($r['cash_balance'] ?? 0);
        $grand = $float + $cash;
        $total_float += $float;
        $total_cash += $cash;
        $total_grand += $grand;
        
        echo '<tr>';
        echo '<td>' . $counter++ . '</td>';
        echo '<td>' . htmlspecialchars($r['report_number']) . '</td>';
        echo '<td>' . date('d-m-Y', strtotime($r['report_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['branch_name'] ?? 'Main') . '</td>';
        echo '<td>' . htmlspecialchars($r['employee_name'] ?? 'N/A') . '</td>';
        echo '<td>' . number_format($float, 2) . '</td>';
        echo '<td>' . number_format($cash, 2) . '</td>';
        echo '<td><strong>' . number_format($grand, 2) . '</strong></td>';
        echo '</tr>';
    }
    
    echo '<tr class="total-row">';
    echo '<td colspan="5" style="text-align:right;"><strong>TOTALS</strong></td>';
    echo '<td><strong>' . number_format($total_float, 2) . '</strong></td>';
    echo '<td><strong>' . number_format($total_cash, 2) . '</strong></td>';
    echo '<td><strong>' . number_format($total_grand, 2) . '</strong></td>';
    echo '</tr>';
    
    echo '</tbody></table>';
    echo '<p style="margin-top:20px; color:#6B7280; font-size:12px;">Total Reports: ' . count($reports) . ' | Generated by ' . htmlspecialchars($company_name) . '</p>';
    echo '</body></html>';
    exit();
}

// ============================================================
// EXPORT PDF (Using HTML to PDF with print)
// ============================================================
function exportPDF($reports, $company_name, $company_address, $company_phone, $company_email, $from_date, $to_date, $branch_name, $total_float, $total_cash, $total_grand, $logo_base64) {
    // For PDF, we'll use HTML with print styles
    // User can "Save as PDF" from print dialog
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Morning Reports Export - PDF</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; padding: 30px; color: #1F2937; }
        .report-container { max-width: 1100px; margin: 0 auto; }
        
        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #DC2626; padding-bottom: 20px; margin-bottom: 24px; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-logo { width: 60px; height: 60px; border-radius: 50%; overflow: hidden; border: 3px solid #DC2626; background: white; display: flex; align-items: center; justify-content: center; }
        .header-logo img { width: 100%; height: 100%; object-fit: cover; }
        .header-logo .logo-placeholder { width: 100%; height: 100%; background: #DC2626; display: flex; align-items: center; justify-content: center; color: white; font-size: 28px; font-weight: 800; }
        .header-title h1 { font-size: 24px; font-weight: 700; color: #1F2937; }
        .header-title .subtitle { font-size: 14px; color: #6B7280; }
        .header-right { text-align: right; }
        .header-right .company-name { font-size: 16px; font-weight: 700; }
        .header-right .company-detail { font-size: 12px; color: #6B7280; display: block; }
        
        /* Report Info */
        .report-info { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 16px 20px; background: #F9FAFB; border-radius: 8px; margin-bottom: 24px; border: 1px solid #E5E7EB; }
        .info-item { display: flex; flex-direction: column; }
        .info-label { font-size: 11px; text-transform: uppercase; color: #6B7280; font-weight: 600; }
        .info-value { font-size: 14px; font-weight: 600; }
        
        /* Table */
        .table-container { margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead { background: #DC2626; }
        thead th { padding: 10px 12px; text-align: left; color: white; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        tbody tr { border-bottom: 1px solid #E5E7EB; }
        tbody td { padding: 8px 12px; }
        tbody tr:hover { background: #F9FAFB; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .total-row { background: #FEF3C7; font-weight: bold; }
        .grand-total { background: #D1FAE5; font-weight: bold; }
        .grand-total td { border-top: 2px solid #10B981; padding: 10px 12px; }
        .grand-total .grand-amount { color: #10B981; font-size: 16px; }
        
        /* Totals Summary */
        .totals-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; padding: 16px 20px; background: #F9FAFB; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 24px; }
        .total-box { text-align: center; }
        .total-box .label { font-size: 12px; text-transform: uppercase; color: #6B7280; }
        .total-box .value { font-size: 20px; font-weight: 800; }
        .total-box.total-float .value { color: #1D4ED8; }
        .total-box.total-cash .value { color: #065F46; }
        .total-box.total-grand .value { color: #D97706; }
        
        /* Footer */
        .footer { margin-top: 30px; padding-top: 20px; border-top: 2px solid #E5E7EB; display: flex; justify-content: space-between; font-size: 12px; color: #6B7280; }
        .footer .signatures { display: flex; gap: 40px; }
        .footer .signature-line { text-align: center; }
        .footer .signature-line .line { width: 120px; border-bottom: 1px solid #1F2937; margin: 0 auto 4px; }
        
        /* Print */
        @media print {
            body { padding: 20px; }
            .no-print { display: none !important; }
            .report-container { max-width: 100%; }
            .header-logo { width: 50px; height: 50px; }
        }
        
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .header-right { text-align: left; width: 100%; }
            .report-info { grid-template-columns: 1fr 1fr; }
            .totals-summary { grid-template-columns: 1fr; }
            .footer { flex-direction: column; gap: 16px; }
            .footer .signatures { flex-direction: column; gap: 12px; }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <!-- Header with Logo -->
        <div class="header">
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
                    <h1>Morning Reports</h1>
                    <div class="subtitle"><?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?></div>
                </div>
            </div>
            <div class="header-right">
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                <span class="company-detail"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($company_address); ?></span>
                <span class="company-detail"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($company_phone); ?></span>
                <span class="company-detail"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($company_email); ?></span>
            </div>
        </div>
        
        <!-- Report Info -->
        <div class="report-info">
            <div class="info-item">
                <span class="info-label">Period</span>
                <span class="info-value"><?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Branch</span>
                <span class="info-value"><?php echo htmlspecialchars($branch_name); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Total Reports</span>
                <span class="info-value"><?php echo count($reports); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Generated</span>
                <span class="info-value"><?php echo date('d M Y, H:i'); ?></span>
            </div>
        </div>
        
        <!-- Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Report No</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Employee</th>
                        <th class="text-right">Total Float</th>
                        <th class="text-right">Cash Balance</th>
                        <th class="text-right">Grand Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:30px; color:#6B7280;">
                                No morning reports found for the selected period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $counter = 1; ?>
                        <?php foreach ($reports as $r): 
                            $float = floatval($r['cumm_total'] ?? 0);
                            $cash = floatval($r['cash_balance'] ?? 0);
                            $grand = $float + $cash;
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo htmlspecialchars($r['report_number']); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($r['report_date'])); ?></td>
                                <td><?php echo htmlspecialchars($r['branch_name'] ?? 'Main'); ?></td>
                                <td><?php echo htmlspecialchars($r['employee_name'] ?? 'N/A'); ?></td>
                                <td class="text-right"><?php echo number_format($float, 2); ?></td>
                                <td class="text-right"><?php echo number_format($cash, 2); ?></td>
                                <td class="text-right font-bold"><?php echo number_format($grand, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Totals Summary -->
        <?php if (!empty($reports)): ?>
            <div class="totals-summary">
                <div class="total-box total-float">
                    <div class="label">Total Float</div>
                    <div class="value"><?php echo number_format($total_float, 2); ?></div>
                </div>
                <div class="total-box total-cash">
                    <div class="label">Total Cash Balance</div>
                    <div class="value"><?php echo number_format($total_cash, 2); ?></div>
                </div>
                <div class="total-box total-grand">
                    <div class="label">Grand Total</div>
                    <div class="value"><?php echo number_format($total_grand, 2); ?></div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="footer">
            <div>
                <span>Generated by <?php echo htmlspecialchars($company_name); ?></span>
                <span style="margin-left:16px;">| Version 2.0.0</span>
            </div>
            <div class="signatures">
                <div class="signature-line">
                    <div class="line"></div>
                    <div class="label">Prepared By</div>
                </div>
                <div class="signature-line">
                    <div class="line"></div>
                    <div class="label">Approved By</div>
                </div>
                <div class="signature-line">
                    <div class="line"></div>
                    <div class="label">Date</div>
                </div>
            </div>
        </div>
        
        <!-- Print Button -->
        <div style="margin-top:20px; text-align:center;" class="no-print">
            <button onclick="window.print()" style="background:#DC2626; color:white; border:none; padding:12px 30px; border-radius:8px; font-size:16px; font-weight:600; cursor:pointer;">
                <i class="fas fa-print"></i> Print / Save as PDF
            </button>
            <a href="index.php" style="display:inline-block; margin-left:12px; background:#6B7280; color:white; padding:12px 30px; border-radius:8px; font-size:16px; font-weight:600; text-decoration:none;">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
    
    <script>
        // Auto-print if print=1 parameter is set
        if (window.location.search.includes('print=1')) {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>
    <?php
    exit();
}

// ============================================================
// EXPORT PRINT
// ============================================================
function exportPrint($reports, $company_name, $company_address, $company_phone, $company_email, $from_date, $to_date, $branch_name, $total_float, $total_cash, $total_grand, $logo_base64) {
    // Same as PDF but with print dialog
    exportPDF($reports, $company_name, $company_address, $company_phone, $company_email, $from_date, $to_date, $branch_name, $total_float, $total_cash, $total_grand, $logo_base64);
}
?>