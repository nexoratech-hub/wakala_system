<?php
// ================================================================
// FILE: modules/capital_management/delete.php
// DELETE CAPITAL TRANSACTION
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get transaction to log
    $stmt = $db->prepare("SELECT capital_number, amount, transaction_type FROM capital_management WHERE id = ?");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transaction) {
        // Delete
        $stmt = $db->prepare("DELETE FROM capital_management WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            logActivity($user_id, 'Delete Capital', 'Capital Management', $id, 
                        json_encode($transaction), 'Deleted');
        }
    }
} catch (PDOException $e) {
    error_log("Error deleting capital transaction: " . $e->getMessage());
}

header('Location: index.php?deleted=1');
exit();
?>