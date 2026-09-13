<?php
// ================================================================
// FILE: modules/transfers/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE TRANSFER
// Reverses financial impact before deleting the transfer
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'employee';

// ============================================================
// GET TRANSFER ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid transfer ID.';
    $redirect = ($role === 'admin' || $role === 'super_admin') 
        ? 'index.php' 
        : 'index_employee.php';
    header('Location: ' . $redirect);
    exit();
}

// ============================================================
// FETCH TRANSFER
// ============================================================
$stmt = $db->prepare("SELECT * FROM transfers WHERE id = ?");
$stmt->execute([$id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    $_SESSION['error_message'] = 'Transfer not found.';
    $redirect = ($role === 'admin' || $role === 'super_admin') 
        ? 'index.php' 
        : 'index_employee.php';
    header('Location: ' . $redirect);
    exit();
}

// ============================================================
// PERMISSION CHECK
// ============================================================
// Admins can delete any transfer
// Employees can only delete their OWN transfers AND only if not yet locked
if ($role !== 'admin' && $role !== 'super_admin') {

    // Employee branch check
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $employee_branch_id = intval($emp['branch_id'] ?? 0);

    // Must be same branch
    if (intval($transfer['branch_id']) !== $employee_branch_id) {
        $_SESSION['error_message'] = 'You do not have permission to delete this transfer.';
        header('Location: index_employee.php');
        exit();
    }

    // Must be their own transfer
    if (intval($transfer['employee_id']) !== $user_id) {
        $_SESSION['error_message'] = 'You can only delete your own transfers.';
        header('Location: index_employee.php');
        exit();
    }
}

// ============================================================
// BLOCK DELETING NON-COMPLETED TRANSFERS
// ============================================================
if (($transfer['status'] ?? 'completed') !== 'completed') {
    $_SESSION['error_message'] = 'Only completed transfers can be deleted.';
    $redirect = ($role === 'admin' || $role === 'super_admin') 
        ? 'index.php?branch_id=' . intval($transfer['branch_id']) 
        : 'index_employee.php';
    header('Location: ' . $redirect);
    exit();
}

// ============================================================
// REVERSE + DELETE (Transaction)
// ============================================================
try {
    $db->beginTransaction();

    $branch_id       = intval($transfer['branch_id']);
    $provider_id     = intval($transfer['provider_id']);
    $amount          = floatval($transfer['amount']);
    $transfer_type   = $transfer['transfer_type']; // cash_to_float | float_to_cash

    // --------------------------------------------------------
    // Find the LATEST daily report for the branch
    // (the transfer may have been created on an older report,
    //  but current state lives on the most recent one)
    // --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT * FROM daily_reports
        WHERE branch_id = ?
        ORDER BY report_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dr) {
        throw new Exception('No daily report found for this branch. Cannot reverse transfer.');
    }

    $dr_id       = intval($dr['id']);
    $dr_cash     = floatval($dr['current_cash'] ?? 0);

    // --------------------------------------------------------
    // Find the provider's current float on this report
    // --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT * FROM daily_report_providers
        WHERE daily_report_id = ? AND provider_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$dr_id, $provider_id]);
    $dr_provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dr_provider) {
        throw new Exception('Provider record not found on daily report. Cannot reverse transfer.');
    }

    $provider_float = floatval($dr_provider['current_float']);

    // --------------------------------------------------------
    // Reverse the financial effect
    // --------------------------------------------------------
    if ($transfer_type === 'cash_to_float') {
        // Was: cash -> float.  Reverse: float -= amount, cash += amount
        $new_float = $provider_float - $amount;
        $new_cash  = $dr_cash + $amount;

        if ($new_float < 0) {
            throw new Exception(
                'Cannot delete — provider float would go negative. ' .
                'Current float: ' . number_format($provider_float) . 
                ', Transfer amount: ' . number_format($amount)
            );
        }
    } else {
        // Was: float -> cash.  Reverse: float += amount, cash -= amount
        $new_float = $provider_float + $amount;
        $new_cash  = $dr_cash - $amount;

        if ($new_cash < 0) {
            throw new Exception(
                'Cannot delete — branch cash would go negative. ' .
                'Current cash: ' . number_format($dr_cash) . 
                ', Transfer amount: ' . number_format($amount)
            );
        }
    }

    // --------------------------------------------------------
    // Update provider float
    // --------------------------------------------------------
    $stmt = $db->prepare("
        UPDATE daily_report_providers
        SET current_float = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_float, $dr_provider['id']]);

    // --------------------------------------------------------
    // Recompute total float across ALL providers on this report
    // (multi-provider safe)
    // --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(current_float), 0)
        FROM daily_report_providers
        WHERE daily_report_id = ?
    ");
    $stmt->execute([$dr_id]);
    $total_float = floatval($stmt->fetchColumn());

    $new_capital = $total_float + $new_cash;

    // --------------------------------------------------------
    // Update daily report totals
    // --------------------------------------------------------
    $stmt = $db->prepare("
        UPDATE daily_reports
        SET current_cash    = ?,
            current_capital = ?,
            updated_at      = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_cash, $new_capital, $dr_id]);

    // --------------------------------------------------------
    // Log activity BEFORE deleting
    // --------------------------------------------------------
    logActivity(
        $user_id,
        'Delete Transfer',
        'Transfers',
        $id,
        $transfer['transfer_number'],
        'Deleted transfer ' . $transfer['transfer_number'] .
        ' (' . ucfirst(str_replace('_', ' ', $transfer_type)) .
        ' of TSh ' . number_format($amount) . 
        ' on ' . ($transfer['provider_name'] ?? 'N/A') . ')'
    );

    // --------------------------------------------------------
    // Delete the transfer row
    // --------------------------------------------------------
    $stmt = $db->prepare("DELETE FROM transfers WHERE id = ?");
    $stmt->execute([$id]);

    $db->commit();

    $_SESSION['success_message'] = 'Transfer ' . $transfer['transfer_number'] . ' deleted and financial impact reversed.';

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error_message'] = 'Delete failed: ' . $e->getMessage();
}

// ============================================================
// REDIRECT BACK
// ============================================================
if ($role === 'admin' || $role === 'super_admin') {
    header('Location: index.php?branch_id=' . intval($transfer['branch_id']));
} else {
    header('Location: index_employee.php');
}
exit();