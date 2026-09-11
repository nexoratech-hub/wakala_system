<?php
// ================================================================
// FILE: modules/capital_management/export.php
// EXPORT CAPITAL TRANSACTIONS
// ✅ FIXED: Support BOTH 'branch' AND 'branch_id' from URL
// ✅ NEW: Logo included in PDF/Print/Excel exports
// ✅ FIXED: Close button now works properly (with fallback)
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

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// ============================================================
// ✅ FIXED: Support BOTH 'branch' AND 'branch_id'
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

// ============================================================
// GET COMPANY INFO
// ============================================================
$company_name = 'Wakala System';
$company_address = '';
$company_phone = '';
$company_email = '';

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings as $s) {
        if ($s['setting_key'] === 'company_name') $company_name = $s['setting_value'];
        if ($s['setting_key'] === 'company_address') $company_address = $s['setting_value'];
        if ($s['setting_key'] === 'company_phone') $company_phone = $s['setting_value'];
        if ($s['setting_key'] === 'company_email') $company_email = $s['setting_value'];
    }
} catch (Exception $e) {
    // Use defaults
}

// ============================================================
// GET SELECTED BRANCH INFO
// ============================================================
$branch_info = null;
if ($selected_branch > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
        $stmt->execute([$selected_branch]);
        $branch_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ============================================================
// BUILD QUERY
// ============================================================
try {
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            p.provider_name,
            p.icon_class as provider_icon,
            p.color_code as provider_color,
            bp.provider_code as branch_provider_code
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
            LEFT JOIN providers p ON cm.reference_id = p.id AND cm.reference_module = 'provider'
            LEFT JOIN branch_providers bp ON bp.branch_id = cm.branch_id AND bp.provider_id = p.id
            WHERE cm.transaction_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND cm.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($type_filter)) {
        $sql .= " AND cm.transaction_type = ?";
        $params[] = $type_filter;
    }

    $sql .= " ORDER BY cm.transaction_date ASC, cm.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error exporting transactions: " . $e->getMessage());
    $transactions = [];
}

// ============================================================
// TYPE LABELS
// ============================================================
$type_labels = [
    'opening' => 'Opening',
    'additional' => 'Additional',
    'profit_allocation' => 'Profit Allocation',
    'cash_out' => 'Cash Out',
    'adjustment' => 'Adjustment'
];

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_in = 0;
$total_out = 0;
$total_float = 0;
$total_cash = 0;

foreach ($transactions as $t) {
    $is_out = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
    $amount = floatval($t['amount']);
    $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
    
    if ($is_out) {
        $total_out += $amount;
    } else {
        $total_in += $amount;
        if ($is_float) $total_float += $amount;
        else $total_cash += $amount;
    }
}
$net_capital = $total_in - $total_out;

// ============================================================
// LOGO PATH - Base64 encode for PDF/Print/Excel
// ============================================================
$logo_path = '../../assets/images/logo.PNG';
$logo_data_uri = '';

if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_data);
}

// ============================================================
// CSV EXPORT
// ============================================================
if ($format == 'csv') {
    $filename = 'capital_export_' . date('Y-m-d') . '.csv';
    if ($branch_info) {
        $filename = 'capital_' . strtolower(str_replace(' ', '_', $branch_info['branch_name'])) . '_' . date('Y-m-d') . '.csv';
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Company info
    fputcsv($output, [$company_name]);
    if ($company_address) fputcsv($output, [$company_address]);
    if ($company_phone) fputcsv($output, ['Phone: ' . $company_phone]);
    if ($company_email) fputcsv($output, ['Email: ' . $company_email]);
    fputcsv($output, []);
    fputcsv($output, ['Capital Transactions Report']);
    
    if ($branch_info) {
        fputcsv($output, ['Branch: ' . $branch_info['branch_name'] . ' (' . $branch_info['branch_code'] . ')']);
    } else {
        fputcsv($output, ['Branch: All Branches']);
    }
    
    fputcsv($output, ['Period: ' . date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date))]);
    fputcsv($output, ['Generated: ' . date('d M Y H:i:s')]);
    fputcsv($output, []);
    
    // Headers
    fputcsv($output, [
        '#', 'Capital No.', 'Date', 'Type', 'Source', 
        'Provider/Code', 'Amount', 'Branch', 'Employee', 'Description', 'Notes'
    ]);
    
    $counter = 1;
    foreach ($transactions as $t) {
        $is_out = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
        $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
        
        $source = $is_float ? 'Float' : 'Cash';
        $provider_info = '';
        if ($is_float) {
            $provider_info = $t['provider_name'] . ' (' . ($t['branch_provider_code'] ?? 'N/A') . ')';
        }
        
        fputcsv($output, [
            $counter++,
            $t['capital_number'],
            date('Y-m-d', strtotime($t['transaction_date'])),
            $type_labels[$t['transaction_type']] ?? $t['transaction_type'],
            $source,
            $provider_info,
            ($is_out ? '-' : '+') . number_format($t['amount'], 2),
            $t['branch_name'] ?? 'Main',
            $t['employee_name'] ?? 'N/A',
            $t['description'] ?? '',
            $t['notes'] ?? ''
        ]);
    }
    
    // Summary
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total In:', '', '', '', '', '', '+' . number_format($total_in, 2)]);
    fputcsv($output, ['Total Out:', '', '', '', '', '', '-' . number_format($total_out, 2)]);
    fputcsv($output, ['Net Capital:', '', '', '', '', '', number_format($net_capital, 2)]);
    fputcsv($output, ['Float In:', '', '', '', '', '', number_format($total_float, 2)]);
    fputcsv($output, ['Cash In:', '', '', '', '', '', number_format($total_cash, 2)]);
    fputcsv($output, ['Total Records:', '', '', '', '', '', count($transactions)]);
    
    fclose($output);
    exit();
    
} 
// ============================================================
// EXCEL EXPORT
// ============================================================
elseif ($format == 'excel') {
    $filename = 'capital_export_' . date('Y-m-d') . '.xls';
    if ($branch_info) {
        $filename = 'capital_' . strtolower(str_replace(' ', '_', $branch_info['branch_name'])) . '_' . date('Y-m-d') . '.xls';
    }
    
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Capital Export</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 10px; }
            .header-table { width: 100%; border: none; margin-bottom: 15px; }
            .header-table td { border: none; vertical-align: middle; padding: 5px; }
            .logo-cell { width: 80px; }
            .logo-img { width: 70px; height: 70px; border-radius: 50%; }
            .company-name { font-size: 20px; font-weight: bold; color: #bb0404; margin: 0; }
            .company-info { font-size: 11px; color: #666; margin: 2px 0; }
            .report-title { font-size: 14px; font-weight: bold; color: #333; margin: 4px 0 0 0; }
            table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
            table.data th { 
                background: #bb0404; 
                color: white; 
                padding: 8px; 
                text-align: left; 
                font-size: 11px;
                border: 1px solid #8a0303;
            }
            table.data td { 
                padding: 6px; 
                border: 1px solid #ccc; 
                font-size: 11px;
            }
            table.data tr:nth-child(even) { background: #f9f9f9; }
            .text-right { text-align: right; }
            .text-success { color: #059669; font-weight: bold; }
            .text-danger { color: #DC2626; font-weight: bold; }
            .summary-table { 
                margin-top: 15px; 
                border-collapse: collapse; 
                width: 300px;
                margin-left: auto;
            }
            .summary-table td { 
                padding: 6px 10px; 
                border: 1px solid #ccc; 
                font-size: 12px;
            }
            .summary-table .label { font-weight: bold; background: #f3f4f6; }
            .summary-table .total-row { background: #DBEAFE; font-weight: bold; font-size: 13px; }
        </style>
    </head>
    <body>
        <!-- Header with Logo -->
        <table class="header-table">
            <tr>
                <?php if ($logo_data_uri): ?>
                <td class="logo-cell">
                    <img src="<?php echo $logo_data_uri; ?>" class="logo-img" alt="Logo">
                </td>
                <?php endif; ?>
                <td>
                    <p class="company-name"><?php echo htmlspecialchars($company_name); ?></p>
                    <?php if ($company_address): ?>
                        <p class="company-info"><i>Address:</i> <?php echo htmlspecialchars($company_address); ?></p>
                    <?php endif; ?>
                    <?php if ($company_phone || $company_email): ?>
                        <p class="company-info">
                            <?php if ($company_phone): ?>Phone: <?php echo htmlspecialchars($company_phone); ?><?php endif; ?>
                            <?php if ($company_phone && $company_email): ?> | <?php endif; ?>
                            <?php if ($company_email): ?>Email: <?php echo htmlspecialchars($company_email); ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <p class="report-title">CAPITAL TRANSACTIONS REPORT</p>
                </td>
            </tr>
        </table>
        
        <!-- Report Info -->
        <table style="width: 100%; border: none; margin-bottom: 10px; font-size: 12px;">
            <tr>
                <td style="border: none; padding: 2px 0;">
                    <strong>Branch:</strong> 
                    <?php if ($branch_info): ?>
                        <?php echo htmlspecialchars($branch_info['branch_name']); ?> 
                        (<?php echo htmlspecialchars($branch_info['branch_code']); ?>)
                    <?php else: ?>
                        All Branches
                    <?php endif; ?>
                </td>
                <td style="border: none; padding: 2px 0; text-align: right;">
                    <strong>Generated:</strong> <?php echo date('d M Y H:i'); ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 2px 0;">
                    <strong>Period:</strong> 
                    <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                </td>
                <td style="border: none; padding: 2px 0; text-align: right;">
                    <strong>Records:</strong> <?php echo count($transactions); ?>
                </td>
            </tr>
        </table>
        
        <!-- Data Table -->
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Capital No.</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Provider/Code</th>
                    <th class="text-right">Amount</th>
                    <th>Branch</th>
                    <th>Employee</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; ?>
                <?php foreach ($transactions as $t): 
                    $is_out = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
                    $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
                    $source = $is_float ? 'Float' : 'Cash';
                    $provider_info = '';
                    if ($is_float) {
                        $provider_info = $t['provider_name'] . ' (' . ($t['branch_provider_code'] ?? 'N/A') . ')';
                    }
                ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($t['capital_number']); ?></td>
                        <td><?php echo date('d M Y', strtotime($t['transaction_date'])); ?></td>
                        <td><?php echo $type_labels[$t['transaction_type']] ?? $t['transaction_type']; ?></td>
                        <td><?php echo $source; ?></td>
                        <td><?php echo htmlspecialchars($provider_info); ?></td>
                        <td class="text-right <?php echo $is_out ? 'text-danger' : 'text-success'; ?>">
                            <?php echo ($is_out ? '-' : '+') . ' ' . formatCurrency($t['amount']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($t['branch_name'] ?? 'Main'); ?></td>
                        <td><?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars(substr($t['description'] ?? '', 0, 50)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Summary -->
        <table class="summary-table">
            <tr>
                <td class="label">Total In:</td>
                <td class="text-right text-success">+ <?php echo formatCurrency($total_in); ?></td>
            </tr>
            <tr>
                <td class="label">Total Out:</td>
                <td class="text-right text-danger">- <?php echo formatCurrency($total_out); ?></td>
            </tr>
            <tr>
                <td class="label">Float In:</td>
                <td class="text-right"><?php echo formatCurrency($total_float); ?></td>
            </tr>
            <tr>
                <td class="label">Cash In:</td>
                <td class="text-right"><?php echo formatCurrency($total_cash); ?></td>
            </tr>
            <tr class="total-row">
                <td class="label">Net Capital:</td>
                <td class="text-right"><?php echo formatCurrency($net_capital); ?></td>
            </tr>
        </table>
        
        <p style="margin-top: 20px; font-size: 10px; color: #999; text-align: center;">
            Generated by <?php echo htmlspecialchars($company_name); ?> Management System on <?php echo date('d M Y H:i:s'); ?>
        </p>
    </body>
    </html>
    <?php
    exit();
    
} 
// ============================================================
// PDF / PRINT EXPORT
// ============================================================
elseif ($format == 'pdf' || $format == 'print') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Capital Transactions Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Inter', 'Segoe UI', Arial, sans-serif; 
                padding: 20px; 
                color: #333;
                font-size: 12px;
            }
            
            /* Header */
            .report-header {
                display: flex;
                align-items: center;
                gap: 20px;
                padding-bottom: 15px;
                border-bottom: 3px solid #bb0404;
                margin-bottom: 15px;
            }
            .report-header .logo {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                border: 3px solid #bb0404;
                object-fit: cover;
                flex-shrink: 0;
            }
            .report-header .company-info {
                flex: 1;
            }
            .report-header .company-name {
                font-size: 22px;
                font-weight: 800;
                color: #bb0404;
                margin-bottom: 3px;
            }
            .report-header .company-details {
                font-size: 11px;
                color: #666;
                line-height: 1.5;
            }
            .report-header .report-title {
                font-size: 14px;
                font-weight: 700;
                color: #333;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-top: 6px;
            }
            
            /* Meta Info */
            .report-meta {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 20px;
                padding: 12px 16px;
                background: #f9fafb;
                border-radius: 8px;
                border-left: 4px solid #bb0404;
                margin-bottom: 15px;
                font-size: 12px;
            }
            .report-meta .meta-item {
                display: flex;
                gap: 6px;
            }
            .report-meta .meta-label {
                font-weight: 600;
                color: #666;
            }
            .report-meta .meta-value {
                font-weight: 500;
                color: #333;
            }
            
            /* Data Table */
            table.data-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
                font-size: 11px;
            }
            table.data-table thead {
                background: #bb0404;
            }
            table.data-table thead th {
                color: #FFFFFF;
                padding: 8px 6px;
                text-align: left;
                font-weight: 700;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border: 1px solid #8a0303;
            }
            table.data-table tbody td {
                padding: 6px;
                border: 1px solid #e5e7eb;
                vertical-align: top;
            }
            table.data-table tbody tr:nth-child(even) {
                background: #f9fafb;
            }
            table.data-table tbody tr:hover {
                background: #fef3c7;
            }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .text-success { color: #059669; font-weight: 700; }
            .text-danger { color: #DC2626; font-weight: 700; }
            .font-bold { font-weight: 700; }
            .text-small { font-size: 10px; color: #999; }
            
            /* Summary */
            .summary-section {
                display: flex;
                justify-content: flex-end;
                margin-bottom: 20px;
            }
            .summary-table {
                width: 320px;
                border-collapse: collapse;
                font-size: 12px;
            }
            .summary-table td {
                padding: 8px 12px;
                border: 1px solid #e5e7eb;
            }
            .summary-table .summary-label {
                font-weight: 600;
                background: #f3f4f6;
                color: #333;
            }
            .summary-table .summary-value {
                text-align: right;
                font-weight: 700;
            }
            .summary-table .total-row {
                background: #DBEAFE;
                font-size: 13px;
                border-top: 2px solid #1E40AF;
            }
            .summary-table .total-row td {
                font-weight: 800;
                color: #1E40AF;
            }
            
            /* Footer */
            .report-footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 2px solid #bb0404;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 10px;
                color: #999;
            }
            .report-footer .signature-box {
                border: 1px dashed #ccc;
                padding: 8px 20px;
                border-radius: 6px;
                min-width: 150px;
                text-align: center;
            }
            .report-footer .signature-box .sig-label {
                font-size: 9px;
                color: #999;
                text-transform: uppercase;
                margin-bottom: 3px;
            }
            .report-footer .signature-box .sig-value {
                font-size: 11px;
                color: #333;
                font-weight: 600;
            }
            
            /* Print Button - Floating */
            .print-actions {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 1000;
                display: flex;
                gap: 10px;
            }
            .print-btn {
                background: #bb0404;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                transition: all 0.3s ease;
                box-shadow: 0 4px 12px rgba(187,4,4,0.3);
                font-family: inherit;
            }
            .print-btn:hover {
                background: #8a0303;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(187,4,4,0.4);
            }
            .close-btn {
                background: #6b7280;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                transition: all 0.3s ease;
                box-shadow: 0 4px 12px rgba(107,114,128,0.3);
                font-family: inherit;
            }
            .close-btn:hover {
                background: #4b5563;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(107,114,128,0.4);
            }
            
            /* Print Media */
            @media print {
                body { padding: 0; font-size: 10px; }
                .print-actions { display: none !important; }
                .report-header { border-bottom-width: 2px; }
                .report-header .logo { width: 60px; height: 60px; }
                .report-header .company-name { font-size: 18px; }
                table.data-table { font-size: 9px; }
                table.data-table thead th { padding: 5px 3px; font-size: 8px; }
                table.data-table tbody td { padding: 4px 3px; }
                .summary-table { font-size: 10px; }
                tr { page-break-inside: avoid; }
                .report-footer { page-break-inside: avoid; }
            }
            
            @page {
                size: A4 landscape;
                margin: 1cm;
            }
        </style>
    </head>
    <body>
        
        <!-- Print Actions -->
        <div class="print-actions">
            <button class="print-btn" onclick="window.print()" type="button">
                🖨 Print / Save as PDF
            </button>
            <button class="close-btn" onclick="closeReport()" type="button">
                ✕ Close
            </button>
        </div>
        
        <!-- Report Header with Logo -->
        <div class="report-header">
            <?php if ($logo_data_uri): ?>
                <img src="<?php echo $logo_data_uri; ?>" class="logo" alt="Company Logo">
            <?php endif; ?>
            <div class="company-info">
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="company-details">
                    <?php if ($company_address): ?>
                        📍 <?php echo htmlspecialchars($company_address); ?><br>
                    <?php endif; ?>
                    <?php if ($company_phone || $company_email): ?>
                        <?php if ($company_phone): ?>📞 <?php echo htmlspecialchars($company_phone); ?><?php endif; ?>
                        <?php if ($company_phone && $company_email): ?> &nbsp;|&nbsp; <?php endif; ?>
                        <?php if ($company_email): ?>✉ <?php echo htmlspecialchars($company_email); ?><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="report-title">📊 Capital Transactions Report</div>
            </div>
        </div>
        
        <!-- Report Meta -->
        <div class="report-meta">
            <div class="meta-item">
                <span class="meta-label">Branch:</span>
                <span class="meta-value">
                    <?php if ($branch_info): ?>
                        <?php echo htmlspecialchars($branch_info['branch_name']); ?>
                        <?php if (!empty($branch_info['branch_code'])): ?>
                            (<?php echo htmlspecialchars($branch_info['branch_code']); ?>)
                        <?php endif; ?>
                    <?php else: ?>
                        All Branches
                    <?php endif; ?>
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Records:</span>
                <span class="meta-value"><?php echo count($transactions); ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Period:</span>
                <span class="meta-value">
                    <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Generated:</span>
                <span class="meta-value"><?php echo date('d M Y H:i:s'); ?></span>
            </div>
        </div>
        
        <!-- Data Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Capital No.</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Source</th>
                    <th>Provider/Code</th>
                    <th class="text-right">Amount</th>
                    <th>Branch</th>
                    <th>Employee</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="10" class="text-center" style="padding: 30px; color: #999;">
                            No transactions found for the selected period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; ?>
                    <?php foreach ($transactions as $t): 
                        $is_out = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
                        $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
                        $source = $is_float ? '🏦 Float' : '💵 Cash';
                        $provider_info = '';
                        if ($is_float) {
                            $provider_info = htmlspecialchars($t['provider_name']) . ' (' . htmlspecialchars($t['branch_provider_code'] ?? 'N/A') . ')';
                        }
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $i++; ?></td>
                            <td class="font-bold" style="color: #bb0404; font-family: monospace;">
                                <?php echo htmlspecialchars($t['capital_number']); ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($t['transaction_date'])); ?></td>
                            <td><?php echo $type_labels[$t['transaction_type']] ?? $t['transaction_type']; ?></td>
                            <td><?php echo $source; ?></td>
                            <td><?php echo $provider_info ?: '-'; ?></td>
                            <td class="text-right <?php echo $is_out ? 'text-danger' : 'text-success'; ?>">
                                <?php echo ($is_out ? '-' : '+') . ' ' . formatCurrency($t['amount']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($t['branch_name'] ?? 'Main'); ?></td>
                            <td><?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?></td>
                            <td class="text-small"><?php echo htmlspecialchars(substr($t['description'] ?? '', 0, 60)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Summary Section -->
        <div class="summary-section">
            <table class="summary-table">
                <tr>
                    <td class="summary-label">Total In:</td>
                    <td class="summary-value text-success">+ <?php echo formatCurrency($total_in); ?></td>
                </tr>
                <tr>
                    <td class="summary-label">Total Out:</td>
                    <td class="summary-value text-danger">- <?php echo formatCurrency($total_out); ?></td>
                </tr>
                <tr>
                    <td class="summary-label">🏦 Float In:</td>
                    <td class="summary-value"><?php echo formatCurrency($total_float); ?></td>
                </tr>
                <tr>
                    <td class="summary-label">💵 Cash In:</td>
                    <td class="summary-value"><?php echo formatCurrency($total_cash); ?></td>
                </tr>
                <tr class="total-row">
                    <td>Net Capital:</td>
                    <td class="summary-value"><?php echo formatCurrency($net_capital); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="report-footer">
            <div>
                Generated by <strong><?php echo htmlspecialchars($company_name); ?></strong> Management System<br>
                Report ID: CAP-<?php echo date('YmdHis'); ?>
            </div>
            <div class="signature-box">
                <div class="sig-label">Authorized By</div>
                <div class="sig-value">_________________</div>
            </div>
        </div>
        
        <!-- ✅ FIXED: Close Button Function with Fallback -->
        <script>
        function closeReport() {
            // Try to close the window first
            try {
                window.close();
            } catch (e) {
                console.log('window.close() failed');
            }
            
            // Fallback after 150ms if window still open
            setTimeout(function() {
                if (!window.closed) {
                    // Try history back
                    if (window.history.length > 1) {
                        window.history.back();
                    } else {
                        // Last resort: redirect to index
                        window.location.href = 'index.php';
                    }
                }
            }, 150);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close
            if (e.key === 'Escape') {
                closeReport();
            }
            // Ctrl+P or Cmd+P to print
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
// DEFAULT REDIRECT
// ============================================================
header('Location: index.php');
exit();
?>