<?php
// ================================================================
// FILE: modules/evening_stock/export.php
// WAKALA FINANCIAL SYSTEM - EVENING STOCK EXPORT
// ✅ Export formats: PDF (HTML), CSV, Word (.doc)
// ✅ Blue theme
// ✅ Summary + Details sections
// ✅ Filters support (date range, branch, status)
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);

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
// GET PARAMETERS
// ============================================================
$format        = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
$from_date     = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date       = isset($_GET['to_date'])   ? $_GET['to_date']   : date('Y-m-d');
$status_filter = isset($_GET['status'])    ? $_GET['status']    : '';

$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
    if ($selected_branch < 0) $selected_branch = 0;
}

if (!in_array($format, ['pdf', 'csv', 'word'])) {
    $format = 'csv';
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$branch_display_name = 'All Branches';
$branch_display_code = '';

try {
    if ($selected_branch > 0) {
        $stmt = $db->prepare("SELECT branch_name, branch_code FROM branches WHERE id = ?");
        $stmt->execute([$selected_branch]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $branch_display_name = $b['branch_name'];
            $branch_display_code = $b['branch_code'] ?? '';
        }
    }
} catch (PDOException $e) {
    // silent
}

// ============================================================
// GET DATA
// ============================================================
try {
    $sql = "SELECT es.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            dr.report_number as daily_report_number,
            (SELECT COUNT(*) FROM evening_stock_providers WHERE evening_stock_id = es.id) as providers_count
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN branches b ON es.branch_id = b.id
            LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
            WHERE es.stock_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND es.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($status_filter)) {
        $sql .= " AND es.status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY es.branch_id ASC, es.stock_date DESC, es.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Export error: " . $e->getMessage());
    $stocks = [];
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$totals = [
    'count'       => 0,
    'waiting'     => 0,
    'approved'    => 0,
    'adjusted'    => 0,
    'rejected'    => 0,
    'float'       => 0,
    'cash'        => 0,
    'grand'       => 0,
];

foreach ($stocks as $s) {
    $totals['count']++;
    $totals[$s['status']] = ($totals[$s['status']] ?? 0) + 1;
    $float = floatval($s['cumm_total'] ?? 0);
    $cash  = floatval($s['cash_balance'] ?? 0);
    $totals['float'] += $float;
    $totals['cash']  += $cash;
    $totals['grand'] += ($float + $cash);
}

$status_labels = [
    'waiting'  => 'Waiting',
    'approved' => 'Approved',
    'adjusted' => 'Adjusted',
    'rejected' => 'Rejected'
];

$period_label = date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date));
$generated_at = date('d M Y, H:i:s');
$company_name = 'Wakala Financial System';

// Try to get company name from system_settings
try {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name' LIMIT 1");
    $stmt->execute();
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && !empty($r['setting_value'])) {
        $company_name = $r['setting_value'];
    }
} catch (PDOException $e) {
    // silent
}

$filter_status_label = !empty($status_filter) ? ($status_labels[$status_filter] ?? ucfirst($status_filter)) : 'All Statuses';
$filename_base = 'evening_stock_' . date('Y-m-d_His');

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header info
    fputcsv($output, [$company_name]);
    fputcsv($output, ['Evening Stock Export']);
    fputcsv($output, ['Period:', $period_label]);
    fputcsv($output, ['Branch:', $branch_display_name . ($branch_display_code ? ' (' . $branch_display_code . ')' : '')]);
    fputcsv($output, ['Status:', $filter_status_label]);
    fputcsv($output, ['Generated:', $generated_at]);
    fputcsv($output, ['Total Records:', $totals['count']]);
    fputcsv($output, []);
    
    // Column headers
    fputcsv($output, [
        '#',
        'Stock Number',
        'Stock Date',
        'Branch',
        'Branch Code',
        'Employee',
        'Employee Code',
        'Providers',
        'Float (TSh)',
        'Cash (TSh)',
        'Total (TSh)',
        'Status',
        'Notes',
        'Submitted At'
    ]);
    
    $i = 1;
    foreach ($stocks as $s) {
        $float = floatval($s['cumm_total'] ?? 0);
        $cash  = floatval($s['cash_balance'] ?? 0);
        $total = $float + $cash;
        
        fputcsv($output, [
            $i++,
            $s['stock_number'],
            date('Y-m-d', strtotime($s['stock_date'])),
            $s['branch_name'] ?? 'N/A',
            $s['branch_code'] ?? '-',
            $s['employee_name'] ?? 'N/A',
            $s['employee_code'] ?? '-',
            intval($s['providers_count'] ?? 0),
            number_format($float, 2, '.', ''),
            number_format($cash, 2, '.', ''),
            number_format($total, 2, '.', ''),
            $status_labels[$s['status']] ?? ucfirst($s['status']),
            $s['notes'] ?? '',
            !empty($s['submitted_at']) ? date('Y-m-d H:i:s', strtotime($s['submitted_at'])) : '-'
        ]);
    }
    
    // Totals row
    fputcsv($output, []);
    fputcsv($output, [
        'TOTAL',
        $totals['count'] . ' records',
        '',
        '',
        '',
        '',
        '',
        '',
        number_format($totals['float'], 2, '.', ''),
        number_format($totals['cash'], 2, '.', ''),
        number_format($totals['grand'], 2, '.', ''),
        '',
        '',
        ''
    ]);
    
    // Status summary
    fputcsv($output, []);
    fputcsv($output, ['STATUS SUMMARY']);
    fputcsv($output, ['Waiting:',  $totals['waiting']]);
    fputcsv($output, ['Approved:', $totals['approved']]);
    fputcsv($output, ['Adjusted:', $totals['adjusted']]);
    fputcsv($output, ['Rejected:', $totals['rejected']]);
    
    fclose($output);
    exit();
}

// ============================================================
// WORD EXPORT (.doc - HTML-based)
// ============================================================
if ($format === 'word') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.doc"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:w="urn:schemas-microsoft-com:office:word"
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <title>Evening Stock Export</title>
        <!--[if gte mso 9]>
        <xml>
            <w:WordDocument>
                <w:View>Print</w:View>
                <w:Zoom>90</w:Zoom>
            </w:WordDocument>
        </xml>
        <![endif]-->
        <style>
            @page {
                size: A4 landscape;
                margin: 1cm;
            }
            body {
                font-family: 'Calibri', 'Arial', sans-serif;
                font-size: 10pt;
                color: #1F2937;
                margin: 0;
            }
            .header {
                border-bottom: 3px solid #2563EB;
                padding-bottom: 12px;
                margin-bottom: 16px;
            }
            .company {
                font-size: 18pt;
                font-weight: bold;
                color: #1E40AF;
                margin: 0 0 4px 0;
            }
            .report-title {
                font-size: 14pt;
                font-weight: bold;
                color: #2563EB;
                margin: 0 0 8px 0;
            }
            .meta {
                font-size: 9pt;
                color: #6B7280;
                line-height: 1.6;
            }
            .meta strong { color: #1F2937; }
            .summary-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
            }
            .summary-table td {
                padding: 6px 10px;
                border: 1px solid #DBEAFE;
                font-size: 9pt;
            }
            .summary-label {
                background: #EFF6FF;
                font-weight: bold;
                color: #1E40AF;
                width: 20%;
            }
            .summary-value {
                color: #1F2937;
                font-weight: bold;
            }
            .data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8.5pt;
            }
            .data-table th {
                background: #2563EB;
                color: #FFFFFF;
                padding: 8px 6px;
                text-align: left;
                font-weight: bold;
                font-size: 8pt;
                text-transform: uppercase;
                border: 1px solid #1E40AF;
            }
            .data-table th.text-right { text-align: right; }
            .data-table td {
                padding: 6px 6px;
                border: 1px solid #E5E7EB;
                color: #1F2937;
            }
            .data-table td.text-right { text-align: right; font-family: 'Courier New', monospace; }
            .data-table tr:nth-child(even) td { background: #F9FAFB; }
            .totals-row td {
                background: #FEF3C7 !important;
                font-weight: bold;
                border-top: 2px solid #2563EB;
                padding: 8px 6px;
            }
            .status-badge {
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 7.5pt;
                font-weight: bold;
            }
            .status-waiting  { background: #FEF3C7; color: #92400E; }
            .status-approved { background: #D1FAE5; color: #065F46; }
            .status-adjusted { background: #DBEAFE; color: #1D4ED8; }
            .status-rejected { background: #FEE2E2; color: #991B1B; }
            .footer {
                margin-top: 16px;
                padding-top: 10px;
                border-top: 1px solid #E5E7EB;
                font-size: 8pt;
                color: #9CA3AF;
                text-align: center;
            }
        </style>
    </head>
    <body>
        
        <!-- HEADER -->
        <div class="header">
            <div class="company"><?php echo htmlspecialchars($company_name); ?></div>
            <div class="report-title">📊 Evening Stock Export Report</div>
            <div class="meta">
                <strong>Period:</strong> <?php echo $period_label; ?> &nbsp;|&nbsp;
                <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display_name); ?>
                <?php if ($branch_display_code): ?> (<?php echo htmlspecialchars($branch_display_code); ?>)<?php endif; ?>
                &nbsp;|&nbsp;
                <strong>Status:</strong> <?php echo $filter_status_label; ?>
                <br>
                <strong>Generated:</strong> <?php echo $generated_at; ?> &nbsp;|&nbsp;
                <strong>Total Records:</strong> <?php echo $totals['count']; ?>
            </div>
        </div>
        
        <!-- SUMMARY -->
        <table class="summary-table">
            <tr>
                <td class="summary-label">Total Records</td>
                <td class="summary-value"><?php echo $totals['count']; ?></td>
                <td class="summary-label">Waiting</td>
                <td class="summary-value"><?php echo $totals['waiting']; ?></td>
                <td class="summary-label">Approved</td>
                <td class="summary-value"><?php echo $totals['approved']; ?></td>
                <td class="summary-label">Adjusted</td>
                <td class="summary-value"><?php echo $totals['adjusted']; ?></td>
                <td class="summary-label">Rejected</td>
                <td class="summary-value"><?php echo $totals['rejected']; ?></td>
            </tr>
            <tr>
                <td class="summary-label">Total Float</td>
                <td class="summary-value" colspan="3">TSh <?php echo number_format($totals['float'], 0); ?></td>
                <td class="summary-label">Total Cash</td>
                <td class="summary-value" colspan="3">TSh <?php echo number_format($totals['cash'], 0); ?></td>
                <td class="summary-label">Grand Total</td>
                <td class="summary-value">TSh <?php echo number_format($totals['grand'], 0); ?></td>
            </tr>
        </table>
        
        <!-- DATA TABLE -->
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Stock No.</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Employee</th>
                    <th style="text-align:center;">Providers</th>
                    <th class="text-right">Float</th>
                    <th class="text-right">Cash</th>
                    <th class="text-right">Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stocks)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center; padding:20px; color:#9CA3AF;">
                            No evening stock records found for this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $i = 1;
                    foreach ($stocks as $s): 
                        $float = floatval($s['cumm_total'] ?? 0);
                        $cash  = floatval($s['cash_balance'] ?? 0);
                        $total = $float + $cash;
                        $status = $s['status'] ?? 'waiting';
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($s['stock_number']); ?></td>
                            <td><?php echo date('d M Y', strtotime($s['stock_date'])); ?></td>
                            <td><?php echo htmlspecialchars($s['branch_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($s['employee_name'] ?? 'N/A'); ?></td>
                            <td style="text-align:center;"><?php echo intval($s['providers_count'] ?? 0); ?></td>
                            <td class="text-right"><?php echo number_format($float, 0); ?></td>
                            <td class="text-right"><?php echo number_format($cash, 0); ?></td>
                            <td class="text-right"><strong><?php echo number_format($total, 0); ?></strong></td>
                            <td>
                                <span class="status-badge status-<?php echo htmlspecialchars($status); ?>">
                                    <?php echo $status_labels[$status] ?? ucfirst($status); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <tr class="totals-row">
                        <td colspan="5" style="text-align:right;">GRAND TOTAL (<?php echo $totals['count']; ?> records)</td>
                        <td style="text-align:center;">—</td>
                        <td class="text-right"><strong><?php echo number_format($totals['float'], 0); ?></strong></td>
                        <td class="text-right"><strong><?php echo number_format($totals['cash'], 0); ?></strong></td>
                        <td class="text-right"><strong><?php echo number_format($totals['grand'], 0); ?></strong></td>
                        <td>—</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer">
            Generated by <?php echo htmlspecialchars($company_name); ?> — <?php echo $generated_at; ?>
        </div>
        
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// PDF / PRINT EXPORT (HTML ready for print-to-PDF)
// ============================================================
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Evening Stock Export — <?php echo $period_label; ?></title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                background: #F3F4F6;
                color: #1F2937;
                margin: 0;
                padding: 24px;
                font-size: 12px;
            }
            .container {
                max-width: 1400px;
                margin: 0 auto;
                background: #FFFFFF;
                border-radius: 12px;
                padding: 28px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            }
            
            /* HEADER */
            .header {
                border-bottom: 3px solid #2563EB;
                padding-bottom: 16px;
                margin-bottom: 20px;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 16px;
                flex-wrap: wrap;
            }
            .header-left h1 {
                font-size: 22px;
                font-weight: 900;
                color: #1E3A8A;
                margin: 0 0 4px 0;
                letter-spacing: 0.3px;
            }
            .header-left .subtitle {
                font-size: 13px;
                color: #2563EB;
                font-weight: 700;
                margin: 0 0 8px 0;
            }
            .header-left .meta {
                font-size: 11px;
                color: #6B7280;
                line-height: 1.6;
            }
            .header-left .meta strong { color: #1F2937; }
            .header-right {
                text-align: right;
                font-size: 10px;
                color: #9CA3AF;
                white-space: nowrap;
            }
            .header-right .logo-badge {
                display: inline-block;
                padding: 8px 16px;
                background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
                color: #FFFFFF;
                border-radius: 8px;
                font-weight: 800;
                font-size: 11px;
                letter-spacing: 0.5px;
                margin-bottom: 6px;
            }
            
            /* SUMMARY CARDS */
            .summary-grid {
                display: grid;
                grid-template-columns: repeat(6, 1fr);
                gap: 10px;
                margin-bottom: 20px;
            }
            .summary-card {
                border-radius: 10px;
                padding: 12px 14px;
                border: 1.5px solid;
                position: relative;
                overflow: hidden;
            }
            .summary-card .label {
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                margin-bottom: 4px;
                opacity: 0.85;
            }
            .summary-card .value {
                font-size: 16px;
                font-weight: 900;
                font-family: 'Courier New', monospace;
                line-height: 1.1;
            }
            .sc-total   { background: #EFF6FF; border-color: #93C5FD; color: #1E40AF; }
            .sc-waiting { background: #FEF3C7; border-color: #FCD34D; color: #92400E; }
            .sc-approved{ background: #D1FAE5; border-color: #6EE7B7; color: #065F46; }
            .sc-float   { background: #E0F2FE; border-color: #7DD3FC; color: #075985; }
            .sc-cash    { background: #CCFBF1; border-color: #5EEAD4; color: #115E59; }
            .sc-grand   { background: #CFFAFE; border-color: #67E8F9; color: #155E75; }
            
            /* STATUS FILTER BADGE */
            .filter-info {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 14px;
                background: #F3F4F6;
                border: 1.5px solid #E5E7EB;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                color: #374151;
                margin-bottom: 16px;
            }
            .filter-info i { color: #2563EB; }
            
            /* DATA TABLE */
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10.5px;
                margin-bottom: 16px;
            }
            thead {
                background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
            }
            thead th {
                color: #FFFFFF;
                padding: 10px 8px;
                text-align: left;
                font-weight: 700;
                font-size: 9px;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                white-space: nowrap;
                border-right: 1px solid rgba(255, 255, 255, 0.15);
            }
            thead th:last-child { border-right: none; }
            thead th.text-right { text-align: right; }
            thead th.text-center { text-align: center; }
            
            tbody td {
                padding: 8px;
                border-bottom: 1px solid #E5E7EB;
                color: #1F2937;
                vertical-align: middle;
            }
            tbody tr:nth-child(even) td { background: #F9FAFB; }
            tbody tr:hover td { background: #EFF6FF; }
            tbody td.text-right {
                text-align: right;
                font-family: 'Courier New', monospace;
                font-weight: 700;
            }
            tbody td.text-center { text-align: center; }
            
            .row-num {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                background: #E5E7EB;
                font-size: 10px;
                font-weight: 800;
                color: #374151;
            }
            
            .stock-no {
                font-family: 'Courier New', monospace;
                font-weight: 800;
                color: #1D4ED8;
                background: #DBEAFE;
                padding: 2px 8px;
                border-radius: 6px;
                font-size: 10px;
                display: inline-block;
            }
            
            .branch-tag {
                display: inline-block;
                padding: 2px 8px;
                background: #E0F2FE;
                color: #075985;
                border-radius: 6px;
                font-size: 9px;
                font-weight: 700;
                border: 1px solid #7DD3FC;
            }
            
            .status-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 10px;
                font-size: 9px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }
            .status-waiting  { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
            .status-approved { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
            .status-adjusted { background: #DBEAFE; color: #1D4ED8; border: 1px solid #93C5FD; }
            .status-rejected { background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; }
            
            .total-amount {
                font-weight: 900;
                color: #1D4ED8;
                font-size: 11px;
            }
            
            .totals-row td {
                background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%) !important;
                font-weight: 900;
                color: #78350F;
                border-top: 3px solid #2563EB;
                border-bottom: none;
                padding: 12px 8px;
                font-size: 11px;
            }
            
            /* FOOTER */
            .footer {
                margin-top: 24px;
                padding-top: 16px;
                border-top: 2px solid #E5E7EB;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 10px;
                color: #9CA3AF;
                flex-wrap: wrap;
                gap: 10px;
            }
            .footer strong { color: #374151; }
            
            /* PRINT BUTTON */
            .print-section {
                text-align: center;
                margin-top: 24px;
                padding-top: 20px;
                border-top: 1px solid #E5E7EB;
            }
            .btn-print {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 14px 32px;
                background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
                color: #FFFFFF;
                border: none;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 800;
                cursor: pointer;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                box-shadow: 0 4px 16px rgba(37, 99, 235, 0.4);
                transition: all 0.3s ease;
                font-family: inherit;
            }
            .btn-print:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 24px rgba(37, 99, 235, 0.55);
                background: linear-gradient(135deg, #1D4ED8 0%, #1E40AF 100%);
            }
            .print-hint {
                margin-top: 10px;
                font-size: 11px;
                color: #6B7280;
            }
            .print-hint kbd {
                background: #F3F4F6;
                border: 1px solid #D1D5DB;
                border-radius: 4px;
                padding: 2px 6px;
                font-family: monospace;
                font-size: 10px;
                color: #374151;
            }
            
            /* PRINT STYLES */
            @media print {
                body {
                    background: #FFFFFF;
                    padding: 0;
                    font-size: 10px;
                }
                .container {
                    box-shadow: none;
                    border-radius: 0;
                    padding: 0;
                    max-width: 100%;
                }
                .print-section,
                .header-right .logo-badge {
                    display: none !important;
                }
                .summary-grid {
                    break-inside: avoid;
                }
                table {
                    font-size: 9px;
                }
                thead {
                    display: table-header-group;
                }
                tr {
                    break-inside: avoid;
                }
                .header {
                    border-bottom-color: #2563EB;
                }
            }
            
            /* RESPONSIVE */
            @media (max-width: 1200px) {
                .summary-grid { grid-template-columns: repeat(3, 1fr); }
            }
            @media (max-width: 768px) {
                body { padding: 12px; }
                .container { padding: 16px; }
                .summary-grid { grid-template-columns: repeat(2, 1fr); }
                .header { flex-direction: column; }
                .header-right { text-align: left; }
                table { font-size: 9px; }
                thead th, tbody td { padding: 6px 4px; }
            }
            @media (max-width: 480px) {
                .summary-grid { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
        
        <div class="container">
            
            <!-- HEADER -->
            <div class="header">
                <div class="header-left">
                    <h1><?php echo htmlspecialchars($company_name); ?></h1>
                    <p class="subtitle">🌙 Evening Stock Report</p>
                    <div class="meta">
                        <strong>Period:</strong> <?php echo $period_label; ?>
                        &nbsp;•&nbsp;
                        <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display_name); ?>
                        <?php if ($branch_display_code): ?>(<?php echo htmlspecialchars($branch_display_code); ?>)<?php endif; ?>
                        &nbsp;•&nbsp;
                        <strong>Records:</strong> <?php echo $totals['count']; ?>
                        <br>
                        <strong>Generated:</strong> <?php echo $generated_at; ?>
                    </div>
                </div>
                <div class="header-right">
                    <div class="logo-badge">EVE-STOCK REPORT</div>
                    <div>Document ID: <?php echo strtoupper(substr(md5($filename_base), 0, 8)); ?></div>
                </div>
            </div>
            
            <!-- SUMMARY CARDS -->
            <div class="summary-grid">
                <div class="summary-card sc-total">
                    <div class="label">Total Records</div>
                    <div class="value"><?php echo $totals['count']; ?></div>
                </div>
                <div class="summary-card sc-waiting">
                    <div class="label">Waiting</div>
                    <div class="value"><?php echo $totals['waiting']; ?></div>
                </div>
                <div class="summary-card sc-approved">
                    <div class="label">Approved</div>
                    <div class="value"><?php echo $totals['approved']; ?></div>
                </div>
                <div class="summary-card sc-float">
                    <div class="label">Total Float</div>
                    <div class="value">TSh <?php echo number_format($totals['float'], 0); ?></div>
                </div>
                <div class="summary-card sc-cash">
                    <div class="label">Total Cash</div>
                    <div class="value">TSh <?php echo number_format($totals['cash'], 0); ?></div>
                </div>
                <div class="summary-card sc-grand">
                    <div class="label">Grand Total</div>
                    <div class="value">TSh <?php echo number_format($totals['grand'], 0); ?></div>
                </div>
            </div>
            
            <!-- FILTER INFO -->
            <div class="filter-info">
                <i class="fas fa-filter"></i>
                <span>Filter: <strong><?php echo $filter_status_label; ?></strong></span>
            </div>
            
            <!-- DATA TABLE -->
            <table>
                <thead>
                    <tr>
                        <th style="width: 36px;">#</th>
                        <th>Stock No.</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Employee</th>
                        <th class="text-center" style="width: 60px;">Prov.</th>
                        <th class="text-right">Float</th>
                        <th class="text-right">Cash</th>
                        <th class="text-right">Total</th>
                        <th style="width: 90px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stocks)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:30px; color:#9CA3AF; font-style:italic;">
                                No evening stock records found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $i = 1;
                        foreach ($stocks as $s): 
                            $float = floatval($s['cumm_total'] ?? 0);
                            $cash  = floatval($s['cash_balance'] ?? 0);
                            $total = $float + $cash;
                            $status = $s['status'] ?? 'waiting';
                        ?>
                            <tr>
                                <td><span class="row-num"><?php echo $i++; ?></span></td>
                                <td><span class="stock-no"><?php echo htmlspecialchars($s['stock_number']); ?></span></td>
                                <td style="white-space:nowrap; font-weight:600;">
                                    <?php echo date('d M Y', strtotime($s['stock_date'])); ?>
                                </td>
                                <td>
                                    <span class="branch-tag">
                                        <?php echo htmlspecialchars($s['branch_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($s['employee_name'] ?? 'N/A'); ?></td>
                                <td class="text-center"><?php echo intval($s['providers_count'] ?? 0); ?></td>
                                <td class="text-right"><?php echo number_format($float, 0); ?></td>
                                <td class="text-right"><?php echo number_format($cash, 0); ?></td>
                                <td class="text-right total-amount"><?php echo number_format($total, 0); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($status); ?>">
                                        <?php echo $status_labels[$status] ?? ucfirst($status); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr class="totals-row">
                            <td colspan="6" style="text-align:right; padding-right:16px;">
                                GRAND TOTAL — <?php echo $totals['count']; ?> records
                            </td>
                            <td class="text-right"><?php echo number_format($totals['float'], 0); ?></td>
                            <td class="text-right"><?php echo number_format($totals['cash'], 0); ?></td>
                            <td class="text-right"><?php echo number_format($totals['grand'], 0); ?></td>
                            <td>—</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- FOOTER -->
            <div class="footer">
                <div>
                    <strong><?php echo htmlspecialchars($company_name); ?></strong> — Evening Stock Report
                </div>
                <div>
                    Generated by Wakala Financial System on <strong><?php echo $generated_at; ?></strong>
                </div>
            </div>
            
            <!-- PRINT BUTTON -->
            <div class="print-section">
                <button class="btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    Print / Save as PDF
                </button>
                <div class="print-hint">
                    Tip: Bonyeza <kbd>Ctrl</kbd> + <kbd>P</kbd> kwa haraka, kisha chagua <strong>"Save as PDF"</strong>
                </div>
            </div>
            
        </div>
        
        <!-- Font Awesome for icons -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        
        <script>
            // Auto-open print dialog for PDF export
            // Comment the line below kama hutaki auto-print
            // window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 800); });
            
            // Keyboard shortcut: Ctrl+P to print
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                    e.preventDefault();
                    window.print();
                }
            });
        </script>
        
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// FALLBACK: Redirect to index
// ============================================================
header('Location: index.php');
exit();
?>