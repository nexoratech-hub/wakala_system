<?php
// ================================================================
// FILE: modules/activity_logs/export.php
// WAKALA FINANCIAL SYSTEM - EXPORT ACTIVITY LOGS
// ✅ Formats: CSV, Excel, PDF/Print
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
$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$selected_employee = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$selected_module = isset($_GET['module']) ? trim($_GET['module']) : '';
$selected_action = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where = ["DATE(al.created_at) BETWEEN ? AND ?"];
$params = [$from_date, $to_date];

if ($selected_branch > 0) {
    $where[] = "al.branch_id = ?";
    $params[] = $selected_branch;
}
if ($selected_employee > 0) {
    $where[] = "al.employee_id = ?";
    $params[] = $selected_employee;
}
if (!empty($selected_module)) {
    $where[] = "al.module = ?";
    $params[] = $selected_module;
}
if (!empty($selected_action)) {
    $where[] = "al.action = ?";
    $params[] = $selected_action;
}
if (!empty($search_term)) {
    $where[] = "(al.description LIKE ? OR al.action LIKE ? OR e.full_name LIKE ? OR al.ip_address LIKE ?)";
    $search_like = '%' . $search_term . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
}

$where_sql = implode(' AND ', $where);

try {
    $stmt = $db->prepare("
        SELECT 
            al.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            b.branch_name
        FROM activity_logs al
        LEFT JOIN employees e ON al.employee_id = e.id
        LEFT JOIN branches b ON al.branch_id = b.id
        WHERE $where_sql
        ORDER BY al.created_at DESC, al.id DESC
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Export error: " . $e->getMessage());
    $logs = [];
}

$branch_display = 'All Branches';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
    $stmt->execute([$selected_branch]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($b) $branch_display = $b['branch_name'];
}

$period_label = date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date));
$generated_at = date('d M Y H:i:s');

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
    
    // Header
    fputcsv($output, ['Activity Logs Export']);
    fputcsv($output, ['Period:', $period_label]);
    fputcsv($output, ['Branch:', $branch_display]);
    fputcsv($output, ['Generated:', $generated_at]);
    fputcsv($output, ['Total Records:', count($logs)]);
    fputcsv($output, []);
    
    // Columns
    fputcsv($output, [
        '#', 'Date & Time', 'Employee', 'Employee ID', 'Branch',
        'Module', 'Action', 'Description', 'IP Address'
    ]);
    
    $i = 1;
    foreach ($logs as $log) {
        fputcsv($output, [
            $i++,
            date('Y-m-d H:i:s', strtotime($log['created_at'])),
            $log['employee_name'] ?? 'System',
            $log['employee_code'] ?? '-',
            $log['branch_name'] ?? '-',
            $log['module'] ?? '-',
            $log['action'] ?? '-',
            $log['description'] ?? '-',
            $log['ip_address'] ?? '-'
        ]);
    }
    
    fclose($output);
    exit();
}

// ============================================================
// EXCEL EXPORT
// ============================================================
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d_His') . '.xls"');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Activity Logs Export</title>
        <style>
            body { font-family: Arial, sans-serif; }
            h2 { color: #7C3AED; margin-bottom: 5px; }
            .info { color: #666; font-size: 12px; margin-bottom: 15px; }
            table { border-collapse: collapse; width: 100%; font-size: 11px; }
            th {
                background: #7C3AED; color: white;
                padding: 10px 8px; text-align: left;
                border: 1px solid #6D28D9;
                font-weight: bold;
                text-transform: uppercase;
            }
            td {
                padding: 8px; border: 1px solid #ddd;
                vertical-align: top;
            }
            tr:nth-child(even) { background: #f9fafb; }
        </style>
    </head>
    <body>
        <h2>Activity Logs Export</h2>
        <div class="info">
            <strong>Period:</strong> <?php echo $period_label; ?> |
            <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display); ?> |
            <strong>Records:</strong> <?php echo count($logs); ?> |
            <strong>Generated:</strong> <?php echo $generated_at; ?>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date & Time</th>
                    <th>Employee</th>
                    <th>Employee ID</th>
                    <th>Branch</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d M Y H:i:s', strtotime($log['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($log['employee_name'] ?? 'System'); ?></td>
                        <td><?php echo htmlspecialchars($log['employee_code'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['branch_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['module'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['action'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['description'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// PDF / PRINT EXPORT
// ============================================================
if ($format === 'pdf' || $format === 'print') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Activity Logs - <?php echo $period_label; ?></title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: 'Inter', Arial, sans-serif;
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
            .header {
                border-bottom: 3px solid #7C3AED;
                padding-bottom: 16px;
                margin-bottom: 20px;
            }
            .header h1 {
                color: #7C3AED;
                font-size: 22px;
                margin: 0 0 6px 0;
                font-weight: 900;
            }
            .header .subtitle {
                color: #6B7280;
                font-size: 12px;
                line-height: 1.6;
            }
            .header .subtitle strong { color: #1F2937; }
            
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10.5px;
                margin-top: 16px;
            }
            thead {
                background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
            }
            thead th {
                color: #FFFFFF;
                padding: 10px 8px;
                text-align: left;
                font-weight: 700;
                font-size: 9px;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                border-right: 1px solid rgba(255, 255, 255, 0.15);
            }
            thead th:last-child { border-right: none; }
            
            tbody td {
                padding: 8px;
                border-bottom: 1px solid #E5E7EB;
                vertical-align: top;
            }
            tbody tr:nth-child(even) { background: #F9FAFB; }
            
            .row-num {
                font-family: 'Courier New', monospace;
                font-weight: 800;
                color: #7C3AED;
            }
            
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
                background: linear-gradient(135deg, #7C3AED, #6D28D9);
                color: #FFFFFF;
                border: none;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 800;
                cursor: pointer;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                box-shadow: 0 4px 16px rgba(124, 58, 237, 0.4);
                transition: all 0.3s ease;
                font-family: inherit;
            }
            .btn-print:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 24px rgba(124, 58, 237, 0.55);
            }
            
            @media print {
                body { background: #FFFFFF; padding: 0; font-size: 10px; }
                .container { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
                .print-section { display: none !important; }
                thead { display: table-header-group; }
                tr { break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>📋 Activity Logs Export</h1>
                <div class="subtitle">
                    <strong>Period:</strong> <?php echo $period_label; ?> &nbsp;|&nbsp;
                    <strong>Branch:</strong> <?php echo htmlspecialchars($branch_display); ?> &nbsp;|&nbsp;
                    <strong>Records:</strong> <?php echo count($logs); ?> &nbsp;|&nbsp;
                    <strong>Generated:</strong> <?php echo $generated_at; ?>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">#</th>
                        <th>Date & Time</th>
                        <th>Employee</th>
                        <th>Branch</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:30px; color:#9CA3AF; font-style:italic;">
                                No activity logs found in this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($logs as $log): ?>
                            <tr>
                                <td class="row-num"><?php echo $i++; ?></td>
                                <td><?php echo date('d M Y H:i', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['employee_name'] ?? 'System'); ?></td>
                                <td><?php echo htmlspecialchars($log['branch_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($log['module'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($log['action'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(substr($log['description'] ?? '-', 0, 80)); ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="print-section">
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

header('Location: index.php');
exit();
?>