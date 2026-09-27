<?php
// ================================================================
// FILE: modules/salaries/export.php
// WAKALA FINANCIAL SYSTEM - SALARIES EXPORT
// ✅ GREEN THEME
// ✅ Header (logo + taarifa) = Page 1 peke yake
// ✅ Tables = zinaanza page inayofuata (multi-page OK)
// ✅ Footer + Official Stamp = mwisho wa report
// ✅ CSV, Excel, PDF, Print formats
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/salary_functions.php';

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
// PARAMETERS
// ============================================================
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';
if (!in_array($format, ['csv', 'excel', 'pdf', 'print'])) {
    $format = 'csv';
}

$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

$from_month = isset($_GET['from_month']) ? trim($_GET['from_month']) : '';
$to_month = isset($_GET['to_month']) ? trim($_GET['to_month']) : '';

// ============================================================
// BRANCH INFO
// ============================================================
$branch_display = 'All Branches';
$branch_code = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$selected_branch]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($b) {
        $branch_display = $b['branch_name'];
        $branch_code = $b['branch_code'] ?? '';
    }
}

// ============================================================
// GET MONTHLY SUMMARY
// ============================================================
try {
    $monthly_summary = getMonthlySalarySummary($db, $selected_branch, 36);
} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    $monthly_summary = [];
}

// ============================================================
// GET DETAILED SALARY RECORDS
// ============================================================
$detailed_records = [];
try {
    $where = [];
    $params = [];
    
    if ($selected_branch > 0) {
        $where[] = "es.branch_id = ?";
        $params[] = $selected_branch;
    }
    
    if (!empty($from_month) && preg_match('/^\d{4}-\d{2}$/', $from_month)) {
        $where[] = "DATE_FORMAT(es.salary_month, '%Y-%m') >= ?";
        $params[] = $from_month;
    }
    
    if (!empty($to_month) && preg_match('/^\d{4}-\d{2}$/', $to_month)) {
        $where[] = "DATE_FORMAT(es.salary_month, '%Y-%m') <= ?";
        $params[] = $to_month;
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $stmt = $db->prepare("
        SELECT 
            es.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.position as employee_position,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            pb.full_name as paid_by_name
        FROM employee_salaries es
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN branches b ON es.branch_id = b.id
        LEFT JOIN employees pb ON es.paid_by = pb.id
        $where_sql
        ORDER BY es.salary_month DESC, b.branch_name ASC, e.full_name ASC
        LIMIT 5000
    ");
    $stmt->execute($params);
    $detailed_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Detailed records error: " . $e->getMessage());
    $detailed_records = [];
}

// ============================================================
// TOTALS
// ============================================================
$grand_total_amount = 0;
$grand_paid = 0;
$grand_waiting = 0;
$grand_upcoming = 0;
$grand_employees = 0;

foreach ($monthly_summary as $m) {
    $grand_total_amount += floatval($m['total_amount'] ?? 0);
    $grand_paid += floatval($m['paid_amount'] ?? 0);
    $grand_waiting += floatval($m['waiting_amount'] ?? 0);
    $grand_upcoming += floatval($m['upcoming_amount'] ?? 0);
    $grand_employees += intval($m['total_employees'] ?? 0);
}

$det_basic = 0;
$det_allowances = 0;
$det_deductions = 0;
$det_net = 0;
foreach ($detailed_records as $r) {
    $det_basic += floatval($r['basic_salary'] ?? 0);
    $det_allowances += floatval($r['allowances'] ?? 0);
    $det_deductions += floatval($r['deductions'] ?? 0);
    $det_net += floatval($r['net_pay'] ?? 0);
}

// ============================================================
// COMPANY INFO + LOGO
// ============================================================
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

$logo_absolute = __DIR__ . '/../../assets/images/logo.PNG';
$logo_exists = file_exists($logo_absolute);

$logo_base64 = '';
if ($logo_exists) {
    $logo_data = file_get_contents($logo_absolute);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
}

$generated_at = date('d M Y H:i:s');
$period_label = count($monthly_summary) > 0 
    ? date('M Y', strtotime($monthly_summary[count($monthly_summary)-1]['month_date'])) . 
      ' - ' . 
      date('M Y', strtotime($monthly_summary[0]['month_date']))
    : 'No data';

$filename_base = 'salaries_export_' . date('Y-m-d_His');

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [$company_name]);
    fputcsv($output, ['Salaries Export Report']);
    fputcsv($output, ['Branch:', $branch_display . ($branch_code ? ' (' . $branch_code . ')' : '')]);
    fputcsv($output, ['Period:', $period_label]);
    fputcsv($output, ['Generated:', $generated_at]);
    fputcsv($output, ['Total Records:', count($detailed_records)]);
    fputcsv($output, []);
    
    fputcsv($output, ['========================================']);
    fputcsv($output, ['SECTION 1: MONTHLY SUMMARY']);
    fputcsv($output, ['========================================']);
    fputcsv($output, []);
    
    fputcsv($output, [
        '#', 'Month', 'Employees', 'Total Amount',
        'Paid Amount', 'Paid Count',
        'Waiting Amount', 'Waiting Count',
        'Upcoming Amount', 'Upcoming Count',
        'Cancelled Count'
    ]);
    
    $i = 1;
    foreach ($monthly_summary as $m) {
        fputcsv($output, [
            $i++,
            $m['month_label'] . ' (' . date('F Y', strtotime($m['month_date'])) . ')',
            intval($m['total_employees']),
            number_format(floatval($m['total_amount']), 2, '.', ''),
            number_format(floatval($m['paid_amount']), 2, '.', ''),
            intval($m['paid_count']),
            number_format(floatval($m['waiting_amount']), 2, '.', ''),
            intval($m['waiting_count']),
            number_format(floatval($m['upcoming_amount']), 2, '.', ''),
            intval($m['upcoming_count']),
            intval($m['cancelled_count'] ?? 0)
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, [
        'GRAND TOTAL', count($monthly_summary) . ' months',
        number_format($grand_employees),
        number_format($grand_total_amount, 2, '.', ''),
        number_format($grand_paid, 2, '.', ''), '',
        number_format($grand_waiting, 2, '.', ''), '',
        number_format($grand_upcoming, 2, '.', ''), '', ''
    ]);
    
    fputcsv($output, []);
    fputcsv($output, []);
    
    fputcsv($output, ['========================================']);
    fputcsv($output, ['SECTION 2: DETAILED SALARY RECORDS']);
    fputcsv($output, ['========================================']);
    fputcsv($output, []);
    
    fputcsv($output, [
        '#', 'Salary Number', 'Employee', 'Employee Code', 'Position',
        'Branch', 'Month', 'Basic Salary', 'Allowances', 'Deductions',
        'Net Pay', 'Status', 'Paid Date', 'Paid By', 'Notes'
    ]);
    
    if (empty($detailed_records)) {
        fputcsv($output, ['No detailed records found.']);
    } else {
        $j = 1;
        foreach ($detailed_records as $r) {
            fputcsv($output, [
                $j++,
                $r['salary_number'] ?? '-',
                $r['employee_name'] ?? '-',
                $r['employee_code'] ?? '-',
                $r['employee_position'] ?? '-',
                $r['branch_name'] ?? '-',
                date('M Y', strtotime($r['salary_month'] ?? 'now')),
                number_format(floatval($r['basic_salary'] ?? 0), 2, '.', ''),
                number_format(floatval($r['allowances'] ?? 0), 2, '.', ''),
                number_format(floatval($r['deductions'] ?? 0), 2, '.', ''),
                number_format(floatval($r['net_pay'] ?? 0), 2, '.', ''),
                ucfirst($r['status'] ?? 'pending'),
                $r['paid_date'] ?? '-',
                $r['paid_by_name'] ?? '-',
                $r['notes'] ?? '-'
            ]);
        }
        
        fputcsv($output, []);
        fputcsv($output, [
            'TOTAL', count($detailed_records) . ' records',
            '', '', '', '', '',
            number_format($det_basic, 2, '.', ''),
            number_format($det_allowances, 2, '.', ''),
            number_format($det_deductions, 2, '.', ''),
            number_format($det_net, 2, '.', ''),
            '', '', '', ''
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['--- End of Report ---']);
    fputcsv($output, ['Generated by ' . $company_name]);
    
    fclose($output);
    exit();
}

// ============================================================
// EXCEL EXPORT
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.xls"');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Salaries Export</title>
        <style>
            body { font-family: Arial, sans-serif; }
            h1 { color: #059669; margin: 0; font-size: 22px; text-align: center; }
            h2 { color: #059669; margin: 20px 0 10px 0; font-size: 16px; }
            .header-table { width: 100%; margin-bottom: 20px; text-align: center; }
            .header-table td { text-align: center; vertical-align: middle; padding: 8px; }
            .logo-img { width: 100px; height: 100px; object-fit: contain; }
            .info { color: #666; font-size: 12px; line-height: 1.8; text-align: center; }
            table.data { border-collapse: collapse; width: 100%; font-size: 11px; margin-top: 10px; }
            table.data th {
                background: #059669; color: white;
                padding: 10px 8px; text-align: left;
                border: 1px solid #047857;
                font-weight: bold; text-transform: uppercase; font-size: 10px;
            }
            table.data th.text-right { text-align: right; }
            table.data th.text-center { text-align: center; }
            table.data td { padding: 8px; border: 1px solid #ddd; }
            table.data td.text-right { text-align: right; }
            table.data td.text-center { text-align: center; }
            table.data tr:nth-child(even) { background: #f9fafb; }
            .total-row { background: #D1FAE5; font-weight: bold; }
        </style>
    </head>
    <body>
        
        <table class="header-table">
            <tr>
                <td style="text-align:center;">
                    <?php if ($logo_exists): ?>
                        <img src="<?php echo $logo_base64; ?>" alt="Logo" class="logo-img">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>
                    <h1><?php echo htmlspecialchars($company_name); ?></h1>
                    <div class="info">
                        <strong>Salaries Export Report</strong><br>
                        <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display); ?>
                        <?php if ($branch_code): ?>(<?php echo htmlspecialchars($branch_code); ?>)<?php endif; ?>
                        <br>
                        <strong>Period:</strong> <?php echo $period_label; ?>
                        <br>
                        <strong>Generated:</strong> <?php echo $generated_at; ?>
                        <br>
                        <strong>Total Records:</strong> <?php echo count($detailed_records); ?>
                    </div>
                </td>
            </tr>
        </table>
        
        <h2>📊 Monthly Summary</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Month</th>
                    <th class="text-center">Employees</th>
                    <th class="text-right">Total Amount</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Waiting</th>
                    <th class="text-right">Upcoming</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($monthly_summary as $m): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><strong><?php echo htmlspecialchars($m['month_label']); ?></strong></td>
                        <td class="text-center"><?php echo intval($m['total_employees']); ?></td>
                        <td class="text-right"><?php echo number_format(floatval($m['total_amount']), 0); ?></td>
                        <td class="text-right" style="color:#059669;">
                            <?php echo $m['paid_amount'] > 0 ? number_format(floatval($m['paid_amount']), 0) : '—'; ?>
                        </td>
                        <td class="text-right" style="color:#D97706;">
                            <?php echo $m['waiting_amount'] > 0 ? number_format(floatval($m['waiting_amount']), 0) : '—'; ?>
                        </td>
                        <td class="text-right" style="color:#2563EB;">
                            <?php echo $m['upcoming_amount'] > 0 ? number_format(floatval($m['upcoming_amount']), 0) : '—'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2">GRAND TOTAL</td>
                    <td class="text-center"><?php echo number_format($grand_employees); ?></td>
                    <td class="text-right"><?php echo number_format($grand_total_amount, 0); ?></td>
                    <td class="text-right"><?php echo number_format($grand_paid, 0); ?></td>
                    <td class="text-right"><?php echo number_format($grand_waiting, 0); ?></td>
                    <td class="text-right"><?php echo number_format($grand_upcoming, 0); ?></td>
                </tr>
            </tbody>
        </table>
        
        <?php if (!empty($detailed_records)): ?>
        <h2>📋 Detailed Salary Records (<?php echo count($detailed_records); ?>)</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Employee</th>
                    <th>Code</th>
                    <th>Branch</th>
                    <th>Month</th>
                    <th class="text-right">Basic</th>
                    <th class="text-right">Allowances</th>
                    <th class="text-right">Deductions</th>
                    <th class="text-right">Net Pay</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $j = 1; foreach ($detailed_records as $r): ?>
                    <tr>
                        <td><?php echo $j++; ?></td>
                        <td><strong><?php echo htmlspecialchars($r['employee_name'] ?? '-'); ?></strong></td>
                        <td><?php echo htmlspecialchars($r['employee_code'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($r['branch_name'] ?? '-'); ?></td>
                        <td><?php echo date('M Y', strtotime($r['salary_month'] ?? 'now')); ?></td>
                        <td class="text-right"><?php echo number_format(floatval($r['basic_salary'] ?? 0), 0); ?></td>
                        <td class="text-right" style="color:#059669;">+<?php echo number_format(floatval($r['allowances'] ?? 0), 0); ?></td>
                        <td class="text-right" style="color:#DC2626;">-<?php echo number_format(floatval($r['deductions'] ?? 0), 0); ?></td>
                        <td class="text-right" style="color:#059669; font-weight:bold;"><?php echo number_format(floatval($r['net_pay'] ?? 0), 0); ?></td>
                        <td><?php echo strtoupper($r['status'] ?? 'pending'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5">TOTAL</td>
                    <td class="text-right"><?php echo number_format($det_basic, 0); ?></td>
                    <td class="text-right">+<?php echo number_format($det_allowances, 0); ?></td>
                    <td class="text-right">-<?php echo number_format($det_deductions, 0); ?></td>
                    <td class="text-right"><?php echo number_format($det_net, 0); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// PDF / PRINT - GREEN THEME
// ✅ HEADER = PAGE 1 PEKE YAKE
// ✅ TABLES = ZINAANZA PAGE 2+
// ✅ FOOTER + STAMP = MWISHO
// ============================================================
if ($format === 'pdf' || $format === 'print') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Salaries Export - <?php echo $period_label; ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            
            body {
                font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                background: #F0FDF4;
                color: #1F2937;
                padding: 20px;
                font-size: 11px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .container {
                max-width: 1400px;
                margin: 0 auto;
                background: #FFFFFF;
                border-radius: 12px;
                padding: 30px;
                box-shadow: 0 4px 20px rgba(5, 150, 105, 0.12);
                border-top: 6px solid #059669;
            }
            
            /* ============================================================
               PAGE 1 - HEADER (standalone page)
               ============================================================ */
            .report-header {
                text-align: center;
                padding: 40px 20px 30px 20px;
                border-bottom: 3px solid #059669;
                margin-bottom: 20px;
            }
            
            .report-header-logo-wrapper {
                display: flex;
                justify-content: center;
                align-items: center;
                margin-bottom: 18px;
            }
            
            .report-header-logo {
                width: 130px;
                height: 130px;
                object-fit: contain;
                border-radius: 16px;
                padding: 10px;
                background: #FFFFFF;
                border: 3px solid #D1FAE5;
                box-shadow: 0 4px 16px rgba(5, 150, 105, 0.18);
            }
            
            .report-header-fallback {
                width: 130px;
                height: 130px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 60px;
                background: #ECFDF5;
                border: 3px solid #D1FAE5;
                border-radius: 16px;
            }
            
            .report-header-info h1 {
                font-size: 28px;
                font-weight: 900;
                color: #065F46;
                margin-bottom: 6px;
                letter-spacing: -0.5px;
                line-height: 1.1;
            }
            
            .report-header-info h2 {
                font-size: 14px;
                font-weight: 800;
                color: #059669;
                text-transform: uppercase;
                letter-spacing: 2px;
                margin-bottom: 18px;
                padding-bottom: 14px;
                border-bottom: 2px solid #D1FAE5;
                display: inline-block;
                padding-left: 24px;
                padding-right: 24px;
            }
            
            .report-header-meta {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px 20px;
                font-size: 12px;
                color: #64748B;
                margin-top: 12px;
            }
            
            .report-header-meta .meta-item {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 18px;
                background: #ECFDF5;
                border-radius: 24px;
                border: 1.5px solid #A7F3D0;
                font-weight: 600;
            }
            
            .report-header-meta .meta-item .meta-label {
                color: #059669;
                font-weight: 800;
                text-transform: uppercase;
                font-size: 10px;
                letter-spacing: 0.8px;
            }
            
            .report-header-meta .meta-item .meta-value {
                color: #065F46;
                font-weight: 700;
            }
            
            .report-header-badge {
                display: inline-block;
                padding: 10px 26px;
                background: linear-gradient(135deg, #059669, #10B981);
                color: #FFFFFF;
                border-radius: 28px;
                font-size: 12px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1.2px;
                margin-top: 22px;
                box-shadow: 0 6px 18px rgba(5, 150, 105, 0.3);
            }
            
            /* ============================================================
               SUMMARY CARDS
               ============================================================ */
            .summary-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 12px;
                margin-bottom: 24px;
            }
            
            .summary-card {
                padding: 16px 18px;
                border-radius: 12px;
                border: 1.5px solid;
            }
            
            .summary-card .label {
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-bottom: 6px;
                opacity: 0.85;
            }
            
            .summary-card .value {
                font-size: 20px;
                font-weight: 900;
                font-family: 'Courier New', monospace;
                line-height: 1.2;
            }
            
            .summary-card .sub {
                font-size: 10px;
                opacity: 0.75;
                margin-top: 4px;
                font-weight: 600;
            }
            
            .card-total { background: #D1FAE5; border-color: #6EE7B7; color: #065F46; }
            .card-paid { background: #A7F3D0; border-color: #34D399; color: #064E3B; }
            .card-waiting { background: #FEF3C7; border-color: #FCD34D; color: #78350F; }
            .card-upcoming { background: #DBEAFE; border-color: #93C5FD; color: #1E40AF; }
            
            /* ============================================================
               SECTION TITLES
               ============================================================ */
            .section-title {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 20px;
                background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
                border-left: 5px solid #059669;
                border-radius: 0 10px 10px 0;
                margin: 24px 0 16px 0;
            }
            
            .section-title h3 {
                font-size: 13px;
                font-weight: 800;
                color: #065F46;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin: 0;
            }
            
            .section-title .count {
                margin-left: auto;
                padding: 4px 14px;
                background: #059669;
                color: #FFFFFF;
                border-radius: 12px;
                font-size: 10px;
                font-weight: 800;
            }
            
            /* ============================================================
               DATA TABLES
               ============================================================ */
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10px;
                margin-bottom: 20px;
                table-layout: fixed;
            }
            
            thead {
                background: linear-gradient(135deg, #059669 0%, #10B981 100%);
            }
            
            thead th {
                color: #FFFFFF;
                padding: 10px 6px;
                text-align: left;
                font-weight: 800;
                font-size: 9px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
                border-right: 1px solid rgba(255, 255, 255, 0.15);
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            thead th:last-child { border-right: none; }
            thead th.text-right { text-align: right; }
            thead th.text-center { text-align: center; }
            
            tbody tr {
                border-bottom: 1px solid #E5E7EB;
            }
            
            tbody tr:nth-child(even) { background: #F9FAFB; }
            tbody tr:hover { background: #ECFDF5; }
            
            tbody td {
                padding: 8px 6px;
                color: #1F2937;
                vertical-align: middle;
                font-size: 10px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            tbody td.text-right {
                text-align: right;
                font-family: 'Courier New', monospace;
                font-weight: 700;
            }
            
            tbody td.text-center { text-align: center; }
            
            .total-row {
                background: linear-gradient(135deg, #D1FAE5, #A7F3D0) !important;
                font-weight: 900;
                border-top: 2px solid #059669;
                border-bottom: 2px solid #059669;
            }
            
            .total-row td {
                padding: 11px 6px;
                color: #064E3B;
                font-size: 11px;
            }
            
            /* STATUS BADGES */
            .badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 9px;
                border-radius: 6px;
                font-size: 8px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                white-space: nowrap;
            }
            
            .badge-paid { background: #D1FAE5; color: #065F46; border: 1px solid #6EE7B7; }
            .badge-waiting { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
            .badge-upcoming { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
            .badge-pending { background: #F3F4F6; color: #4B5563; border: 1px solid #D1D5DB; }
            .badge-cancelled { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
            
            .amount-paid { color: #059669; font-weight: 800; }
            .amount-waiting { color: #D97706; font-weight: 800; }
            .amount-upcoming { color: #2563EB; font-weight: 800; }
            .amount-zero { color: #9CA3AF; }
            
            /* ============================================================
               FOOTER + STAMP
               ============================================================ */
            .report-footer {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 2px solid #D1FAE5;
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                flex-wrap: wrap;
                gap: 20px;
            }
            
            .report-footer-left {
                flex: 1;
                min-width: 250px;
            }
            
            .report-footer-left .brand {
                font-size: 13px;
                font-weight: 800;
                color: #065F46;
                margin-bottom: 4px;
            }
            
            .report-footer-left .sub {
                font-size: 10px;
                color: #64748B;
                line-height: 1.6;
            }
            
            .report-footer-left .sub strong {
                color: #065F46;
                font-weight: 800;
            }
            
            /* ✅ STAMP SECTION */
            .report-footer-stamp {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                min-width: 220px;
            }
            
            .stamp-box {
                width: 180px;
                height: 180px;
                border: 3px dashed #059669;
                border-radius: 50%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                color: #059669;
                background: rgba(209, 250, 229, 0.3);
                position: relative;
                padding: 16px;
                text-align: center;
            }
            
            .stamp-box::before {
                content: '';
                position: absolute;
                inset: 6px;
                border: 1.5px solid #059669;
                border-radius: 50%;
                opacity: 0.5;
            }
            
            .stamp-box .stamp-title {
                font-size: 9px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1.5px;
                color: #065F46;
                line-height: 1.3;
            }
            
            .stamp-box .stamp-icon {
                font-size: 32px;
                color: #059669;
                margin: 4px 0;
                opacity: 0.7;
            }
            
            .stamp-box .stamp-line {
                width: 80%;
                height: 1.5px;
                background: #059669;
                margin: 4px 0;
                opacity: 0.4;
            }
            
            .stamp-box .stamp-subtitle {
                font-size: 8px;
                font-weight: 700;
                color: #059669;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                opacity: 0.8;
            }
            
            .stamp-label {
                font-size: 10px;
                font-weight: 700;
                color: #64748B;
                text-transform: uppercase;
                letter-spacing: 1px;
                text-align: center;
            }
            
            /* ✅ SIGNATURE SECTION */
            .signature-section {
                margin-top: 30px;
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 30px;
                padding-top: 20px;
                border-top: 1.5px dashed #D1FAE5;
            }
            
            .signature-box {
                text-align: center;
            }
            
            .signature-line {
                width: 100%;
                height: 60px;
                border-bottom: 1.5px solid #065F46;
                margin-bottom: 6px;
                position: relative;
            }
            
            .signature-line::after {
                content: '';
                position: absolute;
                bottom: -1px;
                left: 20%;
                right: 20%;
                height: 1.5px;
                background: #64748B;
                opacity: 0.4;
            }
            
            .signature-label {
                font-size: 10px;
                font-weight: 800;
                color: #065F46;
                text-transform: uppercase;
                letter-spacing: 0.8px;
            }
            
            .signature-sublabel {
                font-size: 9px;
                color: #64748B;
                margin-top: 2px;
            }
            
            /* ============================================================
               PRINT BUTTON
               ============================================================ */
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
                background: linear-gradient(135deg, #059669, #10B981);
                color: #FFFFFF;
                border: none;
                border-radius: 12px;
                font-size: 13px;
                font-weight: 800;
                cursor: pointer;
                text-transform: uppercase;
                letter-spacing: 1px;
                box-shadow: 0 6px 20px rgba(5, 150, 105, 0.4);
                transition: all 0.3s ease;
                font-family: inherit;
            }
            
            .btn-print:hover {
                transform: translateY(-3px);
                box-shadow: 0 10px 28px rgba(5, 150, 105, 0.55);
            }
            
            .print-hint {
                margin-top: 12px;
                font-size: 11px;
                color: #64748B;
            }
            
            .print-hint kbd {
                background: #ECFDF5;
                border: 1px solid #A7F3D0;
                border-radius: 4px;
                padding: 2px 8px;
                font-family: monospace;
                font-size: 10px;
                color: #065F46;
                font-weight: 700;
            }
            
            /* ============================================================
               ✅ PRINT STYLES
               ✅ Header = page 1 peke yake
               ✅ Tables = zinaanza page 2+
               ✅ Footer + Stamp = mwisho
               ============================================================ */
            @media print {
                @page {
                    size: A4 portrait;
                    margin: 12mm;
                }
                
                html, body {
                    width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #FFFFFF !important;
                    font-size: 10px !important;
                }
                
                body {
                    padding: 0 !important;
                }
                
                .container {
                    box-shadow: none !important;
                    border-radius: 0 !important;
                    padding: 0 !important;
                    max-width: 100% !important;
                    border-top: none !important;
                    margin: 0 !important;
                }
                
                /* Hide print section */
                .print-section { display: none !important; }
                
                /* ============================================================
                   ✅ HEADER - FULL PAGE 1 PEKE YAKE
                   ============================================================ */
                .report-header {
                    page-break-after: always;
                    break-after: page;
                    min-height: 85vh;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    padding: 40px 20px !important;
                    border-bottom: 3px solid #059669 !important;
                    margin-bottom: 0 !important;
                }
                
                .report-header-logo {
                    width: 160px !important;
                    height: 160px !important;
                }
                
                .report-header-fallback {
                    width: 160px !important;
                    height: 160px !important;
                    font-size: 70px !important;
                }
                
                .report-header-info h1 {
                    font-size: 26px !important;
                    margin-bottom: 8px !important;
                }
                
                .report-header-info h2 {
                    font-size: 13px !important;
                    margin-bottom: 20px !important;
                    padding-bottom: 14px !important;
                }
                
                .report-header-meta {
                    gap: 8px 16px !important;
                    margin-top: 16px !important;
                }
                
                .report-header-meta .meta-item {
                    padding: 6px 14px !important;
                    font-size: 11px !important;
                }
                
                .report-header-badge {
                    padding: 8px 22px !important;
                    font-size: 11px !important;
                    margin-top: 20px !important;
                }
                
                /* ============================================================
                   ✅ TABLES - ZINAANZA PAGE 2+
                   ============================================================ */
                .summary-grid {
                    page-break-before: avoid;
                    break-before: avoid;
                    margin-bottom: 12px !important;
                    gap: 8px !important;
                }
                
                .summary-card {
                    padding: 10px 12px !important;
                }
                
                .summary-card .label { font-size: 8px !important; }
                .summary-card .value { font-size: 14px !important; }
                .summary-card .sub { font-size: 8px !important; }
                
                .section-title {
                    padding: 8px 14px !important;
                    margin: 12px 0 8px 0 !important;
                    page-break-after: avoid;
                    break-after: avoid;
                }
                
                .section-title h3 { font-size: 11px !important; }
                
                table {
                    font-size: 9px !important;
                    page-break-inside: auto;
                }
                
                thead {
                    display: table-header-group;
                }
                
                thead th {
                    padding: 6px 4px !important;
                    font-size: 8px !important;
                }
                
                tbody td {
                    padding: 5px 4px !important;
                    font-size: 9px !important;
                }
                
                .total-row td {
                    padding: 7px 4px !important;
                    font-size: 10px !important;
                }
                
                .badge {
                    padding: 2px 6px !important;
                    font-size: 7px !important;
                }
                
                /* ============================================================
                   ✅ FOOTER + STAMP - MWISHO WA REPORT
                   ============================================================ */
                .report-footer {
                    page-break-before: avoid;
                    break-before: avoid;
                    margin-top: 20px !important;
                    padding-top: 14px !important;
                }
                
                .stamp-box {
                    width: 150px !important;
                    height: 150px !important;
                    padding: 12px !important;
                }
                
                .stamp-box .stamp-icon { font-size: 26px !important; }
                .stamp-box .stamp-title { font-size: 8px !important; }
                .stamp-box .stamp-subtitle { font-size: 7px !important; }
                
                .signature-section {
                    margin-top: 20px !important;
                    padding-top: 14px !important;
                    gap: 20px !important;
                }
                
                .signature-line {
                    height: 45px !important;
                }
                
                /* Prevent orphan breaks */
                tr { page-break-inside: avoid; }
                thead { page-break-after: avoid; }
            }
            
            /* ============================================================
               RESPONSIVE (Screen)
               ============================================================ */
            @media (max-width: 768px) {
                body { padding: 10px; }
                .container { padding: 16px; }
                .report-header-info h1 { font-size: 20px; }
                .report-header-logo { width: 90px; height: 90px; }
                .summary-grid { grid-template-columns: 1fr; }
                table { font-size: 10px; }
                thead th, tbody td { padding: 6px 4px; }
                .report-header-meta { gap: 6px; }
                .report-header-meta .meta-item { font-size: 10px; padding: 5px 12px; }
                .signature-section { grid-template-columns: 1fr; gap: 20px; }
                .report-footer { flex-direction: column; align-items: center; }
            }
        </style>
    </head>
    <body>
        
        <div class="container">
            
            <!-- ============================================================
                 PAGE 1: HEADER (Standalone Page)
                 ============================================================ -->
            <div class="report-header">
                <div class="report-header-logo-wrapper">
                    <?php if ($logo_exists): ?>
                        <img src="<?php echo $logo_base64; ?>" 
                             alt="<?php echo htmlspecialchars($company_name); ?> Logo" 
                             class="report-header-logo">
                    <?php else: ?>
                        <div class="report-header-fallback">🏢</div>
                    <?php endif; ?>
                </div>
                
                <div class="report-header-info">
                    <h1><?php echo htmlspecialchars($company_name); ?></h1>
                    <h2>Salaries Export Report</h2>
                    
                    <div class="report-header-meta">
                        <div class="meta-item">
                            <span class="meta-label">Branch:</span>
                            <span class="meta-value">
                                <?php echo htmlspecialchars($branch_display); ?>
                                <?php if ($branch_code): ?>(<?php echo htmlspecialchars($branch_code); ?>)<?php endif; ?>
                            </span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Period:</span>
                            <span class="meta-value"><?php echo $period_label; ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Generated:</span>
                            <span class="meta-value"><?php echo $generated_at; ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Total Records:</span>
                            <span class="meta-value"><?php echo count($detailed_records); ?></span>
                        </div>
                    </div>
                    
                    <div class="report-header-badge">
                        <?php echo count($monthly_summary); ?> Months • <?php echo number_format($grand_employees); ?> Employees
                    </div>
                </div>
            </div>
            
            <!-- ============================================================
                 PAGE 2+: SUMMARY CARDS + TABLES
                 ============================================================ -->
            <div class="summary-grid">
                <div class="summary-card card-total">
                    <div class="label">Total Amount</div>
                    <div class="value"><?php echo formatCurrency($grand_total_amount); ?></div>
                    <div class="sub"><?php echo number_format($grand_employees); ?> employees</div>
                </div>
                <div class="summary-card card-paid">
                    <div class="label">Paid</div>
                    <div class="value"><?php echo formatCurrency($grand_paid); ?></div>
                    <div class="sub">Already paid</div>
                </div>
                <div class="summary-card card-waiting">
                    <div class="label">Waiting</div>
                    <div class="value"><?php echo formatCurrency($grand_waiting); ?></div>
                    <div class="sub">Ready to pay</div>
                </div>
                <div class="summary-card card-upcoming">
                    <div class="label">Upcoming</div>
                    <div class="value"><?php echo formatCurrency($grand_upcoming); ?></div>
                    <div class="sub">Future salaries</div>
                </div>
            </div>
            
            <!-- SECTION 1: MONTHLY SUMMARY -->
            <div class="section-title">
                <h3>📊 Section 1: Monthly Summary</h3>
                <span class="count"><?php echo count($monthly_summary); ?> months</span>
            </div>
            
            <?php if (!empty($monthly_summary)): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 4%;">#</th>
                        <th style="width: 16%;">Month</th>
                        <th class="text-center" style="width: 8%;">Employees</th>
                        <th class="text-right" style="width: 12%;">Total Amount</th>
                        <th class="text-right" style="width: 14%;">Paid</th>
                        <th class="text-right" style="width: 14%;">Waiting</th>
                        <th class="text-right" style="width: 14%;">Upcoming</th>
                        <th class="text-center" style="width: 10%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($monthly_summary as $m): 
                        if ($m['upcoming_count'] > 0) {
                            $status = 'upcoming';
                            $status_label = 'Upcoming';
                        } elseif ($m['waiting_count'] > 0) {
                            $status = 'waiting';
                            $status_label = 'Waiting';
                        } elseif ($m['paid_count'] > 0 && $m['cancelled_count'] == 0) {
                            $status = 'paid';
                            $status_label = 'Paid';
                        } else {
                            $status = 'pending';
                            $status_label = 'Mixed';
                        }
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td>
                                <strong style="color:#065F46;"><?php echo htmlspecialchars($m['month_label']); ?></strong>
                            </td>
                            <td class="text-center">
                                <strong><?php echo intval($m['total_employees']); ?></strong>
                            </td>
                            <td class="text-right">
                                <strong style="color:#065F46;"><?php echo number_format(floatval($m['total_amount']), 0); ?></strong>
                            </td>
                            <td class="text-right">
                                <?php if ($m['paid_amount'] > 0): ?>
                                    <span class="amount-paid"><?php echo number_format(floatval($m['paid_amount']), 0); ?></span>
                                    <br><small style="color:#64748B;"><?php echo $m['paid_count']; ?> emp</small>
                                <?php else: ?>
                                    <span class="amount-zero">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($m['waiting_amount'] > 0): ?>
                                    <span class="amount-waiting"><?php echo number_format(floatval($m['waiting_amount']), 0); ?></span>
                                    <br><small style="color:#64748B;"><?php echo $m['waiting_count']; ?> emp</small>
                                <?php else: ?>
                                    <span class="amount-zero">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($m['upcoming_amount'] > 0): ?>
                                    <span class="amount-upcoming"><?php echo number_format(floatval($m['upcoming_amount']), 0); ?></span>
                                    <br><small style="color:#64748B;"><?php echo $m['upcoming_count']; ?> emp</small>
                                <?php else: ?>
                                    <span class="amount-zero">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-<?php echo $status; ?>">
                                    <?php echo $status_label; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <tr class="total-row">
                        <td colspan="2" style="text-align:right;">GRAND TOTAL</td>
                        <td class="text-center"><?php echo number_format($grand_employees); ?></td>
                        <td class="text-right"><?php echo number_format($grand_total_amount, 0); ?></td>
                        <td class="text-right"><?php echo number_format($grand_paid, 0); ?></td>
                        <td class="text-right"><?php echo number_format($grand_waiting, 0); ?></td>
                        <td class="text-right"><?php echo number_format($grand_upcoming, 0); ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
            
            <!-- SECTION 2: DETAILED RECORDS -->
            <?php if (!empty($detailed_records)): ?>
            <div class="section-title">
                <h3>📋 Section 2: Detailed Salary Records</h3>
                <span class="count"><?php echo count($detailed_records); ?> records</span>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 3%;">#</th>
                        <th style="width: 13%;">Salary #</th>
                        <th style="width: 15%;">Employee</th>
                        <th style="width: 8%;">Code</th>
                        <th style="width: 10%;">Branch</th>
                        <th style="width: 8%;">Month</th>
                        <th class="text-right" style="width: 9%;">Basic</th>
                        <th class="text-right" style="width: 8%;">Allow.</th>
                        <th class="text-right" style="width: 8%;">Deduct.</th>
                        <th class="text-right" style="width: 9%;">Net Pay</th>
                        <th style="width: 7%;">Status</th>
                        <th style="width: 7%;">Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $j = 1; foreach ($detailed_records as $r): ?>
                        <tr>
                            <td><?php echo $j++; ?></td>
                            <td>
                                <span style="font-family:'Courier New',monospace;font-size:9px;color:#059669;font-weight:800;">
                                    <?php echo htmlspecialchars($r['salary_number'] ?? '-'); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color:#065F46;"><?php echo htmlspecialchars($r['employee_name'] ?? '-'); ?></strong>
                            </td>
                            <td>
                                <span style="font-family:'Courier New',monospace;font-size:9px;">
                                    <?php echo htmlspecialchars($r['employee_code'] ?? '-'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($r['branch_name'] ?? '-'); ?></td>
                            <td><?php echo date('M Y', strtotime($r['salary_month'] ?? 'now')); ?></td>
                            <td class="text-right"><?php echo number_format(floatval($r['basic_salary'] ?? 0), 0); ?></td>
                            <td class="text-right amount-paid">
                                +<?php echo number_format(floatval($r['allowances'] ?? 0), 0); ?>
                            </td>
                            <td class="text-right" style="color:#DC2626;">
                                -<?php echo number_format(floatval($r['deductions'] ?? 0), 0); ?>
                            </td>
                            <td class="text-right">
                                <strong style="color:#059669;">
                                    <?php echo number_format(floatval($r['net_pay'] ?? 0), 0); ?>
                                </strong>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($r['status'] ?? 'pending'); ?>">
                                    <?php echo strtoupper($r['status'] ?? 'pending'); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo !empty($r['paid_date']) ? date('d M Y', strtotime($r['paid_date'])) : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <tr class="total-row">
                        <td colspan="6" style="text-align:right;">TOTAL (<?php echo count($detailed_records); ?> records)</td>
                        <td class="text-right"><?php echo number_format($det_basic, 0); ?></td>
                        <td class="text-right">+<?php echo number_format($det_allowances, 0); ?></td>
                        <td class="text-right">-<?php echo number_format($det_deductions, 0); ?></td>
                        <td class="text-right"><?php echo number_format($det_net, 0); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
            
            <!-- ============================================================
                 FOOTER + OFFICIAL STAMP
                 ============================================================ -->
            <div class="report-footer">
                
                <!-- LEFT: INFO -->
                <div class="report-footer-left">
                    <div class="brand"><?php echo htmlspecialchars($company_name); ?></div>
                    <div class="sub">
                        Salaries Export Report<br>
                        Generated by <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></strong><br>
                        on <strong><?php echo $generated_at; ?></strong>
                    </div>
                </div>
                
                <!-- RIGHT: OFFICIAL STAMP -->
                <div class="report-footer-stamp">
                    <div class="stamp-box">
                        <div class="stamp-title">OFFICIAL</div>
                        <div class="stamp-icon">✅</div>
                        <div class="stamp-line"></div>
                        <div class="stamp-subtitle">Approved</div>
                    </div>
                    <div class="stamp-label">Official Stamp</div>
                </div>
                
            </div>
            
            <!-- ============================================================
                 SIGNATURE SECTION
                 ============================================================ -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Prepared By</div>
                    <div class="signature-sublabel"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Checked By</div>
                    <div class="signature-sublabel">Accountant / Auditor</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Approved By</div>
                    <div class="signature-sublabel">Manager / Director</div>
                </div>
            </div>
            
            <!-- ============================================================
                 PRINT BUTTON
                 ============================================================ -->
            <div class="print-section">
                <button class="btn-print" onclick="window.print()">
                    🖨️ Print / Save as PDF
                </button>
                <div class="print-hint">
                    Tip: Press <kbd>Ctrl</kbd> + <kbd>P</kbd> for quick access, then choose <strong>"Save as PDF"</strong>
                </div>
            </div>
            
        </div>
        
        <script>
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

// Fallback
header('Location: index.php');
exit();
?>