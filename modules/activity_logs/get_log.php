<?php
// ================================================================
// FILE: modules/activity_logs/get_log.php
// GET LOG DETAILS - AJAX
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit();
}

try {
    $stmt = $db->prepare("SELECT al.*, 
            e.full_name as employee_name,
            b.branch_name
            FROM activity_logs al
            LEFT JOIN employees e ON al.employee_id = e.id
            LEFT JOIN branches b ON al.branch_id = b.id
            WHERE al.id = ?");
    $stmt->execute([$id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$log) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Log not found']);
        exit();
    }
    
    $html = '
        <div class="log-details">
            <div class="detail-row">
                <span class="label">ID</span>
                <span class="value">' . htmlspecialchars($log['id']) . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Date & Time</span>
                <span class="value">' . date('d M Y H:i:s', strtotime($log['created_at'])) . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Employee</span>
                <span class="value">' . htmlspecialchars($log['employee_name'] ?? 'Unknown') . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Module</span>
                <span class="value">' . htmlspecialchars($log['module']) . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Action</span>
                <span class="value">' . htmlspecialchars($log['action']) . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Branch</span>
                <span class="value">' . htmlspecialchars($log['branch_name'] ?? 'N/A') . '</span>
            </div>
            <div class="detail-row">
                <span class="label">IP Address</span>
                <span class="value">' . htmlspecialchars($log['ip_address'] ?? 'N/A') . '</span>
            </div>
            <div class="detail-row">
                <span class="label">User Agent</span>
                <span class="value" style="font-size:12px;word-break:break-all;">' . htmlspecialchars($log['user_agent'] ?? 'N/A') . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Record ID</span>
                <span class="value">' . ($log['record_id'] ? htmlspecialchars($log['record_id']) : 'N/A') . '</span>
            </div>
            <div class="detail-row">
                <span class="label">Old Value</span>
                <span class="value"><pre style="background:#f3f4f6;padding:8px;border-radius:4px;font-size:12px;max-height:150px;overflow:auto;">' . htmlspecialchars($log['old_value'] ?? 'N/A') . '</pre></span>
            </div>
            <div class="detail-row">
                <span class="label">New Value</span>
                <span class="value"><pre style="background:#f3f4f6;padding:8px;border-radius:4px;font-size:12px;max-height:150px;overflow:auto;">' . htmlspecialchars($log['new_value'] ?? 'N/A') . '</pre></span>
            </div>
        </div>
        <style>
            .log-details .detail-row {
                display: flex;
                padding: 6px 0;
                border-bottom: 1px solid #e5e7eb;
            }
            .log-details .detail-row:last-child {
                border-bottom: none;
            }
            .log-details .label {
                font-weight: 600;
                color: #6b7280;
                width: 120px;
                flex-shrink: 0;
                font-size: 13px;
            }
            .log-details .value {
                color: #1f2937;
                font-size: 13px;
                flex: 1;
            }
            body.dark-mode .log-details .detail-row {
                border-bottom-color: #334155;
            }
            body.dark-mode .log-details .label {
                color: #94a3b8;
            }
            body.dark-mode .log-details .value {
                color: #f1f5f9;
            }
            body.dark-mode .log-details .value pre {
                background: #334155;
                color: #f1f5f9;
            }
        </style>
    ';
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>