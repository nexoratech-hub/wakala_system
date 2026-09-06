<?php
// ================================================================
// FILE: modules/expenses/delete.php
// DELETE EXPENSE
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get expense to log and check ownership
    $sql = "SELECT * FROM expenses WHERE id = ?";
    $params = [$id];
    
    if ($role === 'employee') {
        $sql .= " AND employee_id = ?";
        $params[] = $user_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($expense) {
        // Delete receipt if exists
        if ($expense['receipt_path'] && file_exists('../../' . $expense['receipt_path'])) {
            unlink('../../' . $expense['receipt_path']);
        }
        
        // Delete from database
        $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([$id]);
        
        logActivity($user_id, 'Delete Expense', 'Expenses', $id, 
                    json_encode($expense), 'Deleted');
    }
} catch (PDOException $e) {
    error_log("Error deleting expense: " . $e->getMessage());
}

header('Location: index.php?deleted=1');
exit();
?>