<?php
// ================================================================
// FILE: modules/evening_stock/add.php
// WAKALA FINANCIAL SYSTEM - ADD EVENING STOCK (ADMIN) - FINAL FIXED
// 
// ✅ FIX: Check duplicate KABLA ya form (inaonyesha nani aliyeunda)
// ✅ FIX: Check duplicate kwenye POST (better error message)
// ✅ FIX: Catch SQL error 1062 → friendly message
// ✅ GREEN THEME
// ✅ AUTO-FILL from daily_reports (SAME DATE as stock_date)
// ✅ Cash taken from daily_reports.current_cash (BRANCH CASH)
// ✅ Float taken from daily_report_providers.current_float
// ✅ cumm_total = FLOAT ONLY
// ✅ Saves daily_report_id, opening_float, opening_cash
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
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET BRANCH & DATE
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

// ============================================================
// GET BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_branch_name = 'All Branches';
$selected_branch_code = '';
if ($selected_branch > 0) {
    foreach ($all_branches as $b) {
        if ($b['id'] == $selected_branch) {
            $selected_branch_name = $b['branch_name'];
            $selected_branch_code = $b['branch_code'] ?? '';
            break;
        }
    }
}

// ============================================================
// ✅ CHECK DUPLICATE #1: EVENING STOCK (KABLA YA FORM)
// Inaonyesha jina la aliyeunda + muda
// ============================================================
$existing_stock = null;
if ($selected_branch > 0) {
    $stmt = $db->prepare("
        SELECT 
            es.id, 
            es.stock_number,
            es.stock_date,
            es.submitted_at,
            es.employee_id,
            e.full_name AS created_by_name,
            e.employee_id AS created_by_code
        FROM evening_stocks es
        LEFT JOIN employees e ON es.employee_id = e.id
        WHERE es.branch_id = ? AND es.stock_date = ?
        LIMIT 1
    ");
    $stmt->execute([$selected_branch, $selected_date]);
    $existing_stock = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================================
// GET DAILY REPORT & PROVIDERS (AUTO-FILL SOURCE)
// ============================================================
$daily_report = null;
$daily_report_providers = [];

if ($selected_branch > 0 && !$existing_stock) {
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
            SELECT drp.*, p.icon_class, p.color_code, p.display_order
            FROM daily_report_providers drp
            LEFT JOIN providers p ON drp.provider_id = p.id
            WHERE drp.daily_report_id = ?
            ORDER BY p.display_order ASC, drp.id ASC
        ");
        $stmt->execute([$daily_report['id']]);
        $daily_report_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_float = 0;
foreach ($daily_report_providers as $drp) {
    $total_float += floatval(str_replace(',', '', $drp['current_float'] ?? 0));
}

$total_cash = $daily_report ? floatval(str_replace(',', '', $daily_report['current_cash'] ?? 0)) : 0;
$grand_total = $total_float + $total_cash;

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_stock') {
    try {
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $stock_date = $_POST['stock_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }

        // ------------------------------------------------------------
        // ✅ CHECK #1: Evening Stock ipo tayari?
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            SELECT 
                es.id, 
                es.stock_number, 
                e.full_name AS created_by,
                e.employee_id AS created_by_code
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            WHERE es.branch_id = ? AND es.stock_date = ?
            LIMIT 1
        ");
        $stmt->execute([$branch_id, $stock_date]);
        $existing_es = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_es) {
            $created_by = $existing_es['created_by'] ?? 'another user';
            $created_by_code = $existing_es['created_by_code'] ?? '';
            
            throw new Exception(
                '❌ Evening Stock for ' . date('d M Y', strtotime($stock_date)) . 
                ' already exists (' . $existing_es['stock_number'] . ') ' .
                'added by ' . $created_by . 
                ($created_by_code ? ' (' . $created_by_code . ')' : '') . '. ' .
                'Each branch can only have ONE evening stock per day.'
            );
        }

        // ------------------------------------------------------------
        // Get Daily Report for the SAME DATE
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            SELECT * FROM daily_reports 
            WHERE branch_id = ? AND report_date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$branch_id, $stock_date]);
        $dr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$dr) {
            throw new Exception('No daily report found for this branch and date. Please create a Daily Report for ' . date('d M Y', strtotime($stock_date)) . ' first.');
        }

        // Get providers
        $stmt = $db->prepare("
            SELECT * FROM daily_report_providers 
            WHERE daily_report_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$dr['id']]);
        $dr_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dr_providers)) {
            throw new Exception('No providers found in daily report.');
        }

        // ------------------------------------------------------------
        // ✅ BEGIN TRANSACTION
        // ------------------------------------------------------------
        $db->beginTransaction();

        $stock_number = 'ES-' . date('Ymd', strtotime($stock_date)) . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Calculate totals
        $total_float_new = 0;
        $provider_data_array = [];

        foreach ($dr_providers as $drp) {
            $pid = $drp['provider_id'];
            $closing_float = floatval(str_replace(',', '', $drp['current_float'] ?? 0));
            $closing_cash = floatval(str_replace(',', '', $drp['current_cash'] ?? 0));

            $total_float_new += $closing_float;

            $provider_data_array[$pid] = [
                'provider_name' => $drp['provider_name'],
                'provider_code' => $drp['provider_code'],
                'opening_float' => floatval(str_replace(',', '', $drp['morning_float'] ?? 0)),
                'opening_cash' => floatval(str_replace(',', '', $drp['morning_cash'] ?? 0)),
                'closing_float' => $closing_float,
                'closing_cash' => $closing_cash,
                'total_deposits' => floatval(str_replace(',', '', $drp['total_deposits'] ?? 0)),
                'total_withdrawals' => floatval(str_replace(',', '', $drp['total_withdrawals'] ?? 0))
            ];
        }

        $total_cash_new = floatval(str_replace(',', '', $dr['current_cash'] ?? 0));

        $branch_name = '';
        foreach ($all_branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }

        // ------------------------------------------------------------
        // ✅ INSERT EVENING STOCK
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            INSERT INTO evening_stocks 
            (stock_number, employee_id, branch, branch_id, daily_report_id, stock_date,
             provider_data, cash_balance, opening_float, opening_cash,
             cumm_total, status, submitted_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', NOW(), ?)
        ");
        $stmt->execute([
            $stock_number,
            $user_id,
            $branch_name,
            $branch_id,
            $dr['id'],
            $stock_date,
            json_encode($provider_data_array),
            $total_cash_new,
            floatval(str_replace(',', '', $dr['current_float'] ?? 0)),
            floatval(str_replace(',', '', $dr['current_cash'] ?? 0)),
            $total_float_new,
            $notes
        ]);

        $stock_id = $db->lastInsertId();

        // ------------------------------------------------------------
        // ✅ INSERT PROVIDERS
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            INSERT INTO evening_stock_providers 
            (evening_stock_id, provider_id, provider_code, provider_name,
             opening_float, opening_cash, closing_float, closing_cash,
             total_deposits, total_withdrawals, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($dr_providers as $drp) {
            $closing_float = floatval(str_replace(',', '', $drp['current_float'] ?? 0));
            $closing_cash = floatval(str_replace(',', '', $drp['current_cash'] ?? 0));

            $stmt->execute([
                $stock_id,
                $drp['provider_id'],
                $drp['provider_code'],
                $drp['provider_name'],
                floatval(str_replace(',', '', $drp['morning_float'] ?? 0)),
                floatval(str_replace(',', '', $drp['morning_cash'] ?? 0)),
                $closing_float,
                $closing_cash,
                floatval(str_replace(',', '', $drp['total_deposits'] ?? 0)),
                floatval(str_replace(',', '', $drp['total_withdrawals'] ?? 0))
            ]);
        }

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
        header('Location: view.php?id=' . $stock_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        
        $error_msg = $e->getMessage();
        
        // ============================================================
        // ✅ CATCH SQL ERROR 1062 - Duplicate entry
        // ============================================================
        if (strpos($error_msg, '1062') !== false || 
            strpos($error_msg, 'Duplicate entry') !== false ||
            strpos($error_msg, 'Integrity constraint') !== false) {
            
            $stock_date_safe = $_POST['stock_date'] ?? date('Y-m-d');
            $branch_id_safe = intval($_POST['branch_id'] ?? 0);
            
            $stmt = $db->prepare("
                SELECT 
                    es.stock_number,
                    e.full_name AS created_by,
                    e.employee_id AS created_by_code
                FROM evening_stocks es
                LEFT JOIN employees e ON es.employee_id = e.id
                WHERE es.branch_id = ? AND es.stock_date = ?
                LIMIT 1
            ");
            $stmt->execute([$branch_id_safe, $stock_date_safe]);
            $dup = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dup) {
                $error_msg = 
                    '❌ Evening Stock for ' . date('d M Y', strtotime($stock_date_safe)) . 
                    ' already exists (' . $dup['stock_number'] . ') ' .
                    'added by ' . ($dup['created_by'] ?? 'another user') . 
                    (!empty($dup['created_by_code']) ? ' (' . $dup['created_by_code'] . ')' : '') . '. ' .
                    'Each branch can only have ONE evening stock per day.';
            } else {
                $error_msg = 
                    '❌ An Evening Stock for ' . date('d M Y', strtotime($stock_date_safe)) . 
                    ' already exists. Each branch can only have ONE evening stock per day.';
            }
        }
        
        $error_message = $error_msg;
    }
}

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-moon"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Evening Stock For</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($selected_branch_name); ?></span>
                    <?php if ($selected_branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($selected_branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <div class="branch-location">
                    <i class="fas fa-calendar"></i>
                    <span><?php echo date('d M Y', strtotime($selected_date)); ?></span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <a href="index.php<?php echo $selected_branch > 0 ? '?branch=' . $selected_branch : ''; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#059669;"></i> New Evening Stock</h2>
            </div>
        </div>

        <!-- MESSAGES -->
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

        <?php if ($selected_branch == 0): ?>
            <!-- SELECT BRANCH -->
            <div class="waiting-card">
                <div class="waiting-icon">
                    <i class="fas fa-store-alt"></i>
                </div>
                <h3 class="waiting-title">Please Select a Branch</h3>
                <p class="waiting-text">
                    Select a branch to add an evening stock entry.
                </p>
                <div class="branch-picker-grid">
                    <?php foreach ($all_branches as $b): ?>
                        <a href="add.php?branch=<?php echo $b['id']; ?>&date=<?php echo $selected_date; ?>" 
                           class="branch-picker-card">
                            <div class="bpc-icon">
                                <i class="fas fa-store-alt"></i>
                            </div>
                            <div class="bpc-info">
                                <span class="bpc-name"><?php echo htmlspecialchars($b['branch_name']); ?></span>
                                <?php if (!empty($b['branch_code'])): ?>
                                    <span class="bpc-code"><?php echo htmlspecialchars($b['branch_code']); ?></span>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-arrow-right bpc-arrow"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($existing_stock): ?>
            <!-- ✅ EXISTING STOCK - With creator info -->
            <div class="waiting-card">
                <div class="waiting-icon waiting-warning">
                    <i class="fas fa-info-circle"></i>
                </div>
                <h3 class="waiting-title">Evening Stock Already Exists</h3>
                <p class="waiting-text">
                    Evening stock <strong><?php echo htmlspecialchars($existing_stock['stock_number']); ?></strong> 
                    already exists for this branch on <?php echo date('d M Y', strtotime($selected_date)); ?>.
                    <?php if (!empty($existing_stock['created_by_name'])): ?>
                        <br><br>
                        <span class="creator-info">
                            <i class="fas fa-user-circle"></i>
                            Added by <strong><?php echo htmlspecialchars($existing_stock['created_by_name']); ?></strong>
                            <?php if (!empty($existing_stock['created_by_code'])): ?>
                                (<?php echo htmlspecialchars($existing_stock['created_by_code']); ?>)
                            <?php endif; ?>
                            <?php if (!empty($existing_stock['submitted_at'])): ?>
                                · <i class="fas fa-clock"></i>
                                <?php echo date('d M Y H:i', strtotime($existing_stock['submitted_at'])); ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <br><br>
                    Each branch can only have <strong>ONE</strong> evening stock per day.
                </p>
                <div class="waiting-actions">
                    <a href="view.php?id=<?php echo $existing_stock['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View Existing
                    </a>
                    <a href="index.php?branch=<?php echo $selected_branch; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

        <?php elseif (!$daily_report): ?>
            <!-- NO DAILY REPORT -->
            <div class="waiting-card">
                <div class="waiting-icon waiting-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="waiting-title">Waiting for Daily Report</h3>
                <p class="waiting-text">
                    No Daily Report found for <strong><?php echo date('d M Y', strtotime($selected_date)); ?></strong> 
                    at <strong><?php echo htmlspecialchars($selected_branch_name); ?></strong>.
                    Please create a Daily Report for this date first.
                </p>
                <div class="waiting-actions">
                    <a href="../daily_report/add.php?branch=<?php echo $selected_branch; ?>&date=<?php echo $selected_date; ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Daily Report
                    </a>
                    <a href="index.php?branch=<?php echo $selected_branch; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

        <?php else: ?>

            <!-- SOURCE INFO -->
            <div class="source-info-card source-daily">
                <div class="source-info-header">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Source: Daily Report</span>
                </div>
                <div class="source-info-body">
                    <div class="source-detail">
                        <span class="source-detail-label">Reference</span>
                        <span class="source-detail-value"><?php echo htmlspecialchars($daily_report['report_number']); ?></span>
                    </div>
                    <div class="source-detail">
                        <span class="source-detail-label">Date</span>
                        <span class="source-detail-value"><?php echo date('d M Y', strtotime($daily_report['report_date'])); ?></span>
                    </div>
                    <div class="source-detail">
                        <span class="source-detail-label">Providers</span>
                        <span class="source-detail-value"><?php echo count($daily_report_providers); ?></span>
                    </div>
                </div>
            </div>

            <!-- FORM -->
            <form method="POST" action="" class="add-form" id="stockForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                <input type="hidden" name="stock_date" value="<?php echo $selected_date; ?>">

                <!-- CASH SECTION -->
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>Branch Cash Balance</h3>
                    </div>
                    <div class="form-card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Stock Date</label>
                                <input type="date" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($selected_date); ?>" 
                                       readonly tabindex="-1">
                            </div>
                            <div class="form-group">
                                <label>Total Cash Balance (TSh)</label>
                                <input type="text" 
                                       class="form-control money-input readonly-input" 
                                       value="<?php echo number_format($total_cash); ?>" 
                                       readonly tabindex="-1">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PROVIDERS SECTION - 3 PER ROW -->
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-university"></i>
                        <h3>Provider Closing Balances</h3>
                        <span class="providers-count"><?php echo count($daily_report_providers); ?> providers</span>
                    </div>
                    <div class="form-card-body">
                        <?php if (count($daily_report_providers) > 0): ?>
                            <div class="providers-grid-3">
                                <?php foreach ($daily_report_providers as $drp):
                                    $color = $drp['color_code'] ?? '#059669';
                                    $icon = $drp['icon_class'] ?? 'fas fa-university';
                                    $closing_float = floatval(str_replace(',', '', $drp['current_float'] ?? 0));
                                ?>
                                    <div class="provider-input-card provider-input-green">
                                        <div class="provider-input-header">
                                            <div class="provider-input-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                            </div>
                                            <div class="provider-input-info">
                                                <span class="provider-input-name"><?php echo htmlspecialchars($drp['provider_name']); ?></span>
                                                <span class="provider-input-code"><?php echo htmlspecialchars($drp['provider_code']); ?></span>
                                            </div>
                                        </div>
                                        <div class="provider-input-body">
                                            <label>Closing Float (TSh)</label>
                                            <input type="text" 
                                                   class="form-control money-input provider-float-input readonly-input" 
                                                   value="<?php echo number_format($closing_float); ?>"
                                                   readonly tabindex="-1">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-providers">
                                <i class="fas fa-info-circle"></i>
                                <p>No providers found.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- NOTES -->
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-sticky-note"></i>
                        <h3>Notes</h3>
                    </div>
                    <div class="form-card-body">
                        <div class="form-group">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Additional notes..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="summary-panel">
                    <div class="summary-panel-header">
                        <i class="fas fa-calculator"></i>
                        <span>Summary</span>
                    </div>
                    <div class="summary-panel-body">
                        <div class="summary-line">
                            <span>Total Float:</span>
                            <span class="summary-value"><?php echo formatCurrency($total_float); ?></span>
                        </div>
                        <div class="summary-line">
                            <span>Cash Balance:</span>
                            <span class="summary-value"><?php echo formatCurrency($total_cash); ?></span>
                        </div>
                        <div class="summary-line summary-line-total">
                            <span>Grand Total:</span>
                            <span class="summary-value"><?php echo formatCurrency($grand_total); ?></span>
                        </div>
                    </div>
                </div>

                <!-- ACTIONS -->
                <div class="actions-bottom">
                    <a href="index.php?branch=<?php echo $selected_branch; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Create Evening Stock
                    </button>
                </div>

            </form>

        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
:root {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-input: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(5, 150, 105, 0.08);
    --green-primary: #059669;
    --green-dark: #047857;
    --green-darker: #065F46;
    --green-light: #D1FAE5;
    --green-lighter: #A7F3D0;
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --green-light: #065F46;
    --green-lighter: #047857;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

.branch-indicator {
    background: linear-gradient(135deg, #059669 0%, #047857 50%, #065F46 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.35);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFF; flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.3);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px; color: #FFF;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px; color: #FFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700; color: #FFF;
    padding: 3px 12px; background: rgba(255,255,255,0.2);
    border-radius: 12px; border: 1px solid rgba(255,255,255,0.25);
}
.branch-location {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: rgba(255,255,255,0.9);
    padding: 4px 12px; background: rgba(255,255,255,0.12);
    border-radius: 12px; white-space: nowrap;
}
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255,255,255,0.12);
    border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);
    color: #FFF; text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; }

.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }

.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close {
    background: transparent; border: none; font-size: 22px;
    color: inherit; cursor: pointer; opacity: 0.6;
}

.waiting-card {
    background: var(--bg-card);
    border-radius: 16px;
    border: 2px dashed var(--green-primary);
    padding: 60px 30px;
    text-align: center;
    box-shadow: 0 4px 16px var(--shadow-color);
    margin-bottom: 20px;
}
.waiting-icon {
    width: 90px; height: 90px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #059669;
    margin: 0 auto 20px;
    border: 3px solid #6EE7B7;
    animation: pulse 2s ease-in-out infinite;
}
.waiting-icon.waiting-warning {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border-color: #FCD34D;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
html.dark-mode .waiting-icon {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #6EE7B7;
    border-color: #10B981;
}
html.dark-mode .waiting-icon.waiting-warning {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FCD34D;
    border-color: #F59E0B;
}
.waiting-title {
    font-size: 24px; font-weight: 800;
    color: var(--text-primary); margin: 0 0 12px 0;
}
.waiting-text {
    font-size: 14px; color: var(--text-secondary);
    max-width: 600px; margin: 0 auto 24px;
    line-height: 1.7;
}
.waiting-text strong {
    color: var(--green-primary);
    background: var(--green-light);
    padding: 2px 8px; border-radius: 6px;
    font-weight: 800;
}
html.dark-mode .waiting-text strong { background: #065F46; color: #6EE7B7; }

/* ✅ CREATOR INFO */
.creator-info {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-secondary);
    padding: 8px 14px;
    background: var(--green-light);
    border-radius: 8px;
    border: 1px solid #A7F3D0;
    margin-top: 8px;
}
.creator-info i { color: var(--green-primary); font-size: 14px; }
.creator-info strong { color: var(--green-darker); }
html.dark-mode .creator-info {
    background: #065F46;
    border-color: #10B981;
    color: #D1FAE5;
}
html.dark-mode .creator-info strong { color: #6EE7B7; }

.waiting-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

.branch-picker-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px; max-width: 900px; margin: 0 auto; padding: 0 20px;
}
.branch-picker-card {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
}
.branch-picker-card:hover {
    border-color: #059669;
    background: var(--green-light);
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.25);
}
html.dark-mode .branch-picker-card:hover {
    background: #065F46;
}
.bpc-icon {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #059669, #047857);
    color: #FFF;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.bpc-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.bpc-name {
    font-size: 14px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bpc-code {
    font-size: 10px; font-weight: 700;
    color: #059669;
    background: var(--green-light);
    padding: 2px 8px; border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}
html.dark-mode .bpc-code { background: #065F46; color: #6EE7B7; }
.bpc-arrow { color: #94a3b8; font-size: 14px; transition: all 0.3s ease; }
.branch-picker-card:hover .bpc-arrow { color: #059669; transform: translateX(4px); }

.source-info-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 2px solid #059669;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.source-info-header {
    padding: 12px 20px;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800;
    color: #FFFFFF;
    text-transform: uppercase; letter-spacing: 1px;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}
.source-info-body {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
}
.source-detail {
    padding: 14px 20px;
    display: flex; flex-direction: column;
    gap: 4px;
    border-right: 1px solid var(--border-color);
    min-width: 0;
}
.source-detail:last-child { border-right: none; }
.source-detail-label {
    font-size: 10px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
}
.source-detail-value {
    font-size: 13px; font-weight: 800;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    word-break: break-word;
}

.form-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.form-card-header {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    padding: 14px 20px;
    display: flex; align-items: center; gap: 10px;
    color: #FFF;
}
.form-card-header i { font-size: 18px; }
.form-card-header h3 { font-size: 15px; font-weight: 800; margin: 0; flex: 1; }
.providers-count {
    font-size: 11px; font-weight: 700;
    padding: 4px 12px;
    background: rgba(255,255,255,0.2);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.3);
}
.form-card-body { padding: 20px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-group label {
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-control {
    padding: 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%;
}
.form-control:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
    background: var(--bg-card);
}
textarea.form-control { resize: vertical; min-height: 80px; font-family: 'Inter', sans-serif; }
.money-input {
    font-size: 18px !important;
    font-weight: 800 !important;
    font-family: 'Inter', 'Courier New', monospace !important;
    letter-spacing: 0.5px;
    text-align: right;
}
.readonly-input {
    background: #F0FDF4 !important;
    border: 2px solid #86EFAC !important;
    color: #065F46 !important;
    cursor: not-allowed;
    font-weight: 900;
    pointer-events: none;
    user-select: none;
    -webkit-user-select: none;
}
html.dark-mode .readonly-input {
    background: #065F46 !important;
    border-color: #10B981 !important;
    color: #6EE7B7 !important;
}

/* ✅ PROVIDERS GRID - GREEN THEME */
.providers-grid-3 { 
    display: grid; 
    grid-template-columns: repeat(3, 1fr); 
    gap: 14px; 
}
.provider-input-card.provider-input-green {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%, #A7F3D0 100%);
    border: 2px solid #6EE7B7;
    border-radius: 14px;
    overflow: hidden;
    transition: all 0.3s ease;
    position: relative;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.12);
}
.provider-input-card.provider-input-green::before {
    content: '';
    position: absolute;
    top: -40px;
    right: -40px;
    width: 120px;
    height: 120px;
    background: rgba(16, 185, 129, 0.15);
    border-radius: 50%;
    pointer-events: none;
    z-index: 0;
}
html.dark-mode .provider-input-card.provider-input-green {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%);
    border-color: #10B981;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
}
.provider-input-card.provider-input-green:hover {
    border-color: #059669;
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.25);
    transform: translateY(-3px);
}
html.dark-mode .provider-input-card.provider-input-green:hover {
    border-color: #34D399;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.35);
}

.provider-input-header {
    padding: 12px 14px;
    background: rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(10px);
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.2);
    display: flex; align-items: center; gap: 10px;
    position: relative;
    z-index: 1;
}
html.dark-mode .provider-input-header {
    background: rgba(15, 23, 42, 0.3);
    border-bottom-color: rgba(16, 185, 129, 0.3);
}
.provider-input-icon {
    width: 38px; height: 38px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFF; font-size: 15px; flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
}
.provider-input-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.provider-input-name {
    font-size: 13px; font-weight: 800;
    color: #065F46;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    letter-spacing: -0.2px;
}
html.dark-mode .provider-input-name { color: #D1FAE5; }
.provider-input-code {
    font-size: 10px; font-weight: 700;
    color: #047857;
    background: rgba(255, 255, 255, 0.7);
    padding: 2px 8px;
    border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
    border: 1px solid rgba(5, 150, 105, 0.3);
}
html.dark-mode .provider-input-code {
    background: rgba(15, 23, 42, 0.4);
    color: #6EE7B7;
    border-color: rgba(16, 185, 129, 0.4);
}
.provider-input-body { 
    padding: 12px 14px; 
    position: relative;
    z-index: 1;
}
.provider-input-body label {
    font-size: 10px; font-weight: 800;
    color: #047857;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: block; margin-bottom: 6px;
}
html.dark-mode .provider-input-body label { color: #6EE7B7; }
.provider-input-body .form-control {
    font-size: 14px;
    padding: 9px 12px;
    text-align: right;
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #065F46;
    background: rgba(255, 255, 255, 0.85);
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
html.dark-mode .provider-input-body .form-control {
    background: rgba(15, 23, 42, 0.4);
    color: #A7F3D0;
    border-color: rgba(16, 185, 129, 0.4);
}

.empty-providers {
    text-align: center;
    padding: 30px 20px;
    color: var(--text-muted);
}
.empty-providers i {
    font-size: 36px; color: var(--text-light);
    opacity: 0.5; display: block; margin-bottom: 10px;
}
.empty-providers p { margin: 0; font-size: 13px; }

.summary-panel {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    border: 2px solid #6EE7B7;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
}
html.dark-mode .summary-panel {
    background: linear-gradient(135deg, #065F46, #047857);
    border-color: #059669;
}
.summary-panel-header {
    padding: 12px 20px;
    background: rgba(5, 150, 105, 0.1);
    border-bottom: 2px solid #6EE7B7;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800;
    color: #065F46;
    text-transform: uppercase; letter-spacing: 1px;
}
html.dark-mode .summary-panel-header {
    background: rgba(0,0,0,0.15);
    border-color: #059669;
    color: #D1FAE5;
}
.summary-panel-header i { color: #059669; }
.summary-panel-body { padding: 16px 20px; }
.summary-line {
    display: flex; justify-content: space-between;
    align-items: center; padding: 8px 0;
    border-bottom: 1px dashed rgba(5, 150, 105, 0.3);
    font-size: 13px; color: #065F46; font-weight: 600;
}
html.dark-mode .summary-line { color: #D1FAE5; border-color: rgba(110, 231, 183, 0.3); }
.summary-line:last-child { border-bottom: none; }
.summary-line-total {
    padding-top: 12px;
    margin-top: 8px;
    border-top: 2px solid #059669 !important;
    font-size: 16px !important;
    font-weight: 800 !important;
    color: #065F46 !important;
}
html.dark-mode .summary-line-total { color: #A7F3D0 !important; }
.summary-value {
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: #059669;
    font-size: 15px;
}
html.dark-mode .summary-value { color: #6EE7B7; }
.summary-line-total .summary-value { font-size: 20px; color: #065F46; }
html.dark-mode .summary-line-total .summary-value { color: #D1FAE5; }

.actions-bottom {
    display: flex; gap: 12px;
    justify-content: flex-end;
    padding: 20px 0;
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    text-decoration: none; white-space: nowrap;
}
.btn-primary {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #FFF;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(5, 150, 105, 0.5);
    color: #FFF;
}
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-secondary {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-input); color: var(--text-primary); }

@media (max-width: 1024px) {
    .providers-grid-3 { grid-template-columns: repeat(2, 1fr); }
    .source-info-body { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .providers-grid-3 { grid-template-columns: 1fr; }
    .source-info-body { grid-template-columns: 1fr; }
    .source-detail { border-right: none; border-bottom: 1px solid var(--border-color); }
    .source-detail:last-child { border-bottom: none; }
    .actions-bottom { flex-direction: column-reverse; }
    .actions-bottom .btn { width: 100%; justify-content: center; }
    .waiting-actions { flex-direction: column; }
    .waiting-actions .btn { width: 100%; justify-content: center; }
    .waiting-title { font-size: 20px; }
    .waiting-icon { width: 70px; height: 70px; font-size: 32px; }
    .branch-picker-grid { grid-template-columns: 1fr; padding: 0 10px; }
}
</style>

<script>
function validateForm() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });

    // Completely block readonly inputs
    document.querySelectorAll('.readonly-input').forEach(function(input) {
        input.addEventListener('keydown', function(e) { e.preventDefault(); return false; });
        input.addEventListener('paste', function(e) { e.preventDefault(); return false; });
        input.addEventListener('cut', function(e) { e.preventDefault(); return false; });
        input.addEventListener('drop', function(e) { e.preventDefault(); return false; });
        input.addEventListener('dragover', function(e) { e.preventDefault(); return false; });
        input.addEventListener('contextmenu', function(e) { e.preventDefault(); return false; });
        input.addEventListener('focus', function(e) { this.blur(); });
    });

    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 8000);
    }
});
</script>

</body>
</html>