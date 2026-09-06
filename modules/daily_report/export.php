<?php
// ================================================================
// FILE: modules/daily_report/export.php
// EXPORT DAILY REPORTS
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

try {
    $sql = "SELECT dr.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            p.provider_name
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN providers p ON dr.provider_id = p.id
            WHERE dr.report_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND dr.branch_id = ?";
        $params[] = $selected_branch;
    }

    $sql .= " ORDER BY dr.report_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error exporting reports: " . $e->getMessage());
    $reports = [];
}

if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="daily_reports_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Report No.', 'Date', 'Branch', 'Provider', 'Deposits', 'Withdrawals', 
                      'Commission', 'Other Income', 'Expenses', 'Salaries', 'Cash Out', 'Net Profit', 'Capital']);
    
    foreach ($reports as $r) {
        fputcsv($output, [
            $r['report_number'],
            date('Y-m-d', strtotime($r['report_date'])),
            $r['branch_name'] ?? 'Main',
            $r['provider_name'] ?? 'N/A',
            number_format($r['total_deposits'], 2),
            number_format($r['total_withdrawals'], 2),
            number_format($r['total_commission'], 2),
            number_format($r['other_income'], 2),
            number_format($r['total_expenses'], 2),
            number_format($r['total_salaries'], 2),
            number_format($r['total_cash_out'], 2),
            number_format($r['net_profit'], 2),
            number_format($r['current_capital'], 2)
        ]);
    }
    fclose($output);
    exit();
    
} elseif ($format == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="daily_reports_' . date('Y-m-d') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>Daily Reports Export</title>';
    echo '<style>th{background:#bb0404;color:white;padding:8px;}td{padding:6px;border:1px solid #ccc;}</style>';
    echo '</head><body>';
    echo '<h2>Daily Reports Export</h2>';
    echo '<p>Period: ' . date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date)) . '</p>';
    echo '<table>';
    echo '<tr><th>Report No.</th><th>Date</th><th>Branch</th><th>Provider</th><th>Deposits</th><th>Withdrawals</th>';
    echo '<th>Commission</th><th>Other Income</th><th>Expenses</th><th>Salaries</th><th>Cash Out</th><th>Net Profit</th><th>Capital</th></tr>';
    
    foreach ($reports as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['report_number']) . '</td>';
        echo '<td>' . date('Y-m-d', strtotime($r['report_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($r['branch_name'] ?? 'Main') . '</td>';
        echo '<td>' . htmlspecialchars($r['provider_name'] ?? 'N/A') . '</td>';
        echo '<td>' . number_format($r['total_deposits'], 2) . '</td>';
        echo '<td>' . number_format($r['total_withdrawals'], 2) . '</td>';
        echo '<td>' . number_format($r['total_commission'], 2) . '</td>';
        echo '<td>' . number_format($r['other_income'], 2) . '</td>';
        echo '<td>' . number_format($r['total_expenses'], 2) . '</td>';
        echo '<td>' . number_format($r['total_salaries'], 2) . '</td>';
        echo '<td>' . number_format($r['total_cash_out'], 2) . '</td>';
        echo '<td>' . number_format($r['net_profit'], 2) . '</td>';
        echo '<td>' . number_format($r['current_capital'], 2) . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit();
    
} elseif ($format == 'pdf' || $format == 'print') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Daily Reports Export</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            h2 { color: #bb0404; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
            th { background: #bb0404; color: white; padding: 8px; text-align: left; }
            td { padding: 6px; border-bottom: 1px solid #ccc; }
            .total { margin-top: 20px; font-weight: bold; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <h2>Daily Reports Export</h2>
        <p>Period: <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?></p>
        <table>
            <tr><th>#</th><th>Report No.</th><th>Date</th><th>Branch</th><th>Deposits</th><th>Withdrawals</th><th>Commission</th><th>Profit</th></tr>
            <?php $i = 1; $total_deposits = 0; $total_withdrawals = 0; $total_profit = 0; ?>
            <?php foreach ($reports as $r): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($r['report_number']); ?></td>
                    <td><?php echo date('d M Y', strtotime($r['report_date'])); ?></td>
                    <td><?php echo htmlspecialchars($r['branch_name'] ?? 'Main'); ?></td>
                    <td><?php echo formatCurrency($r['total_deposits']); ?></td>
                    <td><?php echo formatCurrency($r['total_withdrawals']); ?></td>
                    <td><?php echo formatCurrency($r['total_commission']); ?></td>
                    <td class="<?php echo $r['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($r['net_profit']); ?>
                    </td>
                </tr>
                <?php $total_deposits += floatval($r['total_deposits']); ?>
                <?php $total_withdrawals += floatval($r['total_withdrawals']); ?>
                <?php $total_profit += floatval($r['net_profit']); ?>
            <?php endforeach; ?>
            <tr style="font-weight:bold;background:#f3f4f6;">
                <td colspan="4" style="text-align:right;">TOTAL</td>
                <td><?php echo formatCurrency($total_deposits); ?></td>
                <td><?php echo formatCurrency($total_withdrawals); ?></td>
                <td></td>
                <td class="<?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo formatCurrency($total_profit); ?>
                </td>
            </tr>
        </table>
        <div class="no-print" style="margin-top:20px;"><button onclick="window.print()">Print</button></div>
    </body>
    </html>
    <?php
    exit();
}

header('Location: index.php');
exit();
?>