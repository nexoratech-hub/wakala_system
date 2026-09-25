<?php
// ================================================================
// FILE: modules/daily_report/export.php
// EXPORT DAILY REPORTS
// ✅ FIXED: Ondoa p.provider_name (haipo kwenye daily_reports)
// ✅ FIXED: Ondoa LEFT JOIN providers kwenye main query
// ✅ NEW: Inahesabu providers_count kwa kila report
// ✅ NEW: Better formatting kwa CSV, Excel, PDF
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

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$selected_branch = 0;

// ✅ FIX: Support both 'branch' na 'branch_id' parameters
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

if ($selected_branch == 0) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch();
    if ($emp && $emp['branch_id'] > 0) {
        $selected_branch = intval($emp['branch_id']);
    }
}

try {
    // ✅ FIX: Ondoa p.provider_name na LEFT JOIN providers
    $sql = "SELECT dr.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            mr.report_number as morning_report_number
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
            WHERE dr.report_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND dr.branch_id = ?";
        $params[] = $selected_branch;
    }

    $sql .= " ORDER BY dr.report_date DESC, dr.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================================
    // ✅ NEW: Get providers count kwa kila report
    // ============================================================
    foreach ($reports as &$r) {
        $stmt2 = $db->prepare("
            SELECT COUNT(*) as providers_count,
                   COALESCE(SUM(total_deposits), 0) as sum_deposits,
                   COALESCE(SUM(total_withdrawals), 0) as sum_withdrawals,
                   COALESCE(SUM(current_float), 0) as sum_float
            FROM daily_report_providers 
            WHERE daily_report_id = ?
        ");
        $stmt2->execute([$r['id']]);
        $pinfo = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        $r['providers_count'] = intval($pinfo['providers_count'] ?? 0);
        $r['providers_total_deposits'] = floatval($pinfo['sum_deposits'] ?? 0);
        $r['providers_total_withdrawals'] = floatval($pinfo['sum_withdrawals'] ?? 0);
        $r['providers_total_float'] = floatval($pinfo['sum_float'] ?? 0);
    }
    unset($r);
    
} catch (PDOException $e) {
    error_log("Error exporting reports: " . $e->getMessage());
    $reports = [];
}

// ============================================================
// GET BRANCH NAME KWA HEADER
// ============================================================
$branch_display_name = 'All Branches';
if ($selected_branch > 0) {
    try {
        $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
        $stmt->execute([$selected_branch]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($b) $branch_display_name = $b['branch_name'];
    } catch (PDOException $e) {
        // Silent fail
    }
}

$period_label = date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date));

// ============================================================
// CSV EXPORT
// ============================================================
if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="daily_reports_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM kwa Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header info
    fputcsv($output, ['Daily Reports Export']);
    fputcsv($output, ['Period:', $period_label]);
    fputcsv($output, ['Branch:', $branch_display_name]);
    fputcsv($output, ['Generated:', date('d M Y H:i:s')]);
    fputcsv($output, []);
    
    // Column headers
    fputcsv($output, [
        'Report No.',
        'Date',
        'Branch',
        'Employee',
        'Providers',
        'Morning Float',
        'Current Float',
        'Deposits',
        'Withdrawals',
        'Commission',
        'Other Income',
        'Expenses',
        'Salaries',
        'Cash Out',
        'Net Profit',
        'Current Cash',
        'Current Capital'
    ]);
    
    $total_deposits = 0;
    $total_withdrawals = 0;
    $total_commission = 0;
    $total_other_income = 0;
    $total_expenses = 0;
    $total_salaries = 0;
    $total_cash_out = 0;
    $total_profit = 0;
    $total_capital = 0;
    
    foreach ($reports as $r) {
        fputcsv($output, [
            $r['report_number'],
            date('Y-m-d', strtotime($r['report_date'])),
            $r['branch_name'] ?? 'Main',
            $r['employee_name'] ?? 'N/A',
            intval($r['providers_count'] ?? 0),
            number_format(floatval($r['morning_total'] ?? 0), 2, '.', ''),
            number_format(floatval($r['current_float'] ?? 0), 2, '.', ''),
            number_format(floatval($r['total_deposits'] ?? 0), 2, '.', ''),
            number_format(floatval($r['total_withdrawals'] ?? 0), 2, '.', ''),
            number_format(floatval($r['total_commission'] ?? 0), 2, '.', ''),
            number_format(floatval($r['other_income'] ?? 0), 2, '.', ''),
            number_format(floatval($r['total_expenses'] ?? 0), 2, '.', ''),
            number_format(floatval($r['total_salaries'] ?? 0), 2, '.', ''),
            number_format(floatval($r['total_cash_out'] ?? 0), 2, '.', ''),
            number_format(floatval($r['net_profit'] ?? 0), 2, '.', ''),
            number_format(floatval($r['current_cash'] ?? 0), 2, '.', ''),
            number_format(floatval($r['current_capital'] ?? 0), 2, '.', '')
        ]);
        
        $total_deposits += floatval($r['total_deposits'] ?? 0);
        $total_withdrawals += floatval($r['total_withdrawals'] ?? 0);
        $total_commission += floatval($r['total_commission'] ?? 0);
        $total_other_income += floatval($r['other_income'] ?? 0);
        $total_expenses += floatval($r['total_expenses'] ?? 0);
        $total_salaries += floatval($r['total_salaries'] ?? 0);
        $total_cash_out += floatval($r['total_cash_out'] ?? 0);
        $total_profit += floatval($r['net_profit'] ?? 0);
        $total_capital += floatval($r['current_capital'] ?? 0);
    }
    
    // Totals
    fputcsv($output, []);
    fputcsv($output, [
        'TOTAL',
        '',
        '',
        '',
        count($reports) . ' reports',
        '',
        '',
        number_format($total_deposits, 2, '.', ''),
        number_format($total_withdrawals, 2, '.', ''),
        number_format($total_commission, 2, '.', ''),
        number_format($total_other_income, 2, '.', ''),
        number_format($total_expenses, 2, '.', ''),
        number_format($total_salaries, 2, '.', ''),
        number_format($total_cash_out, 2, '.', ''),
        number_format($total_profit, 2, '.', ''),
        '',
        number_format($total_capital, 2, '.', '')
    ]);
    
    fclose($output);
    exit();
    
// ============================================================
// EXCEL EXPORT
// ============================================================
} elseif ($format == 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="daily_reports_' . date('Y-m-d_His') . '.xls"');
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Daily Reports Export</title>
        <style>
            body { font-family: Arial, sans-serif; }
            h2 { color: #bb0404; margin-bottom: 5px; }
            .info { color: #666; font-size: 12px; margin-bottom: 15px; }
            table { border-collapse: collapse; width: 100%; font-size: 11px; }
            th { 
                background: #bb0404; 
                color: white; 
                padding: 10px 8px; 
                text-align: left; 
                border: 1px solid #8a0303;
                font-weight: bold;
                font-size: 10px;
                text-transform: uppercase;
            }
            th.text-right { text-align: right; }
            td { 
                padding: 8px; 
                border: 1px solid #ddd; 
                font-size: 11px;
            }
            td.text-right { text-align: right; }
            tr:nth-child(even) { background: #f9fafb; }
            .totals-row { 
                background: #fef3c7 !important; 
                font-weight: bold; 
                border-top: 2px solid #bb0404;
            }
            .totals-row td { 
                padding: 10px 8px; 
                font-weight: bold;
                background: #fef3c7;
            }
            .text-success { color: #059669; font-weight: bold; }
            .text-danger { color: #dc2626; font-weight: bold; }
            .amount-float { color: #2563eb; font-weight: bold; }
            .amount-deposit { color: #059669; font-weight: bold; }
            .amount-withdrawal { color: #dc2626; font-weight: bold; }
        </style>
    </head>
    <body>
        <h2>Daily Reports Export</h2>
        <div class="info">
            <strong>Period:</strong> <?php echo $period_label; ?> | 
            <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display_name); ?> | 
            <strong>Reports:</strong> <?php echo count($reports); ?> | 
            <strong>Generated:</strong> <?php echo date('d M Y H:i:s'); ?>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Report No.</th>
                    <th>Date</th>
                    <th>Branch</th>
                    <th>Employee</th>
                    <th class="text-right">Providers</th>
                    <th class="text-right">Morning Float</th>
                    <th class="text-right">Current Float</th>
                    <th class="text-right">Deposits</th>
                    <th class="text-right">Withdrawals</th>
                    <th class="text-right">Commission</th>
                    <th class="text-right">Other Income</th>
                    <th class="text-right">Expenses</th>
                    <th class="text-right">Salaries</th>
                    <th class="text-right">Cash Out</th>
                    <th class="text-right">Net Profit</th>
                    <th class="text-right">Current Cash</th>
                    <th class="text-right">Current Capital</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1; 
                $total_deposits = 0;
                $total_withdrawals = 0;
                $total_commission = 0;
                $total_other_income = 0;
                $total_expenses = 0;
                $total_salaries = 0;
                $total_cash_out = 0;
                $total_profit = 0;
                $total_capital = 0;
                ?>
                <?php foreach ($reports as $r): 
                    $profit = floatval($r['net_profit'] ?? 0);
                ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($r['report_number']); ?></td>
                        <td><?php echo date('d M Y', strtotime($r['report_date'])); ?></td>
                        <td><?php echo htmlspecialchars($r['branch_name'] ?? 'Main'); ?></td>
                        <td><?php echo htmlspecialchars($r['employee_name'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo intval($r['providers_count'] ?? 0); ?></td>
                        <td class="text-right amount-float"><?php echo number_format(floatval($r['morning_total'] ?? 0), 0); ?></td>
                        <td class="text-right amount-float"><?php echo number_format(floatval($r['current_float'] ?? 0), 0); ?></td>
                        <td class="text-right amount-deposit"><?php echo number_format(floatval($r['total_deposits'] ?? 0), 0); ?></td>
                        <td class="text-right amount-withdrawal"><?php echo number_format(floatval($r['total_withdrawals'] ?? 0), 0); ?></td>
                        <td class="text-right amount-deposit"><?php echo number_format(floatval($r['total_commission'] ?? 0), 0); ?></td>
                        <td class="text-right amount-deposit"><?php echo number_format(floatval($r['other_income'] ?? 0), 0); ?></td>
                        <td class="text-right amount-withdrawal"><?php echo number_format(floatval($r['total_expenses'] ?? 0), 0); ?></td>
                        <td class="text-right amount-withdrawal"><?php echo number_format(floatval($r['total_salaries'] ?? 0), 0); ?></td>
                        <td class="text-right amount-withdrawal"><?php echo number_format(floatval($r['total_cash_out'] ?? 0), 0); ?></td>
                        <td class="text-right <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo number_format($profit, 0); ?>
                        </td>
                        <td class="text-right amount-deposit"><?php echo number_format(floatval($r['current_cash'] ?? 0), 0); ?></td>
                        <td class="text-right amount-float"><?php echo number_format(floatval($r['current_capital'] ?? 0), 0); ?></td>
                    </tr>
                    <?php 
                    $total_deposits += floatval($r['total_deposits'] ?? 0);
                    $total_withdrawals += floatval($r['total_withdrawals'] ?? 0);
                    $total_commission += floatval($r['total_commission'] ?? 0);
                    $total_other_income += floatval($r['other_income'] ?? 0);
                    $total_expenses += floatval($r['total_expenses'] ?? 0);
                    $total_salaries += floatval($r['total_salaries'] ?? 0);
                    $total_cash_out += floatval($r['total_cash_out'] ?? 0);
                    $total_profit += $profit;
                    $total_capital += floatval($r['current_capital'] ?? 0);
                    ?>
                <?php endforeach; ?>
                
                <tr class="totals-row">
                    <td colspan="5" style="text-align:right;">TOTAL (<?php echo count($reports); ?> reports)</td>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right">-</td>
                    <td class="text-right amount-deposit"><?php echo number_format($total_deposits, 0); ?></td>
                    <td class="text-right amount-withdrawal"><?php echo number_format($total_withdrawals, 0); ?></td>
                    <td class="text-right amount-deposit"><?php echo number_format($total_commission, 0); ?></td>
                    <td class="text-right amount-deposit"><?php echo number_format($total_other_income, 0); ?></td>
                    <td class="text-right amount-withdrawal"><?php echo number_format($total_expenses, 0); ?></td>
                    <td class="text-right amount-withdrawal"><?php echo number_format($total_salaries, 0); ?></td>
                    <td class="text-right amount-withdrawal"><?php echo number_format($total_cash_out, 0); ?></td>
                    <td class="text-right <?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo number_format($total_profit, 0); ?>
                    </td>
                    <td class="text-right">-</td>
                    <td class="text-right amount-float"><?php echo number_format($total_capital, 0); ?></td>
                </tr>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit();
    
// ============================================================
// PDF / PRINT EXPORT
// ============================================================
} elseif ($format == 'pdf' || $format == 'print') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Daily Reports Export</title>
        <style>
            * { box-sizing: border-box; }
            body { 
                font-family: 'Inter', Arial, sans-serif; 
                padding: 24px; 
                background: #f3f4f6;
                color: #1f2937;
                margin: 0;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: #ffffff;
                border-radius: 12px;
                padding: 24px;
                box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            }
            .header {
                border-bottom: 3px solid #bb0404;
                padding-bottom: 16px;
                margin-bottom: 20px;
            }
            h2 { 
                color: #bb0404; 
                margin: 0 0 8px 0;
                font-size: 22px;
                font-weight: 900;
            }
            .info { 
                color: #6b7280; 
                font-size: 12px; 
                margin: 0;
                line-height: 1.6;
            }
            .info strong { color: #1f2937; }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin-top: 16px; 
                font-size: 11px; 
            }
            th { 
                background: #bb0404; 
                color: white; 
                padding: 10px 8px; 
                text-align: left;
                font-weight: 700;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            th.text-right { text-align: right; }
            td { 
                padding: 9px 8px; 
                border-bottom: 1px solid #e5e7eb;
                font-size: 11px;
            }
            td.text-right { text-align: right; }
            tr:nth-child(even) { background: #f9fafb; }
            .totals-row { 
                background: #fef3c7 !important; 
                font-weight: 900; 
                border-top: 3px solid #bb0404;
            }
            .totals-row td { 
                padding: 12px 8px; 
                background: #fef3c7;
                font-weight: 900;
            }
            .text-success { color: #059669; font-weight: 800; }
            .text-danger { color: #dc2626; font-weight: 800; }
            .amount-float { color: #2563eb; font-weight: 700; }
            .amount-deposit { color: #059669; font-weight: 700; }
            .amount-withdrawal { color: #dc2626; font-weight: 700; }
            
            .no-print { 
                margin-top: 24px; 
                text-align: center;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
            }
            .btn-print {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 28px;
                background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
                color: #ffffff;
                border: none;
                border-radius: 10px;
                font-size: 14px;
                font-weight: 800;
                cursor: pointer;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                box-shadow: 0 4px 12px rgba(187, 4, 4, 0.3);
                transition: all 0.3s ease;
                font-family: 'Inter', sans-serif;
            }
            .btn-print:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(187, 4, 4, 0.45);
            }
            
            @media print {
                body { 
                    background: #ffffff; 
                    padding: 0;
                }
                .container {
                    box-shadow: none;
                    border-radius: 0;
                    padding: 0;
                }
                .no-print { display: none !important; }
                th { background: #bb0404 !important; color: white !important; }
                tr:nth-child(even) { background: #f9fafb !important; }
                .totals-row { background: #fef3c7 !important; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>📊 Daily Reports Export</h2>
                <p class="info">
                    <strong>Period:</strong> <?php echo $period_label; ?> &nbsp;|&nbsp;
                    <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display_name); ?> &nbsp;|&nbsp;
                    <strong>Reports:</strong> <?php echo count($reports); ?> &nbsp;|&nbsp;
                    <strong>Generated:</strong> <?php echo date('d M Y H:i:s'); ?>
                </p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Report No.</th>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Employee</th>
                        <th class="text-right">Providers</th>
                        <th class="text-right">Morning Float</th>
                        <th class="text-right">Current Float</th>
                        <th class="text-right">Deposits</th>
                        <th class="text-right">Withdrawals</th>
                        <th class="text-right">Commission</th>
                        <th class="text-right">Net Profit</th>
                        <th class="text-right">Current Cash</th>
                        <th class="text-right">Capital</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 1; 
                    $total_deposits = 0;
                    $total_withdrawals = 0;
                    $total_commission = 0;
                    $total_profit = 0;
                    $total_capital = 0;
                    ?>
                    <?php foreach ($reports as $r): 
                        $profit = floatval($r['net_profit'] ?? 0);
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['report_number']); ?></strong></td>
                            <td><?php echo date('d M Y', strtotime($r['report_date'])); ?></td>
                            <td><?php echo htmlspecialchars($r['branch_name'] ?? 'Main'); ?></td>
                            <td><?php echo htmlspecialchars($r['employee_name'] ?? 'N/A'); ?></td>
                            <td class="text-right"><?php echo intval($r['providers_count'] ?? 0); ?></td>
                            <td class="text-right amount-float"><?php echo formatCurrency($r['morning_total'] ?? 0); ?></td>
                            <td class="text-right amount-float"><?php echo formatCurrency($r['current_float'] ?? 0); ?></td>
                            <td class="text-right amount-deposit"><?php echo formatCurrency($r['total_deposits'] ?? 0); ?></td>
                            <td class="text-right amount-withdrawal"><?php echo formatCurrency($r['total_withdrawals'] ?? 0); ?></td>
                            <td class="text-right amount-deposit"><?php echo formatCurrency($r['total_commission'] ?? 0); ?></td>
                            <td class="text-right <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo formatCurrency($profit); ?>
                            </td>
                            <td class="text-right amount-deposit"><?php echo formatCurrency($r['current_cash'] ?? 0); ?></td>
                            <td class="text-right amount-float"><?php echo formatCurrency($r['current_capital'] ?? 0); ?></td>
                        </tr>
                        <?php 
                        $total_deposits += floatval($r['total_deposits'] ?? 0);
                        $total_withdrawals += floatval($r['total_withdrawals'] ?? 0);
                        $total_commission += floatval($r['total_commission'] ?? 0);
                        $total_profit += $profit;
                        $total_capital += floatval($r['current_capital'] ?? 0);
                        ?>
                    <?php endforeach; ?>
                    
                    <tr class="totals-row">
                        <td colspan="5" style="text-align:right;">TOTAL (<?php echo count($reports); ?> reports)</td>
                        <td class="text-right">-</td>
                        <td class="text-right">-</td>
                        <td class="text-right">-</td>
                        <td class="text-right amount-deposit"><?php echo formatCurrency($total_deposits); ?></td>
                        <td class="text-right amount-withdrawal"><?php echo formatCurrency($total_withdrawals); ?></td>
                        <td class="text-right amount-deposit"><?php echo formatCurrency($total_commission); ?></td>
                        <td class="text-right <?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($total_profit); ?>
                        </td>
                        <td class="text-right">-</td>
                        <td class="text-right amount-float"><?php echo formatCurrency($total_capital); ?></td>
                    </tr>
                </tbody>
            </table>
            
            <div class="no-print">
                <button class="btn-print" onclick="window.print()">
                    🖨️ Print / Save as PDF
                </button>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Default fallback
header('Location: index.php');
exit();
?>