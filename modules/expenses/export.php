<?php
// ================================================================
// FILE: modules/expenses/export.php
// EXPORT EXPENSES
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

// Check permission
if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
$is_business = isset($_GET['is_business']) ? intval($_GET['is_business']) : -1;

try {
    $sql = "SELECT e.*, 
            emp.full_name as employee_name,
            b.branch_name
            FROM expenses e
            LEFT JOIN employees emp ON e.employee_id = emp.id
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE DATE(e.expense_date) BETWEEN ? AND ?";
    $params = [$from_date, $to_date];
    
    if ($role === 'employee') {
        $sql .= " AND e.employee_id = ?";
        $params[] = $user_id;
    }

    if (!empty($category_filter)) {
        $sql .= " AND e.category = ?";
        $params[] = $category_filter;
    }

    if ($selected_branch > 0 && ($role === 'admin' || $role === 'super_admin')) {
        $sql .= " AND e.branch_id = ?";
        $params[] = $selected_branch;
    }

    if ($is_business >= 0) {
        $sql .= " AND e.is_business_expense = ?";
        $params[] = $is_business;
    }

    $sql .= " ORDER BY e.expense_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error exporting expenses: " . $e->getMessage());
    $expenses = [];
}

if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Expense No.', 'Date', 'Category', 'Name', 'Amount', 'Type', 'Branch', 'Employee', 'Description']);
    
    foreach ($expenses as $e) {
        fputcsv($output, [
            $e['expense_number'],
            date('Y-m-d', strtotime($e['expense_date'])),
            $e['category'],
            $e['expense_name'],
            number_format($e['amount'], 2),
            $e['is_business_expense'] ? 'Business' : 'Personal',
            $e['branch_name'] ?? 'Main',
            $e['employee_name'] ?? 'N/A',
            $e['description'] ?? ''
        ]);
    }
    fclose($output);
    exit();
    
} elseif ($format == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="expenses_' . date('Y-m-d') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>Expenses Export</title>';
    echo '<style>th{background:#bb0404;color:white;padding:8px;}td{padding:6px;border:1px solid #ccc;}</style>';
    echo '</head><body>';
    echo '<h2>Expenses Export</h2>';
    echo '<p>Period: ' . date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date)) . '</p>';
    echo '<table>';
    echo '<tr><th>Expense No.</th><th>Date</th><th>Category</th><th>Name</th><th>Amount</th><th>Type</th><th>Branch</th><th>Employee</th><th>Description</th></tr>';
    
    $total = 0;
    foreach ($expenses as $e) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($e['expense_number']) . '</td>';
        echo '<td>' . date('Y-m-d', strtotime($e['expense_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($e['category']) . '</td>';
        echo '<td>' . htmlspecialchars($e['expense_name']) . '</td>';
        echo '<td>' . number_format($e['amount'], 2) . '</td>';
        echo '<td>' . ($e['is_business_expense'] ? 'Business' : 'Personal') . '</td>';
        echo '<td>' . htmlspecialchars($e['branch_name'] ?? 'Main') . '</td>';
        echo '<td>' . htmlspecialchars($e['employee_name'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($e['description'] ?? '') . '</td>';
        echo '</tr>';
        $total += floatval($e['amount']);
    }
    echo '<tr style="font-weight:bold;background:#f3f4f6;">';
    echo '<td colspan="4" style="text-align:right;">TOTAL</td>';
    echo '<td>' . number_format($total, 2) . '</td>';
    echo '<td colspan="4"></td>';
    echo '</tr>';
    echo '</table></body></html>';
    exit();
}

header('Location: index.php');
exit();
?>