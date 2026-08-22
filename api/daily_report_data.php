<?php
// ================================================================
// FILE: api/daily_report_data.php
// ================================================================

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
$today = date('Y-m-d');

// Get daily reports per provider
if ($selected_branch > 0) {
    $sql = "SELECT dr.*, p.provider_name, b.branch_name
            FROM daily_reports dr
            LEFT JOIN providers p ON dr.provider_id = p.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            WHERE dr.report_date = ? AND dr.branch_id = ?
            ORDER BY p.display_order";
    $params = [$today, $selected_branch];
} else {
    $sql = "SELECT dr.*, p.provider_name, b.branch_name
            FROM daily_reports dr
            LEFT JOIN providers p ON dr.provider_id = p.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            WHERE dr.report_date = ?
            ORDER BY b.branch_name, p.display_order";
    $params = [$today];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$daily_reports = $stmt->fetchAll();

// Calculate totals
$total_float = 0;
$total_cash = 0;
$total_deposits = 0;
$total_withdrawals = 0;
foreach ($daily_reports as $dr) {
    $total_float += $dr['provider_float'] ?? 0;
    $total_cash += $dr['provider_cash'] ?? 0;
    $total_deposits += $dr['provider_deposits'] ?? 0;
    $total_withdrawals += $dr['provider_withdrawals'] ?? 0;
}
$total_stock = $total_float + $total_cash;

$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'data' => [
        'daily_reports' => $daily_reports,
        'total_float' => $total_float,
        'total_cash' => $total_cash,
        'total_stock' => $total_stock,
        'total_deposits' => $total_deposits,
        'total_withdrawals' => $total_withdrawals
    ]
];

header('Content-Type: application/json');
echo json_encode($response);
exit();