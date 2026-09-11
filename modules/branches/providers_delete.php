<?php
// ================================================================
// FILE: modules/branches/providers_delete.php
// WAKALA FINANCIAL SYSTEM - DELETE BRANCH PROVIDER
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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get IDs
$provider_link_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($provider_link_id <= 0 || $branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// Get branch provider details
$stmt = $db->prepare("SELECT bp.*, p.provider_name, b.branch_name 
                      FROM branch_providers bp 
                      JOIN providers p ON bp.provider_id = p.id 
                      JOIN branches b ON bp.branch_id = b.id
                      WHERE bp.id = ? AND bp.branch_id = ?");
$stmt->execute([$provider_link_id, $branch_id]);
$provider_link = $stmt->fetch();

if (!$provider_link) {
    header('Location: providers.php?branch_id=' . $branch_id);
    exit();
}

// Delete branch provider
try {
    $stmt = $db->prepare("DELETE FROM branch_providers WHERE id = ? AND branch_id = ?");
    $stmt->execute([$provider_link_id, $branch_id]);
    
    logActivity($user_id, 'Delete Branch Provider', 'Branch Providers', $branch_id, '', 
                "Removed {$provider_link['provider_name']} ({$provider_link['provider_code']}) from {$provider_link['branch_name']}");
    
    $_SESSION['success_message'] = 'Provider "' . $provider_link['provider_name'] . '" with code "' . $provider_link['provider_code'] . '" removed successfully!';
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error removing provider: ' . $e->getMessage();
}

header('Location: providers.php?branch_id=' . $branch_id);
exit();
?>