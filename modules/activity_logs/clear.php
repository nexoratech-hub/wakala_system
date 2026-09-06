<?php
// ================================================================
// FILE: modules/activity_logs/clear.php
// CLEAR ALL ACTIVITY LOGS
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

// Only super_admin can clear logs
if ($role !== 'super_admin') {
    header('Location: index.php');
    exit();
}

try {
    // Count logs before deletion
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM activity_logs");
    $stmt->execute();
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Delete all logs
    $stmt = $db->prepare("DELETE FROM activity_logs");
    $stmt->execute();
    
    // Log the clear action
    logActivity($user_id, 'Clear All Activity Logs', 'Activity Logs', null, 
                json_encode(['deleted_count' => $count]), 'Cleared all logs');
    
    $_SESSION['success'] = 'All activity logs have been cleared (' . number_format($count) . ' records deleted)';
} catch (PDOException $e) {
    error_log("Error clearing activity logs: " . $e->getMessage());
    $_SESSION['error'] = 'Failed to clear activity logs: ' . $e->getMessage();
}

header('Location: index.php');
exit();
?>