<?php
// ================================================================
// FILE: modules/employees/get_next_id.php
// Returns the next auto-generated employee ID for a branch
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
if ($role !== 'admin' && $role !== 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit();
}

$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($branch_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid branch']);
    exit();
}

try {
    // Get branch code
    $stmt = $db->prepare("SELECT branch_code, branch_name FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        echo json_encode(['success' => false, 'error' => 'Branch not found']);
        exit();
    }

    $branch_code = !empty($branch['branch_code']) 
        ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $branch['branch_code'])) 
        : 'BR' . str_pad($branch_id, 3, '0', STR_PAD_LEFT);

    // Count existing employees in this branch
    $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $count = intval($stmt->fetchColumn());

    // Next number
    $next_num = $count + 1;

    // Format: BR001-EMP-0001
    $employee_id = $branch_code . '-EMP-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    // Safety: loop until we find an unused ID (in case of deletions)
    $attempts = 0;
    while ($attempts < 1000) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        if ($stmt->fetchColumn() == 0) break;
        $next_num++;
        $employee_id = $branch_code . '-EMP-' . str_pad($next_num, 4, '0', STR_PAD_LEFT);
        $attempts++;
    }

    echo json_encode([
        'success'     => true,
        'employee_id' => $employee_id,
        'branch_code' => $branch_code,
        'next_number' => $next_num
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}