<?php
// ================================================================
// FILE: modules/daily_report/export_employee.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEE DAILY REPORT EXPORT
// ✅ Export formats: PDF (HTML), CSV, Word (.doc)
// ✅ Employee sees OWN BRANCH only
// ✅ Employee sees ONLY their own transactions
// ✅ Filters support (date range, branch)
// ✅ ALL TEXT IN ENGLISH
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

// Employee only
if ($role !== 'employee') {
    header('Location: export.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
try {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Location: ../../login.php');
    exit();
}

if (!$employee) {
    header('Location: ../../login.php');
    exit();
}

$employee_branch_id = $employee['branch_id'] ?? 0;

// ============================================================
// GET PARAMETERS
// ============================================================
$format    = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date   = isset($_GET['to_date'])   ? $_GET['to_date']   : date('Y-m-d');

if (!in_array($format, ['pdf', 'csv', 'word'])) {
    $format = 'csv';
}

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
    $from_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $to_date = date('Y-m-d');
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$branch_display_name = 'My Branch';
$branch_display_code = '';
$branch_location = '';

try {
    if ($employee_branch_id > 0) {
        $stmt = $db->prepare("SELECT branch_name, branch_code, location FROM branches WHERE id = ?");
        $stmt->execute([$employee_branch_id]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($b) {
            $branch_display_name = $b['branch_name'];
            $branch_display_code = $b['branch_code'] ?? '';
            $branch_location = $b['location'] ?? '';
        }
    }
} catch (PDOException $e) {
    // silent
}

// ============================================================
// GET REPORTS (BRANCH-WIDE)
// ============================================================
$reports = [];
try {
    $sql = "SELECT 
                dr.id as report_id,
                dr.report_number,
                dr.report_date,
                dr.created_at,
                dr.total_deposits,
                dr.total_withdrawals,
                dr.total_commission,
                dr.other_income,
                dr.total_expenses,
                dr.net_profit,
                dr.current_float,
                dr.current_cash,
                dr.current_capital,
                e.full_name as employee_name,
                b.branch_name as branch_name,
                b.branch_code as branch_code,
                mr.report_number as morning_report_number
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
            WHERE dr.report_date BETWEEN ? AND ?
            AND dr.branch_id = ?
            ORDER BY dr.report_date DESC, dr.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$from_date, $to_date, $employee_branch_id]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get providers count for each report
    foreach ($reports as &$r) {
        $stmt2 = $db->prepare("
            SELECT COUNT(*) as providers_count
            FROM daily_report_providers 
            WHERE daily_report_id = ?
        ");
        $stmt2->execute([$r['report_id']]);
        $pinfo = $stmt2->fetch(PDO::FETCH_ASSOC);
        $r['providers_count'] = intval($pinfo['providers_count'] ?? 0);
    }
    unset($r);
    
} catch (PDOException $e) {
    error_log("Export error: " . $e->getMessage());
    $reports = [];
}

// ============================================================
// GET MY TRANSACTIONS
// ============================================================
$my_transactions = [];
try {
    $sql = "
        SELECT 
            t.*,
            p.provider_name,
            p.icon_class,
            p.color_code
        FROM transactions t
        LEFT JOIN providers p ON t.provider_id = p.id
        WHERE t.branch_id = ?
        AND t.employee_id = ?
        AND DATE(t.transaction_date) BETWEEN ? AND ?
        ORDER BY t.created_at DESC
        LIMIT 500
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$employee_branch_id, $user_id, $from_date, $to_date]);
    $my_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Transactions export error: " . $e->getMessage());
    $my_transactions = [];
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$totals = [
    'reports_count'        => count($reports),
    'total_deposits'       => 0,
    'total_withdrawals'    => 0,
    'total_commission'     => 0,
    'other_income'         => 0,
    'total_expenses'       => 0,
    'net_profit'           => 0,
    'current_float'        => 0,
    'current_cash'         => 0,
    'current_capital'      => 0,
];

foreach ($reports as $r) {
    $totals['total_deposits']    += floatval($r['total_deposits'] ?? 0);
    $totals['total_withdrawals'] += floatval($r['total_withdrawals'] ?? 0);
    $totals['total_commission']  += floatval($r['total_commission'] ?? 0);
    $totals['other_income']      += floatval($r['other_income'] ?? 0);
    $totals['total_expenses']    += floatval($r['total_expenses'] ?? 0);
    $totals['net_profit']        += floatval($r['net_profit'] ?? 0);
}

// Current float/cash from latest report
if (!empty($reports)) {
    $latest = $reports[0];
    $totals['current_float'] = floatval($latest['current_float'] ?? 0);
    $totals['current_cash']  = floatval($latest['current_cash'] ?? 0);
    $totals['current_capital'] = floatval($latest['current_capital'] ?? 0);
}

// My transactions totals
$my_totals = [
    'count'       => count($my_transactions),
    'deposits'    => 0,
    'withdrawals' => 0,
    'deposit_count' => 0,
    'withdrawal_count' => 0,
];

foreach ($my_transactions as $t) {
    if ($t['transaction_type'] === 'deposit') {
        $my_totals['deposits'] += floatval($t['amount']);
        $my_totals['deposit_count']++;
    } else {
        $my_totals['withdrawals'] += floatval($t['amount']);
        $my_totals['withdrawal_count']++;
    }
}

// Company info
$company_name = 'Wakala Financial System';
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

$period_label = date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date));
$generated_at = date('d M Y, H:i:s');
$filename_base = 'employee_daily_reports_' . date('Y-m-d_His');

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // ============================================================
    // HEADER INFO
    // ============================================================
    fputcsv($output, [$company_name]);
    fputcsv($output, ['Employee Daily Reports Export']);
    fputcsv($output, ['Employee:', $employee['full_name'] ?? 'N/A']);
    fputcsv($output, ['Employee ID:', $employee['employee_id'] ?? 'N/A']);
    fputcsv($output, ['Branch:', $branch_display_name . ($branch_display_code ? ' (' . $branch_display_code . ')' : '')]);
    fputcsv($output, ['Period:', $period_label]);
    fputcsv($output, ['Generated:', $generated_at]);
    fputcsv($output, []);
    
    // ============================================================
    // SECTION 1: MY TRANSACTIONS
    // ============================================================
    fputcsv($output, ['========================================']);
    fputcsv($output, ['SECTION 1: MY TRANSACTIONS']);
    fputcsv($output, ['========================================']);
    fputcsv($output, []);
    
    fputcsv($output, [
        '#',
        'Transaction #',
        'Date',
        'Time',
        'Provider',
        'Type',
        'Amount (TSh)',
        'Reference',
        'Description',
        'Status'
    ]);
    
    if (empty($my_transactions)) {
        fputcsv($output, ['No transactions found in this period']);
    } else {
        $i = 1;
        foreach ($my_transactions as $t) {
            $is_deposit = $t['transaction_type'] === 'deposit';
            $created = $t['created_at'] ?? $t['transaction_date'];
            
            fputcsv($output, [
                $i++,
                $t['transaction_number'] ?? '-',
                date('Y-m-d', strtotime($created)),
                date('H:i:s', strtotime($created)),
                $t['provider_name'] ?? 'N/A',
                ucfirst($t['transaction_type']),
                ($is_deposit ? '+' : '-') . number_format(floatval($t['amount']), 2, '.', ''),
                $t['reference_number'] ?? '-',
                $t['description'] ?? '-',
                ucfirst($t['status'] ?? 'approved')
            ]);
        }
        
        // My transactions totals
        fputcsv($output, []);
        fputcsv($output, [
            'MY TOTALS',
            $my_totals['count'] . ' transactions',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ]);
        fputcsv($output, ['My Deposits:', '+' . number_format($my_totals['deposits'], 2, '.', ''), '(' . $my_totals['deposit_count'] . ' txn)']);
        fputcsv($output, ['My Withdrawals:', '-' . number_format($my_totals['withdrawals'], 2, '.', ''), '(' . $my_totals['withdrawal_count'] . ' txn)']);
    }
    
    fputcsv($output, []);
    fputcsv($output, []);
    
    // ============================================================
    // SECTION 2: BRANCH DAILY REPORTS
    // ============================================================
    fputcsv($output, ['========================================']);
    fputcsv($output, ['SECTION 2: BRANCH DAILY REPORTS']);
    fputcsv($output, ['========================================']);
    fputcsv($output, []);
    
    fputcsv($output, [
        '#',
        'Report Number',
        'Report Date',
        'Providers',
        'Total Deposits',
        'Total Withdrawals',
        'Commission',
        'Other Income',
        'Expenses',
        'Net Profit',
        'Current Float',
        'Current Cash',
        'Current Capital',
        'Created By'
    ]);
    
    if (empty($reports)) {
        fputcsv($output, ['No reports found in this period']);
    } else {
        $i = 1;
        foreach ($reports as $r) {
            fputcsv($output, [
                $i++,
                $r['report_number'] ?? '-',
                date('Y-m-d', strtotime($r['report_date'] ?? 'now')),
                intval($r['providers_count'] ?? 0),
                number_format(floatval($r['total_deposits'] ?? 0), 2, '.', ''),
                number_format(floatval($r['total_withdrawals'] ?? 0), 2, '.', ''),
                number_format(floatval($r['total_commission'] ?? 0), 2, '.', ''),
                number_format(floatval($r['other_income'] ?? 0), 2, '.', ''),
                number_format(floatval($r['total_expenses'] ?? 0), 2, '.', ''),
                number_format(floatval($r['net_profit'] ?? 0), 2, '.', ''),
                number_format(floatval($r['current_float'] ?? 0), 2, '.', ''),
                number_format(floatval($r['current_cash'] ?? 0), 2, '.', ''),
                number_format(floatval($r['current_capital'] ?? 0), 2, '.', ''),
                $r['employee_name'] ?? 'N/A'
            ]);
        }
        
        // Branch totals
        fputcsv($output, []);
        fputcsv($output, [
            'TOTAL',
            $totals['reports_count'] . ' reports',
            '',
            '',
            number_format($totals['total_deposits'], 2, '.', ''),
            number_format($totals['total_withdrawals'], 2, '.', ''),
            number_format($totals['total_commission'], 2, '.', ''),
            number_format($totals['other_income'], 2, '.', ''),
            number_format($totals['total_expenses'], 2, '.', ''),
            number_format($totals['net_profit'], 2, '.', ''),
            number_format($totals['current_float'], 2, '.', ''),
            number_format($totals['current_cash'], 2, '.', ''),
            number_format($totals['current_capital'], 2, '.', ''),
            ''
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['--- End of Report ---']);
    
    fclose($output);
    exit();
}

// ============================================================
// WORD EXPORT (.doc)
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
        <title>Employee Daily Reports Export</title>
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
                border-bottom: 3px solid #bb0404;
                padding-bottom: 12px;
                margin-bottom: 16px;
            }
            .company {
                font-size: 18pt;
                font-weight: bold;
                color: #991B1B;
                margin: 0 0 4px 0;
            }
            .report-title {
                font-size: 14pt;
                font-weight: bold;
                color: #bb0404;
                margin: 0 0 8px 0;
            }
            .meta {
                font-size: 9pt;
                color: #6B7280;
                line-height: 1.6;
            }
            .meta strong { color: #1F2937; }
            .section-title {
                background: #FEE2E2;
                border-left: 5px solid #bb0404;
                padding: 8px 12px;
                margin: 18px 0 10px 0;
                font-size: 12pt;
                font-weight: bold;
                color: #991B1B;
            }
            .summary-grid {
                display: table;
                width: 100%;
                margin-bottom: 14px;
                border-collapse: collapse;
            }
            .summary-row {
                display: table-row;
            }
            .summary-cell {
                display: table-cell;
                padding: 8px 10px;
                border: 1px solid #FECACA;
                background: #FEF2F2;
                font-size: 9pt;
            }
            .summary-label {
                font-weight: bold;
                color: #991B1B;
            }
            .summary-value {
                color: #1F2937;
                font-weight: bold;
            }
            .data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 8.5pt;
                margin-bottom: 16px;
            }
            .data-table th {
                background: #bb0404;
                color: #FFFFFF;
                padding: 7px 5px;
                text-align: left;
                font-weight: bold;
                font-size: 8pt;
                text-transform: uppercase;
                border: 1px solid #8a0303;
            }
            .data-table th.text-right { text-align: right; }
            .data-table th.text-center { text-align: center; }
            .data-table td {
                padding: 6px 5px;
                border: 1px solid #E5E7EB;
                color: #1F2937;
                vertical-align: middle;
            }
            .data-table td.text-right { text-align: right; font-family: 'Courier New', monospace; }
            .data-table td.text-center { text-align: center; }
            .data-table tr:nth-child(even) td { background: #F9FAFB; }
            .totals-row td {
                background: #FEF3C7 !important;
                font-weight: bold;
                border-top: 2px solid #bb0404;
                padding: 8px 5px;
            }
            .type-deposit { color: #059669; font-weight: bold; }
            .type-withdrawal { color: #DC2626; font-weight: bold; }
            .amount-positive { color: #059669; font-weight: bold; }
            .amount-negative { color: #DC2626; font-weight: bold; }
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
            <div class="report-title">📊 Employee Daily Reports Export</div>
            <div class="meta">
                <strong>Employee:</strong> <?php echo htmlspecialchars($employee['full_name'] ?? 'N/A'); ?>
                (<?php echo htmlspecialchars($employee['employee_id'] ?? 'N/A'); ?>)
                &nbsp;|&nbsp;
                <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display_name); ?>
                <?php if ($branch_display_code): ?>(<?php echo htmlspecialchars($branch_display_code); ?>)<?php endif; ?>
                <br>
                <strong>Period:</strong> <?php echo $period_label; ?>
                &nbsp;|&nbsp;
                <strong>Generated:</strong> <?php echo $generated_at; ?>
            </div>
        </div>
        
        <!-- ============================================================ -->
        <!-- SECTION 1: MY TRANSACTIONS -->
        <!-- ============================================================ -->
        <div class="section-title">📋 My Transactions (<?php echo $my_totals['count']; ?> records)</div>
        
        <div class="summary-grid">
            <div class="summary-row">
                <div class="summary-cell"><span class="summary-label">My Deposits:</span> <span class="summary-value">TSh <?php echo number_format($my_totals['deposits'], 0); ?></span> (<?php echo $my_totals['deposit_count']; ?> txn)</div>
                <div class="summary-cell"><span class="summary-label">My Withdrawals:</span> <span class="summary-value">TSh <?php echo number_format($my_totals['withdrawals'], 0); ?></span> (<?php echo $my_totals['withdrawal_count']; ?> txn)</div>
                <div class="summary-cell"><span class="summary-label">Total Transactions:</span> <span class="summary-value"><?php echo $my_totals['count']; ?></span></div>
            </div>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Transaction #</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Provider</th>
                    <th>Type</th>
                    <th class="text-right">Amount</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($my_transactions)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:20px; color:#9CA3AF;">
                            No transactions found in this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($my_transactions as $t): 
                        $is_deposit = $t['transaction_type'] === 'deposit';
                        $created = $t['created_at'] ?? $t['transaction_date'];
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($t['transaction_number'] ?? '-'); ?></td>
                            <td><?php echo date('d M Y', strtotime($created)); ?></td>
                            <td><?php echo date('H:i', strtotime($created)); ?></td>
                            <td><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="<?php echo $is_deposit ? 'type-deposit' : 'type-withdrawal'; ?>">
                                    <?php echo ucfirst($t['transaction_type']); ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <span class="<?php echo $is_deposit ? 'amount-positive' : 'amount-negative'; ?>">
                                    <?php echo $is_deposit ? '+' : '-'; ?><?php echo number_format(floatval($t['amount']), 0); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($t['reference_number'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- ============================================================ -->
        <!-- SECTION 2: BRANCH DAILY REPORTS -->
        <!-- ============================================================ -->
        <div class="section-title">📊 Branch Daily Reports (<?php echo $totals['reports_count']; ?> reports)</div>
        
        <div class="summary-grid">
            <div class="summary-row">
                <div class="summary-cell"><span class="summary-label">Total Deposits:</span> <span class="summary-value">TSh <?php echo number_format($totals['total_deposits'], 0); ?></span></div>
                <div class="summary-cell"><span class="summary-label">Total Withdrawals:</span> <span class="summary-value">TSh <?php echo number_format($totals['total_withdrawals'], 0); ?></span></div>
                <div class="summary-cell"><span class="summary-label">Net Profit:</span> <span class="summary-value">TSh <?php echo number_format($totals['net_profit'], 0); ?></span></div>
                <div class="summary-cell"><span class="summary-label">Total Capital:</span> <span class="summary-value">TSh <?php echo number_format($totals['current_capital'], 0); ?></span></div>
            </div>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Report Number</th>
                    <th>Date</th>
                    <th class="text-center">Providers</th>
                    <th class="text-right">Deposits</th>
                    <th class="text-right">Withdrawals</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Net Profit</th>
                    <th class="text-right">Current Capital</th>
                    <th>Created By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center; padding:20px; color:#9CA3AF;">
                            No reports found in this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($reports as $r): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($r['report_number'] ?? '-'); ?></td>
                            <td><?php echo date('d M Y', strtotime($r['report_date'] ?? 'now')); ?></td>
                            <td class="text-center"><?php echo intval($r['providers_count'] ?? 0); ?></td>
                            <td class="text-right"><?php echo number_format(floatval($r['total_deposits'] ?? 0), 0); ?></td>
                            <td class="text-right"><?php echo number_format(floatval($r['total_withdrawals'] ?? 0), 0); ?></td>
                            <td class="text-right"><?php echo number_format(floatval($r['total_commission'] ?? 0), 0); ?></td>
                            <td class="text-right <?php echo (floatval($r['net_profit'] ?? 0) >= 0) ? 'amount-positive' : 'amount-negative'; ?>">
                                <?php echo number_format(floatval($r['net_profit'] ?? 0), 0); ?>
                            </td>
                            <td class="text-right"><?php echo number_format(floatval($r['current_capital'] ?? 0), 0); ?></td>
                            <td><?php echo htmlspecialchars($r['employee_name'] ?? 'N/A'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <tr class="totals-row">
                        <td colspan="4" style="text-align:right;">GRAND TOTAL (<?php echo $totals['reports_count']; ?> reports)</td>
                        <td class="text-right"><?php echo number_format($totals['total_deposits'], 0); ?></td>
                        <td class="text-right"><?php echo number_format($totals['total_withdrawals'], 0); ?></td>
                        <td class="text-right"><?php echo number_format($totals['total_commission'], 0); ?></td>
                        <td class="text-right"><?php echo number_format($totals['net_profit'], 0); ?></td>
                        <td class="text-right"><?php echo number_format($totals['current_capital'], 0); ?></td>
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
// PDF EXPORT (HTML ready for print-to-PDF)
// ============================================================
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Employee Daily Reports — <?php echo $period_label; ?></title>
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
                border-bottom: 3px solid #bb0404;
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
                color: #991B1B;
                margin: 0 0 4px 0;
                letter-spacing: 0.3px;
            }
            .header-left .subtitle {
                font-size: 13px;
                color: #bb0404;
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
                background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
                color: #FFFFFF;
                border-radius: 8px;
                font-weight: 800;
                font-size: 11px;
                letter-spacing: 0.5px;
                margin-bottom: 6px;
            }
            
            /* SECTION TITLE */
            .section-title {
                background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
                border-left: 5px solid #bb0404;
                padding: 12px 16px;
                margin: 22px 0 14px 0;
                font-size: 14px;
                font-weight: 800;
                color: #991B1B;
                border-radius: 0 8px 8px 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 10px;
            }
            .section-title .count {
                background: #bb0404;
                color: #FFFFFF;
                padding: 4px 14px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 800;
            }
            
            /* SUMMARY CARDS */
            .summary-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-bottom: 16px;
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
            .sc-deposit  { background: #DCFCE7; border-color: #6EE7B7; color: #065F46; }
            .sc-withdraw { background: #FEE2E2; border-color: #FCA5A5; color: #991B1B; }
            .sc-profit   { background: #DBEAFE; border-color: #93C5FD; color: #1E40AF; }
            .sc-capital  { background: #EDE9FE; border-color: #C4B5FD; color: #5B21B6; }
            .sc-neutral  { background: #F3F4F6; border-color: #D1D5DB; color: #374151; }
            
            /* DATA TABLE */
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10.5px;
                margin-bottom: 16px;
            }
            thead {
                background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
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
            tbody tr:hover td { background: #FEF2F2; }
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
            
            .txn-no {
                font-family: 'Courier New', monospace;
                font-weight: 800;
                color: #1D4ED8;
                background: #DBEAFE;
                padding: 2px 8px;
                border-radius: 6px;
                font-size: 10px;
                display: inline-block;
            }
            
            .type-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 10px;
                border-radius: 10px;
                font-size: 9px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }
            .type-deposit {
                background: #DCFCE7;
                color: #065F46;
                border: 1px solid #6EE7B7;
            }
            .type-withdrawal {
                background: #FEE2E2;
                color: #991B1B;
                border: 1px solid #FCA5A5;
            }
            
            .amount-positive { color: #059669; font-weight: 900; }
            .amount-negative { color: #DC2626; font-weight: 900; }
            
            .totals-row td {
                background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%) !important;
                font-weight: 900;
                color: #78350F;
                border-top: 3px solid #bb0404;
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
                background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
                color: #FFFFFF;
                border: none;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 800;
                cursor: pointer;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                box-shadow: 0 4px 16px rgba(187, 4, 4, 0.4);
                transition: all 0.3s ease;
                font-family: inherit;
            }
            .btn-print:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 24px rgba(187, 4, 4, 0.55);
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
                .summary-grid { break-inside: avoid; }
                table { font-size: 9px; }
                thead { display: table-header-group; }
                tr { break-inside: avoid; }
            }
            
            /* RESPONSIVE */
            @media (max-width: 1200px) {
                .summary-grid { grid-template-columns: repeat(2, 1fr); }
            }
            @media (max-width: 768px) {
                body { padding: 12px; }
                .container { padding: 16px; }
                .summary-grid { grid-template-columns: 1fr; }
                .header { flex-direction: column; }
                .header-right { text-align: left; }
                table { font-size: 9px; }
                thead th, tbody td { padding: 6px 4px; }
            }
        </style>
    </head>
    <body>
        
        <div class="container">
            
            <!-- HEADER -->
            <div class="header">
                <div class="header-left">
                    <h1><?php echo htmlspecialchars($company_name); ?></h1>
                    <p class="subtitle">📊 Employee Daily Reports</p>
                    <div class="meta">
                        <strong>Employee:</strong> <?php echo htmlspecialchars($employee['full_name'] ?? 'N/A'); ?>
                        (<?php echo htmlspecialchars($employee['employee_id'] ?? 'N/A'); ?>)
                        <br>
                        <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display_name); ?>
                        <?php if ($branch_display_code): ?>(<?php echo htmlspecialchars($branch_display_code); ?>)<?php endif; ?>
                        &nbsp;•&nbsp;
                        <strong>Period:</strong> <?php echo $period_label; ?>
                        <br>
                        <strong>Generated:</strong> <?php echo $generated_at; ?>
                    </div>
                </div>
                <div class="header-right">
                    <div class="logo-badge">EMPLOYEE REPORT</div>
                    <div>Document ID: <?php echo strtoupper(substr(md5($filename_base), 0, 8)); ?></div>
                </div>
            </div>
            
            <!-- ============================================================ -->
            <!-- SECTION 1: MY TRANSACTIONS -->
            <!-- ============================================================ -->
            <div class="section-title">
                <span>📋 My Transactions</span>
                <span class="count"><?php echo $my_totals['count']; ?> records</span>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card sc-deposit">
                    <div class="label">My Deposits</div>
                    <div class="value">TSh <?php echo number_format($my_totals['deposits'], 0); ?></div>
                </div>
                <div class="summary-card sc-withdraw">
                    <div class="label">My Withdrawals</div>
                    <div class="value">TSh <?php echo number_format($my_totals['withdrawals'], 0); ?></div>
                </div>
                <div class="summary-card sc-neutral">
                    <div class="label">Deposit Count</div>
                    <div class="value"><?php echo $my_totals['deposit_count']; ?> txn</div>
                </div>
                <div class="summary-card sc-neutral">
                    <div class="label">Withdrawal Count</div>
                    <div class="value"><?php echo $my_totals['withdrawal_count']; ?> txn</div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">#</th>
                        <th>Transaction #</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Provider</th>
                        <th>Type</th>
                        <th class="text-right">Amount</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_transactions)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:30px; color:#9CA3AF; font-style:italic;">
                                No transactions found in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($my_transactions as $t): 
                            $is_deposit = $t['transaction_type'] === 'deposit';
                            $created = $t['created_at'] ?? $t['transaction_date'];
                        ?>
                            <tr>
                                <td><span class="row-num"><?php echo $i++; ?></span></td>
                                <td><span class="txn-no"><?php echo htmlspecialchars($t['transaction_number'] ?? '-'); ?></span></td>
                                <td><?php echo date('d M Y', strtotime($created)); ?></td>
                                <td><?php echo date('H:i', strtotime($created)); ?></td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="type-badge <?php echo $is_deposit ? 'type-deposit' : 'type-withdrawal'; ?>">
                                        <?php echo ucfirst($t['transaction_type']); ?>
                                    </span>
                                </td>
                                <td class="text-right <?php echo $is_deposit ? 'amount-positive' : 'amount-negative'; ?>">
                                    <?php echo $is_deposit ? '+' : '-'; ?><?php echo number_format(floatval($t['amount']), 0); ?>
                                </td>
                                <td><?php echo htmlspecialchars($t['reference_number'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- ============================================================ -->
            <!-- SECTION 2: BRANCH DAILY REPORTS -->
            <!-- ============================================================ -->
            <div class="section-title">
                <span>📊 Branch Daily Reports</span>
                <span class="count"><?php echo $totals['reports_count']; ?> reports</span>
            </div>
            
            <div class="summary-grid">
                <div class="summary-card sc-deposit">
                    <div class="label">Total Deposits</div>
                    <div class="value">TSh <?php echo number_format($totals['total_deposits'], 0); ?></div>
                </div>
                <div class="summary-card sc-withdraw">
                    <div class="label">Total Withdrawals</div>
                    <div class="value">TSh <?php echo number_format($totals['total_withdrawals'], 0); ?></div>
                </div>
                <div class="summary-card sc-profit">
                    <div class="label">Net Profit</div>
                    <div class="value">TSh <?php echo number_format($totals['net_profit'], 0); ?></div>
                </div>
                <div class="summary-card sc-capital">
                    <div class="label">Total Capital</div>
                    <div class="value">TSh <?php echo number_format($totals['current_capital'], 0); ?></div>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">#</th>
                        <th>Report Number</th>
                        <th>Date</th>
                        <th class="text-center">Prov.</th>
                        <th class="text-right">Deposits</th>
                        <th class="text-right">Withdrawals</th>
                        <th class="text-right">Commission</th>
                        <th class="text-right">Net Profit</th>
                        <th class="text-right">Current Capital</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:30px; color:#9CA3AF; font-style:italic;">
                                No reports found in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($reports as $r): 
                            $profit = floatval($r['net_profit'] ?? 0);
                        ?>
                            <tr>
                                <td><span class="row-num"><?php echo $i++; ?></span></td>
                                <td><span class="txn-no"><?php echo htmlspecialchars($r['report_number'] ?? '-'); ?></span></td>
                                <td style="font-weight:600;"><?php echo date('d M Y', strtotime($r['report_date'] ?? 'now')); ?></td>
                                <td class="text-center"><?php echo intval($r['providers_count'] ?? 0); ?></td>
                                <td class="text-right amount-positive"><?php echo number_format(floatval($r['total_deposits'] ?? 0), 0); ?></td>
                                <td class="text-right amount-negative"><?php echo number_format(floatval($r['total_withdrawals'] ?? 0), 0); ?></td>
                                <td class="text-right amount-positive"><?php echo number_format(floatval($r['total_commission'] ?? 0), 0); ?></td>
                                <td class="text-right <?php echo $profit >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                    <?php echo number_format($profit, 0); ?>
                                </td>
                                <td class="text-right"><?php echo number_format(floatval($r['current_capital'] ?? 0), 0); ?></td>
                                <td><?php echo htmlspecialchars($r['employee_name'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr class="totals-row">
                            <td colspan="4" style="text-align:right;">GRAND TOTAL (<?php echo $totals['reports_count']; ?> reports)</td>
                            <td class="text-right"><?php echo number_format($totals['total_deposits'], 0); ?></td>
                            <td class="text-right"><?php echo number_format($totals['total_withdrawals'], 0); ?></td>
                            <td class="text-right"><?php echo number_format($totals['total_commission'], 0); ?></td>
                            <td class="text-right"><?php echo number_format($totals['net_profit'], 0); ?></td>
                            <td class="text-right"><?php echo number_format($totals['current_capital'], 0); ?></td>
                            <td>—</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- FOOTER -->
            <div class="footer">
                <div>
                    <strong><?php echo htmlspecialchars($company_name); ?></strong> — Employee Daily Reports
                </div>
                <div>
                    Generated on <strong><?php echo $generated_at; ?></strong>
                </div>
            </div>
            
            <!-- PRINT BUTTON -->
            <div class="print-section">
                <button class="btn-print" onclick="window.print()">
                    <i>🖨️</i>
                    Print / Save as PDF
                </button>
                <div class="print-hint">
                    Tip: Press <kbd>Ctrl</kbd> + <kbd>P</kbd> for quick access, then choose <strong>"Save as PDF"</strong>
                </div>
            </div>
            
        </div>
        
        <script>
            // Optional: Auto-open print dialog
            // window.addEventListener('load', function() { setTimeout(function() { window.print(); }, 800); });
            
            // Keyboard shortcut: Ctrl+P
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
// FALLBACK
// ============================================================
header('Location: index_employee.php');
exit();
?>