<?php
// ================================================================
// FILE: modules/capital_management/export.php
// EXPORT CAPITAL TRANSACTIONS
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
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

try {
    // Build query
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
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

    $sql .= " ORDER BY cm.transaction_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error exporting transactions: " . $e->getMessage());
    $transactions = [];
}

$type_labels = [
    'opening' => 'Opening',
    'additional' => 'Additional',
    'profit_allocation' => 'Profit Allocation',
    'cash_out' => 'Cash Out',
    'adjustment' => 'Adjustment'
];

if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="capital_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Capital No.', 'Date', 'Type', 'Amount', 'Branch', 'Employee', 'Description', 'Notes']);
    
    foreach ($transactions as $t) {
        fputcsv($output, [
            $t['capital_number'],
            date('Y-m-d', strtotime($t['transaction_date'])),
            $type_labels[$t['transaction_type']] ?? $t['transaction_type'],
            number_format($t['amount'], 2),
            $t['branch_name'] ?? 'Main',
            $t['employee_name'] ?? 'N/A',
            $t['description'] ?? '',
            $t['notes'] ?? ''
        ]);
    }
    fclose($output);
    exit();
    
} elseif ($format == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="capital_export_' . date('Y-m-d') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>Capital Export</title>';
    echo '<style>th{background:#bb0404;color:white;padding:8px;}td{padding:6px;border:1px solid #ccc;}</style>';
    echo '</head><body>';
    echo '<h2>Capital Transactions Export</h2>';
    echo '<p>Period: ' . date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date)) . '</p>';
    echo '<table>';
    echo '<tr><th>Capital No.</th><th>Date</th><th>Type</th><th>Amount</th><th>Branch</th><th>Employee</th><th>Description</th><th>Notes</th></tr>';
    
    foreach ($transactions as $t) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($t['capital_number']) . '</td>';
        echo '<td>' . date('Y-m-d', strtotime($t['transaction_date'])) . '</td>';
        echo '<td>' . ($type_labels[$t['transaction_type']] ?? $t['transaction_type']) . '</td>';
        echo '<td>' . number_format($t['amount'], 2) . '</td>';
        echo '<td>' . htmlspecialchars($t['branch_name'] ?? 'Main') . '</td>';
        echo '<td>' . htmlspecialchars($t['employee_name'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($t['description'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($t['notes'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit();
    
} elseif ($format == 'pdf' || $format == 'print') {
    // Simple print version
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Capital Export</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            h2 { color: #bb0404; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #bb0404; color: white; padding: 8px; text-align: left; }
            td { padding: 6px; border-bottom: 1px solid #ccc; }
            .total { margin-top: 20px; font-weight: bold; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <h2>Capital Transactions Export</h2>
        <p>Period: <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?></p>
        <table>
            <tr><th>#</th><th>Capital No.</th><th>Date</th><th>Type</th><th>Amount</th><th>Branch</th><th>Employee</th><th>Description</th></tr>
            <?php $i = 1; $total = 0; ?>
            <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($t['capital_number']); ?></td>
                    <td><?php echo date('d M Y', strtotime($t['transaction_date'])); ?></td>
                    <td><?php echo $type_labels[$t['transaction_type']] ?? $t['transaction_type']; ?></td>
                    <td><?php echo formatCurrency($t['amount']); ?></td>
                    <td><?php echo htmlspecialchars($t['branch_name'] ?? 'Main'); ?></td>
                    <td><?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars(substr($t['description'] ?? '', 0, 30)); ?></td>
                </tr>
                <?php $total += floatval($t['amount']); ?>
            <?php endforeach; ?>
        </table>
        <div class="total">Total Amount: <?php echo formatCurrency($total); ?></div>
        <div class="no-print" style="margin-top:20px;"><button onclick="window.print()">Print</button></div>
    </body>
    </html>
    <?php
    exit();
}

header('Location: index.php');
exit();
?>