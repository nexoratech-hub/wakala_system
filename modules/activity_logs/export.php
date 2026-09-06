<?php
// ================================================================
// FILE: modules/activity_logs/export.php
// EXPORT ACTIVITY LOGS
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: index.php');
    exit();
}

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$module_filter = isset($_GET['module']) ? $_GET['module'] : '';
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$employee_filter = isset($_GET['employee']) ? intval($_GET['employee']) : 0;

try {
    $sql = "SELECT al.*, 
            e.full_name as employee_name,
            b.branch_name
            FROM activity_logs al
            LEFT JOIN employees e ON al.employee_id = e.id
            LEFT JOIN branches b ON al.branch_id = b.id
            WHERE DATE(al.created_at) BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if (!empty($module_filter)) {
        $sql .= " AND al.module = ?";
        $params[] = $module_filter;
    }

    if (!empty($action_filter)) {
        $sql .= " AND al.action LIKE ?";
        $params[] = '%' . $action_filter . '%';
    }

    if ($employee_filter > 0) {
        $sql .= " AND al.employee_id = ?";
        $params[] = $employee_filter;
    }

    $sql .= " ORDER BY al.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error exporting logs: " . $e->getMessage());
    $logs = [];
}

if ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Date & Time', 'Employee', 'Module', 'Action', 'Record ID', 'IP Address', 'Branch']);
    
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['id'],
            date('Y-m-d H:i:s', strtotime($log['created_at'])),
            $log['employee_name'] ?? 'Unknown',
            $log['module'],
            $log['action'],
            $log['record_id'] ?? 'N/A',
            $log['ip_address'] ?? 'N/A',
            $log['branch_name'] ?? 'N/A'
        ]);
    }
    fclose($output);
    exit();
    
} elseif ($format == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>Activity Logs Export</title>';
    echo '<style>th{background:#bb0404;color:white;padding:8px;}td{padding:6px;border:1px solid #ccc;}</style>';
    echo '</head><body>';
    echo '<h2>Activity Logs Export</h2>';
    echo '<p>Period: ' . date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date)) . '</p>';
    echo '<table>';
    echo '<tr><th>ID</th><th>Date & Time</th><th>Employee</th><th>Module</th><th>Action</th><th>Record ID</th><th>IP Address</th><th>Branch</th></tr>';
    
    foreach ($logs as $log) {
        echo '<tr>';
        echo '<td>' . $log['id'] . '</td>';
        echo '<td>' . date('Y-m-d H:i:s', strtotime($log['created_at'])) . '</td>';
        echo '<td>' . htmlspecialchars($log['employee_name'] ?? 'Unknown') . '</td>';
        echo '<td>' . htmlspecialchars($log['module']) . '</td>';
        echo '<td>' . htmlspecialchars($log['action']) . '</td>';
        echo '<td>' . ($log['record_id'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($log['ip_address'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($log['branch_name'] ?? 'N/A') . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit();
}

header('Location: index.php');
exit();
?>