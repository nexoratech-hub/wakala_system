<?php
// ================================================================
// FILE: modules/expenses/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE EXPENSE
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

if ($role !== 'admin' && $role !== 'super_admin') {
    $_SESSION['error_message'] = 'You do not have permission to delete expenses.';
    header('Location: index_employee.php');
    exit();
}

$expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($expense_id <= 0) {
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM expenses WHERE id = ?");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    $_SESSION['error_message'] = 'Expense not found.';
    header('Location: index.php');
    exit();
}

$delete_branch_id = intval($expense['branch_id']);

try {
    // Delete receipt file
    if (!empty($expense['receipt_path']) && file_exists('../../' . $expense['receipt_path'])) {
        @unlink('../../' . $expense['receipt_path']);
    }
    
    logActivity(
        $user_id,
        'Delete Expense',
        'Expenses',
        $expense_id,
        '',
        'Deleted expense: ' . $expense['expense_number'] . ' - ' . formatCurrency($expense['amount'])
    );
    
    $stmt = $db->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->execute([$expense_id]);
    
    $_SESSION['success_message'] = 'Expense "' . $expense['expense_number'] . '" deleted successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

$redirect = 'index.php';
if ($delete_branch_id > 0) {
    $redirect .= '?branch_id=' . $delete_branch_id;
}

header('Location: ' . $redirect);
exit();
?>