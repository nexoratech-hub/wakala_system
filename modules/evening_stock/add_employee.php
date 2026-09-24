<?php
// ================================================================
// FILE: modules/evening_stock/add_employee.php
// EVENING STOCK - ADD (EMPLOYEE)
// ✅ Auto-filter kutoka daily_report
// ✅ Employee anaongeza kwa branch yake pekee
// ✅ Rangi ni BLUE (sio purple)
// ✅ Baada ya save → view_employee.php
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
// GET EMPLOYEE BRANCH (employee anaongeza kwa branch yake pekee)
// ============================================================
$stmt = $db->prepare("SELECT branch_id, branch, full_name FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_branch_id = $emp['branch_id'] ?? 0;
$employee_branch    = $emp['branch'] ?? 'Main';

// Employee LAZIMA awe na branch_id
if ($employee_branch_id <= 0) {
    $_SESSION['error_message'] = 'You are not assigned to any branch. Please contact admin.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET BRANCH & DATE FROM URL (AUTO FILTER)
// ============================================================
$selected_branch = $employee_branch_id;   // ⭐ locked kwa branch yake
$selected_date   = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// ============================================================
// GET BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
$stmt->execute([$selected_branch]);
$branch_info = $stmt->fetch(PDO::FETCH_ASSOC);

$selected_branch_name = $branch_info['branch_name'] ?? $employee_branch;
$selected_branch_code = $branch_info['branch_code'] ?? '';

// ============================================================
// CHECK IF EVENING STOCK ALREADY EXISTS FOR THIS DATE+BRANCH
// ============================================================
$existing_stock = null;
$stmt = $db->prepare("
    SELECT id, stock_number 
    FROM evening_stocks 
    WHERE branch_id = ? AND stock_date = ?
    LIMIT 1
");
$stmt->execute([$selected_branch, $selected_date]);
$existing_stock = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// GET DAILY REPORT FOR THIS DATE+BRANCH (AUTO FILTER SOURCE)
// ============================================================
$daily_report = null;
$daily_report_providers = [];

if (!$existing_stock) {
    $stmt = $db->prepare("
        SELECT dr.*
        FROM daily_reports dr
        WHERE dr.branch_id = ? AND dr.report_date = ?
        ORDER BY dr.id DESC
        LIMIT 1
    ");
    $stmt->execute([$selected_branch, $selected_date]);
    $daily_report = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($daily_report) {
        $stmt = $db->prepare("
            SELECT drp.*
            FROM daily_report_providers drp
            WHERE drp.daily_report_id = ?
            ORDER BY drp.id ASC
        ");
        $stmt->execute([$daily_report['id']]);
        $daily_report_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message   = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_stock') {
    try {
        // Employee HAWEZI kubadilisha branch — tunatumia yake
        $branch_id  = $employee_branch_id;
        $stock_date = $_POST['stock_date'] ?? date('Y-m-d');
        $notes      = trim($_POST['notes'] ?? '');

        // Check if stock already exists
        $stmt = $db->prepare("SELECT id FROM evening_stocks WHERE branch_id = ? AND stock_date = ?");
        $stmt->execute([$branch_id, $stock_date]);
        if ($stmt->fetch()) {
            throw new Exception('Evening stock already exists for this branch and date.');
        }

        // Get daily report
        $stmt = $db->prepare("
            SELECT * FROM daily_reports 
            WHERE branch_id = ? AND report_date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$branch_id, $stock_date]);
        $dr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dr) {
            throw new Exception('No daily report found for this branch and date. Please create a daily report first.');
        }

        // Get providers from daily report
        $stmt = $db->prepare("
            SELECT * FROM daily_report_providers 
            WHERE daily_report_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$dr['id']]);
        $dr_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dr_providers)) {
            throw new Exception('No providers found in daily report for this date.');
        }

        // ====================================================
        // START TRANSACTION
        // ====================================================
        $db->beginTransaction();

        // Generate stock number
        $stock_number = 'ES-' . date('Ymd', strtotime($stock_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Calculate totals
        $total_float = 0;
        $total_cash  = 0;
        $provider_data_array = [];

        foreach ($dr_providers as $drp) {
            $pid = $drp['provider_id'];
            $closing_float = floatval($_POST['closing_float'][$pid] ?? $drp['current_float']);
            $closing_cash  = floatval($_POST['closing_cash'][$pid]  ?? $drp['current_cash']);

            $total_float += $closing_float;
            $total_cash  += $closing_cash;

            $provider_data_array[$pid] = [
                'provider_name'     => $drp['provider_name'],
                'provider_code'     => $drp['provider_code'],
                'opening_float'     => floatval($drp['morning_float']),
                'opening_cash'      => floatval($drp['morning_cash']),
                'closing_float'     => $closing_float,
                'closing_cash'      => $closing_cash,
                'total_deposits'    => floatval($drp['total_deposits']),
                'total_withdrawals' => floatval($drp['total_withdrawals'])
            ];
        }

        // Get branch name
        $branch_name = $selected_branch_name;

        // Insert evening stock
        $stmt = $db->prepare("
            INSERT INTO evening_stocks 
            (stock_number, employee_id, branch, branch_id, daily_report_id, stock_date,
             provider_data, cash_balance, opening_float, opening_cash,
             cumm_total, status, submitted_at, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', NOW(), ?, NOW())
        ");
        $stmt->execute([
            $stock_number,
            $user_id,
            $branch_name,
            $branch_id,
            $dr['id'],
            $stock_date,
            json_encode($provider_data_array),
            $total_cash,
            floatval($dr['current_float'] ?? 0),
            floatval($dr['current_cash'] ?? 0),
            $total_float,
            $notes
        ]);

        $stock_id = $db->lastInsertId();

        // Insert evening_stock_providers
        foreach ($dr_providers as $drp) {
            $pid = $drp['provider_id'];
            $closing_float = floatval($_POST['closing_float'][$pid] ?? $drp['current_float']);
            $closing_cash  = floatval($_POST['closing_cash'][$pid]  ?? $drp['current_cash']);

            $stmt = $db->prepare("
                INSERT INTO evening_stock_providers 
                (evening_stock_id, provider_id, provider_code, provider_name,
                 opening_float, opening_cash, closing_float, closing_cash,
                 total_deposits, total_withdrawals, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $stock_id,
                $pid,
                $drp['provider_code'],
                $drp['provider_name'],
                floatval($drp['morning_float']),
                floatval($drp['morning_cash']),
                $closing_float,
                $closing_cash,
                floatval($drp['total_deposits']),
                floatval($drp['total_withdrawals'])
            ]);
        }

        // Log activity
        logActivity(
            $user_id,
            'Add Evening Stock',
            'Evening Stock',
            $stock_id,
            '',
            'New evening stock added for branch: ' . $branch_name . ' on ' . $stock_date
        );

        $db->commit();

        $_SESSION['success_message'] = 'Evening stock ' . $stock_number . ' created successfully!';
        // ⭐ Employee anarudishwa kwenye view_employee.php
        header('Location: view_employee.php?id=' . $stock_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Success/error messages
$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- ============================================================
        BRANCH CARD — BLUE
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Evening Stock For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($selected_branch_name); ?></span>
                <?php if ($selected_branch_code): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($selected_branch_code); ?></span>
                <?php endif; ?>
                <span class="branch-status-date">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($selected_date)); ?>
                </span>
                <span class="branch-status-viewonly">
                    <i class="fas fa-eye"></i> View Only Branch
                </span>
            </div>
            <a href="index_employee.php" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#2563EB;"></i> Add Evening Stock</h2>
                <p class="text-muted">Auto-filtered from Daily Report</p>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success_message_session; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        IF EVENING STOCK ALREADY EXISTS
        ============================================================ -->
        <?php if ($existing_stock): ?>
            <div class="existing-stock-warning">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Evening Stock Already Exists!</strong>
                    <p>Evening stock <strong><?php echo htmlspecialchars($existing_stock['stock_number']); ?></strong> already exists for this branch on <?php echo date('d M Y', strtotime($selected_date)); ?>.</p>
                    <div class="warning-actions">
                        <a href="view_employee.php?id=<?php echo $existing_stock['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View Existing Stock
                        </a>
                        <a href="index_employee.php" class="btn btn-cancel">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>
            </div>

        <!-- ============================================================
        IF NO DAILY REPORT
        ============================================================ -->
        <?php elseif (!$daily_report): ?>
            <div class="no-daily-report-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>No Daily Report Found</strong>
                    <p>Hakuna Daily Report ya tarehe <strong><?php echo date('d M Y', strtotime($selected_date)); ?></strong> kwa branch <strong><?php echo htmlspecialchars($selected_branch_name); ?></strong>.</p>
                    <p>Evening stock inahitaji Daily Report kwanza. Tafadhali wasiliana na admin kuunda Daily Report kwa tarehe hii.</p>
                    <div class="warning-actions">
                        <a href="index_employee.php" class="btn btn-cancel">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>
            </div>

        <!-- ============================================================
        MAIN FORM - AUTO FILTERED FROM DAILY REPORT
        ============================================================ -->
        <?php else: ?>

            <!-- Daily Report Info -->
            <div class="daily-report-info-card">
                <div class="dric-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="dric-content">
                    <span class="dric-label">Auto-Filtered From Daily Report</span>
                    <span class="dric-value">
                        <strong><?php echo htmlspecialchars($daily_report['report_number']); ?></strong>
                        • <?php echo date('d M Y', strtotime($daily_report['report_date'])); ?>
                        • <?php echo count($daily_report_providers); ?> providers
                    </span>
                </div>
                <div class="dric-totals">
                    <div class="dric-total-item">
                        <span class="dric-total-label">Float</span>
                        <span class="dric-total-value"><?php echo formatCurrency($daily_report['current_float'] ?? 0); ?></span>
                    </div>
                    <div class="dric-total-item">
                        <span class="dric-total-label">Cash</span>
                        <span class="dric-total-value"><?php echo formatCurrency($daily_report['current_cash'] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <div class="form-container">
                <form method="POST" action="" class="main-form" id="stockForm" onsubmit="return validateForm()">
                    <input type="hidden" name="action" value="add_stock">
                    <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                    <input type="hidden" name="stock_date" value="<?php echo $selected_date; ?>">

                    <!-- ===== PROVIDERS FROM DAILY REPORT ===== -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-university"></i> Provider Closing Balances</h3>
                            <span class="section-badge"><?php echo count($daily_report_providers); ?> Providers</span>
                        </div>

                        <p class="section-hint">
                            <i class="fas fa-info-circle"></i>
                            Balances zimechukuliwa kutoka Daily Report. Unaweza kuedit kabla ya kusave.
                        </p>

                        <div class="providers-table-wrapper">
                            <table class="providers-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">#</th>
                                        <th>Provider</th>
                                        <th class="text-right">Opening Float</th>
                                        <th class="text-right">Opening Cash</th>
                                        <th class="text-right">Deposits</th>
                                        <th class="text-right">Withdrawals</th>
                                        <th class="text-right">Closing Float</th>
                                        <th class="text-right">Closing Cash</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; foreach ($daily_report_providers as $drp): ?>
                                        <tr>
                                            <td><?php echo $i++; ?></td>
                                            <td>
                                                <div class="provider-cell">
                                                    <span class="provider-name"><?php echo htmlspecialchars($drp['provider_name']); ?></span>
                                                    <span class="provider-code"><?php echo htmlspecialchars($drp['provider_code']); ?></span>
                                                </div>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-readonly"><?php echo formatCurrency($drp['morning_float']); ?></span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-readonly"><?php echo formatCurrency($drp['morning_cash']); ?></span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-readonly text-success">+<?php echo formatCurrency($drp['total_deposits']); ?></span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-readonly text-danger">-<?php echo formatCurrency($drp['total_withdrawals']); ?></span>
                                            </td>
                                            <td class="text-right">
                                                <input type="text"
                                                       name="closing_float[<?php echo $drp['provider_id']; ?>]"
                                                       class="money-input-table"
                                                       value="<?php echo number_format($drp['current_float'], 0, '.', ''); ?>"
                                                       data-provider-id="<?php echo $drp['provider_id']; ?>"
                                                       oninput="formatMoneyInput(this); updateTotals();">
                                            </td>
                                            <td class="text-right">
                                                <input type="text"
                                                       name="closing_cash[<?php echo $drp['provider_id']; ?>]"
                                                       class="money-input-table"
                                                       value="<?php echo number_format($drp['current_cash'], 0, '.', ''); ?>"
                                                       data-provider-id="<?php echo $drp['provider_id']; ?>"
                                                       oninput="formatMoneyInput(this); updateTotals();">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="totals-row">
                                        <td colspan="6" class="text-right">
                                            <strong>TOTAL</strong>
                                        </td>
                                        <td class="text-right">
                                            <span class="total-float" id="totalFloat">TSh 0</span>
                                        </td>
                                        <td class="text-right">
                                            <span class="total-cash" id="totalCash">TSh 0</span>
                                        </td>
                                    </tr>
                                    <tr class="grand-total-row">
                                        <td colspan="6" class="text-right">
                                            <strong>GRAND TOTAL</strong>
                                        </td>
                                        <td colspan="2" class="text-right">
                                            <span class="grand-total" id="grandTotal">TSh 0</span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- ===== NOTES ===== -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                        </div>

                        <div class="form-group full-width">
                            <textarea name="notes"
                                      class="form-control textarea-control"
                                      rows="3"
                                      placeholder="Additional notes for this evening stock..."></textarea>
                        </div>
                    </div>

                    <!-- ===== ACTIONS ===== -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit" id="submitBtn">
                            <i class="fas fa-save"></i> Save Evening Stock
                        </button>
                        <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <a href="index_employee.php" class="btn btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>

                </form>
            </div>

        <?php endif; ?>

    </div><!-- /.main-content -->

    <?php include_once '../../includes/employee_footer.php'; ?>

</div><!-- /.main-wrapper -->

<style>
/* ============================================================
   CSS VARIABLES — BLUE THEME
   ============================================================ */
:root {
    --ea-bg: #F3F4F6;
    --ea-text: #1F2937;
    --ea-text-secondary: #6B7280;
    --ea-text-light: #9CA3AF;
    --ea-border: #E5E7EB;
    --ea-card-bg: #FFFFFF;
    --ea-input-bg: #F9FAFB;
    --ea-hover: #F3F4F6;
    --ea-shadow: rgba(0,0,0,0.06);

    --ea-blue-900: #1E3A8A;
    --ea-blue-700: #1D4ED8;
    --ea-blue-600: #2563EB;
    --ea-blue-500: #3B82F6;

    --sidebar-width: 220px;
    --topbar-height: 70px;
}

html.dark-mode {
    --ea-bg: #0F172A;
    --ea-text: #F9FAFB;
    --ea-text-secondary: #9CA3AF;
    --ea-text-light: #6B7280;
    --ea-border: #334155;
    --ea-card-bg: #1E293B;
    --ea-input-bg: #334155;
    --ea-hover: #334155;
    --ea-shadow: rgba(0,0,0,0.3);
}

*, *::before, *::after { box-sizing: border-box; }

html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}

body {
    background: var(--ea-bg) !important;
    color: var(--ea-text);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    padding-top: 0 !important;
    margin: 0 !important;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ============================================================
   MAIN WRAPPER
   ============================================================ */
.main-wrapper {
    background: var(--ea-bg) !important;
    margin-left: var(--sidebar-width) !important;
    padding-top: var(--topbar-height);
    width: calc(100% - var(--sidebar-width)) !important;
    max-width: calc(100% - var(--sidebar-width)) !important;
    min-height: 100vh;
    overflow-x: hidden !important;
    display: flex;
    flex-direction: column;
}

.main-content {
    background: var(--ea-bg) !important;
    padding: 16px 20px !important;
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    overflow-x: hidden !important;
    flex: 1;
}

/* ============================================================
   FOOTER
   ============================================================ */
.employee-footer {
    margin-left: 0 !important;
    margin-top: auto !important;
    margin-bottom: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    background: #ffffff !important;
    border-top: 1px solid var(--ea-border) !important;
    padding: 10px 20px !important;
}
html.dark-mode .employee-footer {
    background: #1e293b !important;
    border-color: #334155 !important;
}
.employee-footer .footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #6b7280;
    flex-wrap: wrap;
    gap: 8px;
}
html.dark-mode .employee-footer .footer-content { color: #94a3b8; }
.employee-footer .footer-version { font-weight: 600; color: #bb0404; }

/* ============================================================
   BRANCH STATUS CARD — BLUE
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(37, 99, 235, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
}
.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}
.branch-status-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name {
    font-size: 18px;
    font-weight: 800;
    color: #FFFFFF;
}
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    font-family: 'Courier New', monospace;
}
.branch-status-date {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    font-weight: 600;
}
.branch-status-viewonly {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 700;
    color: #FFFFFF;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    text-transform: uppercase;
    letter-spacing: 0.6px;
}
.btn-back-card {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}
.btn-back-card:hover {
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
    transform: translateX(-3px);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 800;
    color: var(--ea-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--ea-text-secondary);
    margin: 4px 0 0 0;
}

/* ============================================================
   ALERTS & WARNINGS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
}

.existing-stock-warning,
.no-daily-report-warning {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 20px 24px;
    border-radius: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.existing-stock-warning {
    background: #DBEAFE;
    border: 2px solid #93C5FD;
    color: #1E40AF;
}
.existing-stock-warning > i {
    font-size: 28px;
    color: #2563EB;
    flex-shrink: 0;
    margin-top: 2px;
}
.existing-stock-warning strong { font-weight: 800; font-size: 15px; display: block; margin-bottom: 6px; }
.existing-stock-warning p { font-size: 13px; margin: 0 0 12px 0; line-height: 1.5; }
.warning-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
}

.no-daily-report-warning {
    background: #FEE2E2;
    border: 2px solid #FCA5A5;
    color: #991B1B;
}
.no-daily-report-warning > i {
    font-size: 28px;
    color: #DC2626;
    flex-shrink: 0;
    margin-top: 2px;
}
.no-daily-report-warning strong { font-weight: 800; font-size: 15px; display: block; margin-bottom: 6px; }
.no-daily-report-warning p { font-size: 13px; margin: 0 0 6px 0; line-height: 1.5; }

/* ============================================================
   DAILY REPORT INFO CARD
   ============================================================ */
.daily-report-info-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
html.dark-mode .daily-report-info-card {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
.dric-icon {
    width: 52px;
    height: 52px;
    background: #2563EB;
    color: #FFFFFF;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}
.dric-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex: 1;
    min-width: 0;
}
.dric-label {
    font-size: 10px;
    font-weight: 800;
    color: #1D4ED8;
    text-transform: uppercase;
    letter-spacing: 1px;
}
html.dark-mode .dric-label { color: #60A5FA; }
.dric-value {
    font-size: 14px;
    font-weight: 600;
    color: #1E40AF;
}
html.dark-mode .dric-value { color: #DBEAFE; }
.dric-value strong {
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: #2563EB;
}
.dric-totals {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.dric-total-item {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 10px;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
    min-width: 110px;
}
html.dark-mode .dric-total-item { background: rgba(15, 23, 42, 0.5); }
.dric-total-label {
    font-size: 9px;
    font-weight: 800;
    color: #1D4ED8;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.dric-total-value {
    font-size: 14px;
    font-weight: 900;
    color: #1E40AF;
    font-family: 'Inter', 'Courier New', monospace;
}
html.dark-mode .dric-total-value { color: #DBEAFE; }

/* ============================================================
   FORM CONTAINER
   ============================================================ */
.form-container {
    background: var(--ea-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--ea-border);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--ea-shadow);
}
.form-section {
    padding: 22px 26px;
    border-bottom: 1px solid var(--ea-border);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 8px;
}
.section-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--ea-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-header h3 i { color: #2563EB; font-size: 15px; }
.section-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--ea-text-secondary);
    background: var(--ea-hover);
    padding: 3px 12px;
    border-radius: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.section-hint {
    font-size: 12px;
    color: var(--ea-text-secondary);
    background: var(--ea-hover);
    padding: 10px 14px;
    border-radius: 8px;
    margin: 0 0 16px 0;
    border-left: 3px solid #2563EB;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-hint i { color: #2563EB; }

/* ============================================================
   PROVIDERS TABLE — BLUE HEADER
   ============================================================ */
.providers-table-wrapper {
    overflow-x: auto;
    border-radius: 10px;
    border: 1.5px solid var(--ea-border);
    background: var(--ea-card-bg);
}
.providers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}
.providers-table thead {
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
}
.providers-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.providers-table thead th.text-right { text-align: right; }
.providers-table tbody tr {
    border-bottom: 1px solid var(--ea-border);
    transition: background 0.2s ease;
}
.providers-table tbody tr:hover { background: var(--ea-hover); }
.providers-table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--ea-text);
    vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }

.provider-cell {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.provider-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--ea-text);
}
.provider-code {
    font-size: 10px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .provider-code { background: #1E3A5F; color: #60A5FA; }

.amount-readonly {
    font-size: 13px;
    font-weight: 700;
    font-family: 'Inter', 'Courier New', monospace;
    color: var(--ea-text-secondary);
}
.amount-readonly.text-success { color: #10B981; }
.amount-readonly.text-danger { color: #DC2626; }

.money-input-table {
    width: 130px;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--ea-border);
    font-size: 13px;
    font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    text-align: right;
    color: #2563EB;
    background: var(--ea-input-bg);
    outline: none;
    transition: all 0.3s ease;
}
.money-input-table:focus {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    background: var(--ea-card-bg);
}
html.dark-mode .money-input-table { color: #60A5FA; }

.providers-table tfoot {
    background: var(--ea-hover);
}
.providers-table tfoot td {
    padding: 14px;
    font-size: 13px;
    color: var(--ea-text);
    border-top: 2px solid var(--ea-border);
}
.totals-row td {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
}
html.dark-mode .totals-row td {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
}
.total-float, .total-cash {
    font-size: 15px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #1D4ED8;
}
html.dark-mode .total-float,
html.dark-mode .total-cash { color: #60A5FA; }

.grand-total-row td {
    background: linear-gradient(135deg, #2563EB, #1D4ED8);
    color: #FFFFFF;
    border-top: 2px solid #1E40AF;
}
.grand-total-row strong {
    color: #FFFFFF;
    font-size: 13px;
    letter-spacing: 1px;
}
.grand-total {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #FCD34D;
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

/* ============================================================
   FORM CONTROLS
   ============================================================ */
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full-width { width: 100%; }
.form-control {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--ea-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--ea-input-bg);
    color: var(--ea-text);
    font-weight: 500;
}
.form-control::placeholder { color: var(--ea-text-light); }
.form-control:focus {
    border-color: #2563EB;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    background: var(--ea-card-bg);
}
.form-control.textarea-control {
    min-height: 80px;
    resize: vertical;
    line-height: 1.6;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 26px;
    border-top: 1px solid var(--ea-border);
    background: var(--ea-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-submit {
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
    color: white;
}
.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
.btn-reset, .btn-cancel {
    background: var(--ea-card-bg);
    color: var(--ea-text-secondary);
    border: 1.5px solid var(--ea-border);
}
.btn-reset:hover { background: var(--ea-border); color: var(--ea-text); }
.btn-cancel:hover {
    background: #FEE2E2;
    color: #991B1B;
    border-color: #FECACA;
}
.btn-primary {
    background: #2563EB;
    color: white;
}
.btn-primary:hover {
    background: #1D4ED8;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .main-wrapper {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding-top: 62px;
        min-height: 100vh;
    }
    .main-content { padding: 12px !important; }

    .employee-footer {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 10px 14px !important;
    }
    .employee-footer .footer-content {
        font-size: 11px;
        flex-direction: column;
        text-align: center;
        gap: 4px;
    }

    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 18px;
    }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }

    .page-header { flex-direction: column; align-items: flex-start; }

    .daily-report-info-card {
        flex-direction: column;
        align-items: flex-start;
    }
    .dric-totals { width: 100%; }
    .dric-total-item { flex: 1; }

    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }

    .form-section { padding: 18px 20px; }

    .money-input-table { width: 110px; font-size: 12px; padding: 6px 10px; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 58px; }
    .main-content { padding: 10px !important; }
    .employee-footer { padding: 8px 12px !important; }
    .employee-footer .footer-content { font-size: 10px; }

    .branch-status-name { font-size: 15px; }
    .branch-status-icon { width: 44px; height: 44px; font-size: 18px; }
    .header-left h2 { font-size: 18px; }
    .section-header h3 { font-size: 13px; }
    .form-section { padding: 16px 14px; }
    .money-input-table { width: 90px; font-size: 11px; }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    var value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);

    var formatted = '';
    var count = 0;
    for (var i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    updateTotals();
}

// ============================================================
// UPDATE TOTALS
// ============================================================
function updateTotals() {
    var totalFloat = 0;
    var totalCash = 0;

    document.querySelectorAll('input[name^="closing_float"]').forEach(function(input) {
        totalFloat += parseFloat(input.value.replace(/,/g, '')) || 0;
    });

    document.querySelectorAll('input[name^="closing_cash"]').forEach(function(input) {
        totalCash += parseFloat(input.value.replace(/,/g, '')) || 0;
    });

    var grandTotal = totalFloat + totalCash;

    var tf = document.getElementById('totalFloat');
    var tc = document.getElementById('totalCash');
    var gt = document.getElementById('grandTotal');
    if (tf) tf.textContent = 'TSh ' + totalFloat.toLocaleString('en-US');
    if (tc) tc.textContent = 'TSh ' + totalCash.toLocaleString('en-US');
    if (gt) gt.textContent = 'TSh ' + grandTotal.toLocaleString('en-US');
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAny unsaved changes will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updateTotals();

    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });

    // Auto-hide alerts
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 5000);
    }
});
</script>

</body>
</html>