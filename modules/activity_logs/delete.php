<?php
// ================================================================
// FILE: modules/activity_logs/delete.php
// DELETE SINGLE ACTIVITY LOG
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

// Only super_admin can delete logs
if ($role !== 'super_admin') {
    header('Location: index.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get log to log deletion
    $stmt = $db->prepare("SELECT * FROM activity_logs WHERE id = ?");
    $stmt->execute([$id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($log) {
        $stmt = $db->prepare("DELETE FROM activity_logs WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log the deletion
        logActivity($user_id, 'Delete Activity Log', 'Activity Logs', $id, 
                    json_encode($log), 'Deleted');
    }
} catch (PDOException $e) {
    error_log("Error deleting activity log: " . $e->getMessage());
}

header('Location: index.php?deleted=1');
exit();
?>