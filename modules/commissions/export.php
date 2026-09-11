<?php
// ================================================================
// FILE: modules/commissions/export.php
// EXPORT COMMISSIONS
// ✅ Server-side export (CSV, Excel, PDF, Print)
// ✅ With Logo
// ✅ Support branch filter
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
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// ============================================================
// ✅ Support BOTH 'branch' AND 'branch_id'
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
} catch (Exception $e) {}

// ============================================================
// GET BRANCH INFO
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
    $sql = "SELECT 
                c.id, c.commission_number, c.commission_date, c.provider_data,
                c.total_commission, c.other_income, c.total_business_income,
                c.allocate_to_capital, c.allocated_amount, c.created_at, c.notes,
                emp.full_name as employee_name,
                b.branch_name as branch_name, b.branch_code as branch_code
            FROM commissions c
            LEFT JOIN employees emp ON c.employee_id = emp.id
            LEFT JOIN branches b ON c.branch_id = b.id
            WHERE 1=1";
    $params = [];

    if ($selected_branch > 0) {
        $sql .= " AND c.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($from_date)) {
        $sql .= " AND c.commission_date >= ?";
        $params[] = $from_date;
    }

    if (!empty($to_date)) {
        $sql .= " AND c.commission_date <= ?";
        $params[] = $to_date;
    }

    if (!empty($search_query)) {
        $sql .= " AND (
            c.commission_number LIKE ? OR
            c.notes LIKE ? OR
            emp.full_name LIKE ? OR
            b.branch_name LIKE ?
        )";
        $search_like = '%' . $search_query . '%';
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
    }

    $sql .= " ORDER BY c.commission_date DESC, c.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $commissions = [];
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_commission = 0;
$total_other_income = 0;
$total_business_income = 0;

foreach ($commissions as $c) {
    $total_commission += floatval($c['total_commission']);
    $total_other_income += floatval($c['other_income']);
    $total_business_income += floatval($c['total_business_income']);
}

// ============================================================
// LOGO
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
    $filename = 'commissions_export_' . date('Y-m-d') . '.csv';
    if ($branch_info) {
        $filename = 'commissions_' . strtolower(str_replace(' ', '_', $branch_info['branch_name'])) . '_' . date('Y-m-d') . '.csv';
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [$company_name]);
    if ($company_address) fputcsv($output, [$company_address]);
    if ($company_phone) fputcsv($output, ['Phone: ' . $company_phone]);
    if ($company_email) fputcsv($output, ['Email: ' . $company_email]);
    fputcsv($output, []);
    fputcsv($output, ['Commissions Report']);
    
    if ($branch_info) {
        fputcsv($output, ['Branch: ' . $branch_info['branch_name'] . ' (' . $branch_info['branch_code'] . ')']);
    } else {
        fputcsv($output, ['Branch: All Branches']);
    }
    
    fputcsv($output, ['Generated: ' . date('d M Y H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, [
        '#', 'Commission No.', 'Date', 'Employee', 'Branch', 
        'Providers', 'Commission', 'Other Income', 'Total Income'
    ]);
    
    $counter = 1;
    foreach ($commissions as $c) {
        $provider_data = json_decode($c['provider_data'] ?? '{}', true);
        $provider_count = count($provider_data);
        
        fputcsv($output, [
            $counter++,
            $c['commission_number'],
            date('Y-m-d', strtotime($c['commission_date'])),
            $c['employee_name'] ?? 'N/A',
            $c['branch_name'] ?? 'Main',
            $provider_count . ' providers',
            number_format($c['total_commission'], 2),
            number_format($c['other_income'], 2),
            number_format($c['total_business_income'], 2)
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Commission:', '', '', '', '', '', number_format($total_commission, 2)]);
    fputcsv($output, ['Total Other Income:', '', '', '', '', '', number_format($total_other_income, 2)]);
    fputcsv($output, ['Total Business Income:', '', '', '', '', '', number_format($total_business_income, 2)]);
    fputcsv($output, ['Total Records:', '', '', '', '', '', count($commissions)]);
    
    fclose($output);
    exit();
}

// ============================================================
// EXCEL EXPORT
// ============================================================
elseif ($format == 'excel') {
    $filename = 'commissions_export_' . date('Y-m-d') . '.xls';
    if ($branch_info) {
        $filename = 'commissions_' . strtolower(str_replace(' ', '_', $branch_info['branch_name'])) . '_' . date('Y-m-d') . '.xls';
    }
    
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Commissions Export</title>
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
            table.data th { background: #DC2626; color: white; padding: 8px; text-align: left; font-size: 11px; border: 1px solid #8a0303; }
            table.data td { padding: 6px; border: 1px solid #ccc; font-size: 11px; }
            table.data tr:nth-child(even) { background: #f9f9f9; }
            .text-right { text-align: right; }
            .text-success { color: #059669; font-weight: bold; }
            .text-purple { color: #7C3AED; font-weight: bold; }
            .text-blue { color: #1D4ED8; font-weight: bold; }
            .summary-table { margin-top: 15px; border-collapse: collapse; width: 350px; margin-left: auto; }
            .summary-table td { padding: 6px 10px; border: 1px solid #ccc; font-size: 12px; }
            .summary-table .label { font-weight: bold; background: #f3f4f6; }
            .summary-table .total-row { background: #DBEAFE; font-weight: bold; font-size: 13px; }
        </style>
    </head>
    <body>
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
                    <p class="report-title">COMMISSIONS REPORT</p>
                </td>
            </tr>
        </table>
        
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
                    <strong>Records:</strong> <?php echo count($commissions); ?>
                </td>
                <td style="border: none; padding: 2px 0; text-align: right;">
                    <?php if (!empty($search_query)): ?>
                        <strong>Search:</strong> "<?php echo htmlspecialchars($search_query); ?>"
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Commission No.</th>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Branch</th>
                    <th>Providers</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Other Income</th>
                    <th class="text-right">Total Income</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($commissions)): ?>
                    <tr><td colspan="9" style="text-align: center; padding: 30px; color: #999;">No commissions found.</td></tr>
                <?php else: ?>
                    <?php $i = 1; ?>
                    <?php foreach ($commissions as $c): 
                        $provider_data = json_decode($c['provider_data'] ?? '{}', true);
                        $provider_count = count($provider_data);
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($c['commission_number']); ?></td>
                            <td><?php echo date('d M Y', strtotime($c['commission_date'])); ?></td>
                            <td><?php echo htmlspecialchars($c['employee_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($c['branch_name'] ?? 'Main'); ?></td>
                            <td><?php echo $provider_count; ?> providers</td>
                            <td class="text-right text-success"><?php echo formatCurrency($c['total_commission']); ?></td>
                            <td class="text-right text-purple"><?php echo formatCurrency($c['other_income']); ?></td>
                            <td class="text-right text-blue"><?php echo formatCurrency($c['total_business_income']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <table class="summary-table">
            <tr>
                <td class="label">Total Commission:</td>
                <td class="text-right text-success"><?php echo formatCurrency($total_commission); ?></td>
            </tr>
            <tr>
                <td class="label">Total Other Income:</td>
                <td class="text-right text-purple"><?php echo formatCurrency($total_other_income); ?></td>
            </tr>
            <tr class="total-row">
                <td class="label">Total Business Income:</td>
                <td class="text-right"><?php echo formatCurrency($total_business_income); ?></td>
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
        <title>Commissions Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', 'Segoe UI', Arial, sans-serif; padding: 20px; color: #333; font-size: 12px; }
            
            .report-header {
                display: flex;
                align-items: center;
                gap: 20px;
                padding-bottom: 15px;
                border-bottom: 3px solid #DC2626;
                margin-bottom: 15px;
            }
            .report-header .logo {
                width: 80px;
                height: 80px;
                border-radius: 50%;
                border: 3px solid #DC2626;
                object-fit: cover;
                flex-shrink: 0;
            }
            .report-header .company-info { flex: 1; }
            .report-header .company-name {
                font-size: 22px;
                font-weight: 800;
                color: #DC2626;
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
            
            .report-meta {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 20px;
                padding: 12px 16px;
                background: #f9fafb;
                border-radius: 8px;
                border-left: 4px solid #DC2626;
                margin-bottom: 15px;
                font-size: 12px;
            }
            .report-meta .meta-item { display: flex; gap: 6px; }
            .report-meta .meta-label { font-weight: 600; color: #666; }
            .report-meta .meta-value { font-weight: 500; color: #333; }
            
            table.data-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
                font-size: 11px;
            }
            table.data-table thead {
                background: #DC2626;
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
            table.data-table tbody tr:nth-child(even) { background: #f9fafb; }
            
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .text-success { color: #059669; font-weight: 700; }
            .text-purple { color: #7C3AED; font-weight: 700; }
            .text-blue { color: #1D4ED8; font-weight: 700; }
            .font-bold { font-weight: 700; }
            
            .summary-section {
                display: flex;
                justify-content: flex-end;
                margin-bottom: 20px;
            }
            .summary-table {
                width: 350px;
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
            
            .report-footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 2px solid #DC2626;
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
            
            .print-actions {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 1000;
                display: flex;
                gap: 10px;
            }
            .print-btn {
                background: #DC2626;
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
                box-shadow: 0 4px 12px rgba(220,38,38,0.3);
                font-family: inherit;
            }
            .print-btn:hover { background: #B91C1C; transform: translateY(-2px); }
            
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
                font-family: inherit;
            }
            .close-btn:hover { background: #4b5563; }
            
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
            }
            
            @page {
                size: A4 landscape;
                margin: 1cm;
            }
        </style>
    </head>
    <body>
        
        <div class="print-actions">
            <button class="print-btn" onclick="window.print()" type="button">
                🖨 Print / Save as PDF
            </button>
            <button class="close-btn" onclick="closeReport()" type="button">
                ✕ Close
            </button>
        </div>
        
        <!-- Header -->
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
                <div class="report-title">💰 Commissions Report</div>
            </div>
        </div>
        
        <!-- Meta -->
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
                <span class="meta-value"><?php echo count($commissions); ?></span>
            </div>
            <?php if (!empty($search_query)): ?>
                <div class="meta-item">
                    <span class="meta-label">Search:</span>
                    <span class="meta-value">"<?php echo htmlspecialchars($search_query); ?>"</span>
                </div>
            <?php endif; ?>
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
                    <th>Commission No.</th>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Branch</th>
                    <th class="text-center">Providers</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Other Income</th>
                    <th class="text-right">Total Income</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($commissions)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 30px; color: #999;">
                            No commissions found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; ?>
                    <?php foreach ($commissions as $c): 
                        $provider_data = json_decode($c['provider_data'] ?? '{}', true);
                        $provider_count = count($provider_data);
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $i++; ?></td>
                            <td class="font-bold" style="color: #DC2626; font-family: monospace;">
                                <?php echo htmlspecialchars($c['commission_number']); ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($c['commission_date'])); ?></td>
                            <td><?php echo htmlspecialchars($c['employee_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($c['branch_name'] ?? 'Main'); ?></td>
                            <td class="text-center"><?php echo $provider_count; ?></td>
                            <td class="text-right text-success"><?php echo formatCurrency($c['total_commission']); ?></td>
                            <td class="text-right text-purple"><?php echo formatCurrency($c['other_income']); ?></td>
                            <td class="text-right text-blue"><?php echo formatCurrency($c['total_business_income']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Summary -->
        <div class="summary-section">
            <table class="summary-table">
                <tr>
                    <td class="summary-label">Total Commission:</td>
                    <td class="summary-value text-success"><?php echo formatCurrency($total_commission); ?></td>
                </tr>
                <tr>
                    <td class="summary-label">Total Other Income:</td>
                    <td class="summary-value text-purple"><?php echo formatCurrency($total_other_income); ?></td>
                </tr>
                <tr class="total-row">
                    <td>Total Business Income:</td>
                    <td class="summary-value"><?php echo formatCurrency($total_business_income); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="report-footer">
            <div>
                Generated by <strong><?php echo htmlspecialchars($company_name); ?></strong> Management System<br>
                Report ID: COM-<?php echo date('YmdHis'); ?>
            </div>
            <div class="signature-box">
                <div class="sig-label">Authorized By</div>
                <div class="sig-value">_________________</div>
            </div>
        </div>
        
        <script>
        function closeReport() {
            try { window.close(); } catch (e) {}
            setTimeout(function() {
                if (!window.closed) {
                    if (window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = 'index.php';
                    }
                }
            }, 150);
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeReport();
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

header('Location: index.php');
exit();
?>