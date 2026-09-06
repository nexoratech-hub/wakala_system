<?php
// ================================================================
// FILE: modules/providers/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE PROVIDER
// ================================================================

// ============================================================
// INCLUDE CONFIG BEFORE SESSION
// ============================================================
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK LOGIN
// ============================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

// ============================================================
// CHECK PERMISSION - Only admin and super_admin can access
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET PROVIDER ID
// ============================================================
$provider_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($provider_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE id = ?");
$stmt->execute([$provider_id]);
$provider = $stmt->fetch();

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// PROCESS DELETE
// ============================================================
try {
    // Log activity before deletion
    logActivity($user_id, 'Delete Provider', 'Providers', $provider_id, 
        json_encode([
            'code' => $provider['provider_code'],
            'name' => $provider['provider_name'],
            'type' => $provider['provider_type']
        ]), 
        'Deleted provider: ' . $provider['provider_name'] . ' (' . $provider['provider_code'] . ')', 
        null);

    // Delete provider
    $delete_stmt = $db->prepare("DELETE FROM providers WHERE id = ?");
    $delete_stmt->execute([$provider_id]);

    $_SESSION['success_message'] = 'Provider "' . htmlspecialchars($provider['provider_name']) . '" deleted successfully!';
    
} catch (Exception $e) {
    // Check if it's a foreign key constraint error
    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        $_SESSION['error_message'] = 'Cannot delete this provider because it is being used in other records.';
    } else {
        $_SESSION['error_message'] = 'Error deleting provider: ' . $e->getMessage();
    }
}

header('Location: index.php');
exit();
?>