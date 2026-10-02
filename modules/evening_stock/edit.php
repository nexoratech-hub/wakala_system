<?php
// ================================================================
// FILE: modules/evening_stock/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT EVENING STOCK (ADMIN) - FINAL
// 
// FEATURES:
//    - Edit existing evening stock
//    - Loads current data from evening_stocks
//    - Updates evening_stocks + evening_stock_providers
//    - cumm_total = FLOAT ONLY (not float + cash)
//    - cash_balance unchanged (from daily_reports.current_cash)
//    - Full English UI
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET STOCK ID
// ============================================================
$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    $_SESSION['error_message'] = 'Invalid evening stock ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH EVENING STOCK
// ============================================================
$stmt = $db->prepare("
    SELECT 
        es.*,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        b.location AS branch_location,
        dr.report_number AS daily_report_number,
        dr.report_date AS daily_report_date
    FROM evening_stocks es
    LEFT JOIN employees e ON es.employee_id = e.id
    LEFT JOIN branches b ON es.branch_id = b.id
    LEFT JOIN daily_reports dr ON es.daily_report_id = dr.id
    WHERE es.id = ?
");
$stmt->execute([$stock_id]);
$stock = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stock) {
    $_SESSION['error_message'] = 'Evening stock not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$branch_id = intval($stock['branch_id']);
$branch_name = $stock['branch_display_name'] ?? 'Unknown';
$branch_code = $stock['branch_display_code'] ?? '';
$branch_location = $stock['branch_location'] ?? '';

// ============================================================
// FETCH EXISTING PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        esp.*,
        p.icon_class, 
        p.color_code, 
        p.provider_type, 
        p.display_order
    FROM evening_stock_providers esp
    LEFT JOIN providers p ON esp.provider_id = p.id
    WHERE esp.evening_stock_id = ?
    ORDER BY p.display_order, esp.provider_name
");
$stmt->execute([$stock_id]);
$existing_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// GET ALL BRANCH PROVIDERS (for adding new ones)
// ============================================================
$stmt = $db->prepare("
    SELECT 
        p.id, 
        p.provider_name, 
        p.icon_class, 
        p.color_code, 
        p.provider_type, 
        p.display_order, 
        bp.provider_code
    FROM providers p
    INNER JOIN branch_providers bp ON bp.provider_id = p.id
    WHERE bp.branch_id = ? AND bp.is_active = 1
    ORDER BY p.display_order, p.provider_name
");
$stmt->execute([$branch_id]);
$branch_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build map: provider_id => existing provider data
$existing_map = [];
foreach ($existing_providers as $ep) {
    $existing_map[intval($ep['provider_id'])] = $ep;
}

// Build provider list for the form
$form_providers = [];
foreach ($branch_providers as $bp) {
    $pid = intval($bp['id']);
    $existing = $existing_map[$pid] ?? null;
    
    $form_providers[] = [
        'provider_id' => $pid,
        'provider_code' => $bp['provider_code'],
        'provider_name' => $bp['provider_name'],
        'icon_class' => $bp['icon_class'],
        'color_code' => $bp['color_code'],
        'provider_type' => $bp['provider_type'],
        'display_order' => $bp['display_order'],
        'opening_float' => $existing ? floatval($existing['opening_float']) : 0,
        'opening_cash' => $existing ? floatval($existing['opening_cash']) : 0,
        'closing_float' => $existing ? floatval($existing['closing_float']) : 0,
        'closing_cash' => $existing ? floatval($existing['closing_cash']) : 0,
        'total_deposits' => $existing ? floatval($existing['total_deposits']) : 0,
        'total_withdrawals' => $existing ? floatval($existing['total_withdrawals']) : 0,
        'has_existing' => $existing ? true : false,
    ];
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$current_total_float = 0;
foreach ($existing_providers as $ep) {
    $current_total_float += floatval($ep['closing_float']);
}
$current_cash = floatval(str_replace(',', '', $stock['cash_balance'] ?? 0));
$current_grand_total = $current_total_float + $current_cash;

// ============================================================
// HANDLE POST - UPDATE
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_evening_stock') {
    try {
        $db->beginTransaction();

        $post_date       = $_POST['stock_date'] ?? $stock['stock_date'];
        $post_notes      = trim($_POST['notes'] ?? '');
        $post_status     = $_POST['status'] ?? $stock['status'];
        $post_providers  = $_POST['providers'] ?? [];

        // ------------------------------------------------------------
        // VALIDATION
        // ------------------------------------------------------------
        if (!in_array($post_status, ['waiting', 'approved', 'adjusted', 'rejected'])) {
            throw new Exception('Invalid status.');
        }
        if (strtotime($post_date) > strtotime(date('Y-m-d'))) {
            throw new Exception('Stock date cannot be in the future.');
        }

        // Calculate total float
        $total_float = 0;
        foreach ($post_providers as $pid => $float) {
            $fv = floatval(str_replace(',', '', $float));
            if ($fv < 0) {
                throw new Exception('Provider closing float cannot be negative.');
            }
            $total_float += $fv;
        }

        // Save old values for log
        $old_stock_number = $stock['stock_number'];
        $old_status = $stock['status'];
        $old_total_float = $current_total_float;

        // ------------------------------------------------------------
        // 1) UPDATE evening_stocks
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            UPDATE evening_stocks 
            SET stock_date = ?,
                cumm_total = ?,
                status = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $post_date,
            $total_float,
            $post_status,
            $post_notes,
            $stock_id
        ]);

        // ------------------------------------------------------------
        // 2) UPDATE evening_stock_providers
        // ------------------------------------------------------------
        $stmt_update = $db->prepare("
            UPDATE evening_stock_providers 
            SET closing_float = ?, updated_at = NOW()
            WHERE evening_stock_id = ? AND provider_id = ?
        ");

        foreach ($post_providers as $pid => $float) {
            $pid = intval($pid);
            $fv = floatval(str_replace(',', '', $float));
            
            if ($pid <= 0) continue;
            
            $stmt_update->execute([$fv, $stock_id, $pid]);
        }

        // ------------------------------------------------------------
        // LOG ACTIVITY
        // ------------------------------------------------------------
        logActivity(
            $user_id, 
            'Edit Evening Stock', 
            'Evening Stock', 
            $stock_id, 
            $old_stock_number,
            'Updated ' . $old_stock_number . 
            ' - Status: ' . $old_status . ' → ' . $post_status .
            ' | Float: ' . number_format($old_total_float) . ' → ' . number_format($total_float)
        );

        $db->commit();

        $_SESSION['success_message'] = 'Evening Stock ' . $stock['stock_number'] . ' updated successfully!';
        header('Location: view.php?id=' . $stock_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
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
        
        <!-- ============================================================
        BRANCH INDICATOR - AMBER
        ============================================================ -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($branch_location): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch_location); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <a href="view.php?id=<?php echo $stock_id; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Stock</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#F59E0B;"></i> Edit Evening Stock</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($stock['stock_number']); ?>
                </p>
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
        INFO BANNER
        ============================================================ -->
        <div class="info-banner">
            <div class="info-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="info-content">
                <h4>Editing Existing Evening Stock</h4>
                <p>
                    You are editing the evening stock for 
                    <strong><?php echo date('d M Y', strtotime($stock['stock_date'])); ?></strong>.
                    Original closing balances are pre-loaded. Note that <strong>cumm_total = FLOAT ONLY</strong> 
                    (cash is a separate balance).
                </p>
            </div>
        </div>

        <!-- ============================================================
        SOURCE INFO CARD (Read-only)
        ============================================================ -->
        <div class="source-info-card">
            <div class="source-info-header">
                <i class="fas fa-moon"></i>
                <span>Original Source Information</span>
            </div>
            <div class="source-info-body">
                <div class="source-detail">
                    <span class="source-detail-label">Stock Number</span>
                    <span class="source-detail-value"><?php echo htmlspecialchars($stock['stock_number']); ?></span>
                </div>
                <div class="source-detail">
                    <span class="source-detail-label">Daily Report</span>
                    <span class="source-detail-value">
                        <?php echo htmlspecialchars($stock['daily_report_number'] ?? 'N/A'); ?>
                    </span>
                </div>
                <div class="source-detail">
                    <span class="source-detail-label">Submitted By</span>
                    <span class="source-detail-value">
                        <?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FORM
        ============================================================ -->
        <form method="POST" action="" class="edit-form" id="editForm" onsubmit="return validateEdit()">
            <input type="hidden" name="action" value="edit_evening_stock">

            <!-- ============================================================ -->
            <!-- STOCK DATE + STATUS -->
            <!-- ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-calendar-alt"></i>
                    <h3>Stock Information</h3>
                </div>
                <div class="form-card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock Date <span class="required">*</span></label>
                            <input type="date" name="stock_date" id="stock_date" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($stock['stock_date']); ?>" 
                                   required>
                            <small class="form-hint">You can change the stock date</small>
                        </div>
                        <div class="form-group">
                            <label>Status <span class="required">*</span></label>
                            <select name="status" id="status" class="form-control" required>
                                <option value="waiting" <?php echo $stock['status'] == 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                                <option value="approved" <?php echo $stock['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="adjusted" <?php echo $stock['status'] == 'adjusted' ? 'selected' : ''; ?>>Adjusted</option>
                                <option value="rejected" <?php echo $stock['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <small class="form-hint">Current status of this stock</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- CASH BALANCE (READ-ONLY DISPLAY) -->
            <!-- ============================================================ -->
            <div class="form-card form-card-cash">
                <div class="form-card-header form-card-header-cash">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>Cash Balance</h3>
                    <span class="readonly-badge">
                        <i class="fas fa-lock"></i> Locked
                    </span>
                </div>
                <div class="form-card-body">
                    <div class="cash-display">
                        <div class="cash-display-item">
                            <span class="cash-display-label">Branch Cash (from Daily Report)</span>
                            <span class="cash-display-value"><?php echo formatCurrency($current_cash); ?></span>
                        </div>
                        <div class="cash-display-info">
                            <i class="fas fa-info-circle"></i>
                            Cash balance is locked and cannot be edited. It comes from the linked daily report's current cash.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- PROVIDERS -->
            <!-- ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-university"></i>
                    <h3>Provider Closing Balances</h3>
                    <span class="providers-count"><?php echo count($form_providers); ?> providers</span>
                </div>
                <div class="form-card-body">
                    <div class="section-hint">
                        <i class="fas fa-info-circle"></i>
                        Edit closing float for each provider. Opening balances and cash are read-only.
                    </div>

                    <?php if (count($form_providers) > 0): ?>
                        <div class="providers-grid">
                            <?php foreach ($form_providers as $fp):
                                $pid = intval($fp['provider_id']);
                                $color = $fp['color_code'] ?? '#0B5ED7';
                                $icon = $fp['icon_class'] ?? 'fas fa-university';
                                $closing_float = floatval($fp['closing_float']);
                                $opening_float = floatval($fp['opening_float']);
                                $has_value = $closing_float > 0;
                                $changed = ($closing_float != $opening_float);
                            ?>
                                <div class="provider-input-card <?php echo $has_value ? 'has-value' : ''; ?>">
                                    <div class="provider-input-header">
                                        <div class="provider-input-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                            <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                        </div>
                                        <div class="provider-input-info">
                                            <span class="provider-input-name">
                                                <?php echo htmlspecialchars($fp['provider_name']); ?>
                                            </span>
                                            <span class="provider-input-code">
                                                <?php echo htmlspecialchars($fp['provider_code']); ?>
                                            </span>
                                        </div>
                                        <?php if ($has_value): ?>
                                            <span class="active-badge">
                                                <i class="fas fa-check-circle"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Opening Float (read-only) -->
                                    <div class="provider-opening-row">
                                        <span class="por-label">Opening:</span>
                                        <span class="por-value"><?php echo formatCurrency($opening_float); ?></span>
                                    </div>

                                    <!-- Closing Float (editable) -->
                                    <div class="provider-input-body">
                                        <label>Closing Float (TSh)</label>
                                        <input type="text" 
                                               name="providers[<?php echo $pid; ?>]" 
                                               class="form-control money-input provider-float-input" 
                                               value="<?php echo $has_value ? number_format($closing_float, 0, '.', '') : '0'; ?>"
                                               placeholder="0"
                                               data-provider-id="<?php echo $pid; ?>"
                                               data-original="<?php echo $closing_float; ?>"
                                               oninput="formatMoneyInput(this); recalcTotals();">
                                    </div>

                                    <!-- Deposits/Withdrawals (read-only info) -->
                                    <?php if (floatval($fp['total_deposits']) > 0 || floatval($fp['total_withdrawals']) > 0): ?>
                                    <div class="provider-activity-row">
                                        <?php if (floatval($fp['total_deposits']) > 0): ?>
                                            <span class="par-item par-deposit">
                                                <i class="fas fa-arrow-down"></i>
                                                +<?php echo formatCurrency($fp['total_deposits']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (floatval($fp['total_withdrawals']) > 0): ?>
                                            <span class="par-item par-withdrawal">
                                                <i class="fas fa-arrow-up"></i>
                                                -<?php echo formatCurrency($fp['total_withdrawals']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-providers">
                            <i class="fas fa-info-circle"></i>
                            <p>No providers found for this branch.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- NOTES -->
            <!-- ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-sticky-note"></i>
                    <h3>Notes</h3>
                </div>
                <div class="form-card-body">
                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="3" 
                                  placeholder="Additional notes..."><?php echo htmlspecialchars($stock['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- SUMMARY PANEL -->
            <!-- ============================================================ -->
            <div class="summary-panel">
                <div class="summary-panel-header">
                    <i class="fas fa-calculator"></i>
                    <span>Live Summary</span>
                </div>
                <div class="summary-panel-body">
                    <div class="summary-line">
                        <span>Total Closing Float:</span>
                        <span class="summary-value" id="sum_float">TSh 0</span>
                    </div>
                    <div class="summary-line">
                        <span>Cash Balance (Locked):</span>
                        <span class="summary-value" id="sum_cash"><?php echo formatCurrency($current_cash); ?></span>
                    </div>
                    <div class="summary-line summary-line-total">
                        <span>Grand Total:</span>
                        <span class="summary-value" id="sum_total"><?php echo formatCurrency($current_cash); ?></span>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- COMPARISON CARD -->
            <!-- ============================================================ -->
            <div class="comparison-card">
                <div class="comparison-header">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Comparison (Original vs New)</span>
                </div>
                <div class="comparison-body">
                    <div class="comparison-row">
                        <div class="comparison-label">Total Float</div>
                        <div class="comparison-values">
                            <span class="comparison-old"><?php echo formatCurrency($current_total_float); ?></span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="comparison-new" id="comp_float"><?php echo formatCurrency($current_total_float); ?></span>
                        </div>
                    </div>
                    <div class="comparison-row">
                        <div class="comparison-label">Cash Balance</div>
                        <div class="comparison-values">
                            <span class="comparison-old"><?php echo formatCurrency($current_cash); ?></span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="comparison-new"><?php echo formatCurrency($current_cash); ?></span>
                        </div>
                    </div>
                    <div class="comparison-row comparison-row-total">
                        <div class="comparison-label">Grand Total</div>
                        <div class="comparison-values">
                            <span class="comparison-old"><?php echo formatCurrency($current_grand_total); ?></span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="comparison-new" id="comp_total"><?php echo formatCurrency($current_grand_total); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ACTIONS -->
            <!-- ============================================================ -->
            <div class="actions-bottom">
                <a href="view.php?id=<?php echo $stock_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>

        </form>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   VARIABLES
   ============================================================ */
:root {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-input: #f8fafc;
    --bg-table-even: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(245, 158, 11, 0.08);
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --bg-table-even: #1a2332;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   BRANCH INDICATOR - AMBER
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 50%, #B45309 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(217, 119, 6, 0.3);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper { width: 42px; height: 42px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #FFF; flex-shrink: 0; border: 1.5px solid rgba(255,255,255,0.3); }
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; color: #FFF; }
.branch-indicator-name { font-weight: 800; font-size: 16px; color: #FFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
.branch-indicator-code { font-size: 11px; font-weight: 700; color: #FFF; padding: 3px 12px; background: rgba(255,255,255,0.2); border-radius: 12px; border: 1px solid rgba(255,255,255,0.25); }
.branch-location { display: flex; align-items: center; gap: 5px; font-size: 12px; color: rgba(255,255,255,0.9); padding: 4px 12px; background: rgba(255,255,255,0.12); border-radius: 12px; white-space: nowrap; }
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255,255,255,0.12);
    border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);
    color: #FFF; text-decoration: none; font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0; display: flex; align-items: center; gap: 6px; font-family: 'Courier New', monospace; font-weight: 600; }

/* ============================================================
   ALERTS
   ============================================================ */
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px var(--shadow-color); }
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

/* ============================================================
   INFO BANNER
   ============================================================ */
.info-banner {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1.5px solid #FCD34D;
    border-left: 5px solid #D97706;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 18px;
    display: flex; align-items: flex-start; gap: 14px;
}
html.dark-mode .info-banner { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #D97706; border-left-color: #FBBF24; }
.info-icon {
    width: 44px; height: 44px; border-radius: 50%;
    background: #D97706; color: #FFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
}
html.dark-mode .info-icon { background: #F59E0B; }
.info-content h4 { font-size: 14px; font-weight: 800; color: #78350F; margin: 0 0 6px 0; }
html.dark-mode .info-content h4 { color: #FCD34D; }
.info-content p { font-size: 13px; color: #78350F; margin: 0; line-height: 1.6; }
html.dark-mode .info-content p { color: #FDE68A; }
.info-content strong { background: rgba(255,255,255,0.5); padding: 1px 6px; border-radius: 4px; font-weight: 800; }
html.dark-mode .info-content strong { background: rgba(0,0,0,0.25); color: #FCD34D; }

/* ============================================================
   SOURCE INFO CARD
   ============================================================ */
.source-info-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 2px solid #7C3AED;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.source-info-header {
    padding: 12px 20px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800;
    color: #FFFFFF;
    text-transform: uppercase; letter-spacing: 1px;
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

/* ============================================================
   FORM CARDS
   ============================================================ */
.form-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.form-card-header {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
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
.readonly-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10px; font-weight: 800;
    padding: 4px 12px;
    background: rgba(255,255,255,0.25);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.4);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.form-card-body { padding: 20px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-group:last-child { margin-bottom: 0; }
.form-group label {
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-group label .required { color: #DC2626; }
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
    border-color: #F59E0B;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
    background: var(--bg-card);
}
.form-hint { font-size: 10px; color: var(--text-muted); font-weight: 500; }
textarea.form-control { resize: vertical; min-height: 80px; font-family: 'Inter', sans-serif; }
.money-input {
    font-size: 18px !important;
    font-weight: 800 !important;
    font-family: 'Inter', 'Courier New', monospace !important;
    letter-spacing: 0.5px;
    text-align: right;
}

/* ============================================================
   CASH CARD (READ-ONLY)
   ============================================================ */
.form-card-cash { border-color: #6EE7B7; }
.form-card-header-cash {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}
.cash-display {
    display: flex; flex-direction: column; gap: 12px;
}
.cash-display-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border: 2px solid #6EE7B7;
    border-radius: 12px;
}
html.dark-mode .cash-display-item { background: linear-gradient(135deg, #064E3B, #065F46); border-color: #10B981; }
.cash-display-label {
    font-size: 11px; font-weight: 800;
    color: #047857;
    text-transform: uppercase; letter-spacing: 0.8px;
}
html.dark-mode .cash-display-label { color: #6EE7B7; }
.cash-display-value {
    font-size: 24px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #065F46;
    word-break: break-all;
}
html.dark-mode .cash-display-value { color: #D1FAE5; }
.cash-display-info {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 10px 14px;
    background: var(--bg-input);
    border-radius: 8px;
    border-left: 3px solid #F59E0B;
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.5;
}
.cash-display-info i { color: #F59E0B; margin-top: 2px; flex-shrink: 0; }

/* ============================================================
   PROVIDERS GRID
   ============================================================ */
.section-hint {
    font-size: 12px;
    color: var(--text-secondary);
    background: var(--bg-input);
    padding: 10px 14px;
    border-radius: 8px;
    margin: 0 0 16px 0;
    border-left: 3px solid #F59E0B;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-hint i { color: #F59E0B; }

.providers-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.provider-input-card {
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    position: relative;
}
.provider-input-card:hover { border-color: #F59E0B; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15); }
.provider-input-card.has-value { border-color: #10B981; background: #F0FDF4; }
html.dark-mode .provider-input-card.has-value { background: #064E3B; border-color: #10B981; }

.provider-input-header {
    padding: 12px 14px;
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 10px;
    position: relative;
}
html.dark-mode .provider-input-card.has-value .provider-input-header { background: rgba(16, 185, 129, 0.1); }
.provider-input-icon {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFF; font-size: 15px; flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.provider-input-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.provider-input-name { font-size: 12px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.provider-input-code { font-size: 9px; font-weight: 700; color: #D97706; background: #FEF3C7; padding: 1px 6px; border-radius: 5px; align-self: flex-start; font-family: 'Courier New', monospace; }
html.dark-mode .provider-input-code { background: #5F3A1E; color: #FBBF24; }

.active-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px; height: 22px;
    background: #10B981;
    color: #FFF;
    border-radius: 50%;
    font-size: 11px;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.4);
}

/* Opening Row (read-only) */
.provider-opening-row {
    padding: 8px 14px;
    background: rgba(245, 158, 11, 0.05);
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid rgba(245, 158, 11, 0.15);
    font-size: 11px;
}
.por-label {
    font-weight: 700; color: #92400E;
    text-transform: uppercase; letter-spacing: 0.5px;
}
html.dark-mode .por-label { color: #FCD34D; }
.por-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #D97706;
    font-size: 12px;
}
html.dark-mode .por-value { color: #FBBF24; }

.provider-input-body { padding: 12px 14px; }
.provider-input-body label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px; }
.provider-input-body .form-control { font-size: 14px; padding: 9px 12px; text-align: right; font-family: 'Courier New', monospace; font-weight: 700; }

/* Activity Row (deposits/withdrawals) */
.provider-activity-row {
    padding: 8px 14px;
    background: var(--bg-card);
    display: flex; justify-content: space-between; align-items: center;
    gap: 6px;
    font-size: 10px;
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
}
.par-item {
    display: inline-flex; align-items: center; gap: 3px;
    font-family: 'Courier New', monospace;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 6px;
    white-space: nowrap;
}
.par-deposit { color: #059669; background: #D1FAE5; }
.par-withdrawal { color: #DC2626; background: #FEE2E2; }
html.dark-mode .par-deposit { color: #34D399; background: #065F46; }
html.dark-mode .par-withdrawal { color: #FCA5A5; background: #7F1D1D; }

.empty-providers { text-align: center; padding: 30px 20px; color: var(--text-muted); }
.empty-providers i { font-size: 36px; color: var(--text-light); opacity: 0.5; display: block; margin-bottom: 10px; }
.empty-providers p { margin: 0; font-size: 13px; }

/* ============================================================
   SUMMARY PANEL
   ============================================================ */
.summary-panel {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
}
html.dark-mode .summary-panel { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #D97706; }
.summary-panel-header {
    padding: 12px 20px;
    background: rgba(217, 119, 6, 0.1);
    border-bottom: 2px solid #FCD34D;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800; color: #78350F;
    text-transform: uppercase; letter-spacing: 1px;
}
html.dark-mode .summary-panel-header { background: rgba(0,0,0,0.15); border-color: #D97706; color: #FDE68A; }
.summary-panel-header i { color: #D97706; }
.summary-panel-body { padding: 16px 20px; }
.summary-line { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed rgba(217, 119, 6, 0.3); font-size: 13px; color: #78350F; font-weight: 600; }
html.dark-mode .summary-line { color: #FDE68A; border-color: rgba(252, 211, 77, 0.3); }
.summary-line:last-child { border-bottom: none; }
.summary-line-total { padding-top: 12px; margin-top: 8px; border-top: 2px solid #D97706 !important; font-size: 16px !important; font-weight: 800 !important; color: #78350F !important; }
html.dark-mode .summary-line-total { color: #FCD34D !important; }
.summary-value { font-family: 'Courier New', monospace; font-weight: 800; color: #D97706; font-size: 15px; }
html.dark-mode .summary-value { color: #FBBF24; }
.summary-line-total .summary-value { font-size: 20px; color: #78350F; }
html.dark-mode .summary-line-total .summary-value { color: #FCD34D; }

/* ============================================================
   COMPARISON CARD
   ============================================================ */
.comparison-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid #C4B5FD;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(124, 58, 237, 0.1);
}
.comparison-header {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    padding: 12px 20px;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800; color: #FFF;
    text-transform: uppercase; letter-spacing: 1px;
}
.comparison-header i { font-size: 16px; }
.comparison-body { padding: 16px 20px; }
.comparison-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px dashed var(--border-color);
    gap: 16px;
    flex-wrap: wrap;
}
.comparison-row:last-child { border-bottom: none; }
.comparison-row-total {
    padding-top: 14px;
    margin-top: 6px;
    border-top: 2px solid #7C3AED !important;
}
.comparison-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.comparison-values {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.comparison-old {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    font-size: 14px;
    color: var(--text-muted);
    text-decoration: line-through;
    opacity: 0.7;
}
.comparison-values i {
    color: #7C3AED;
    font-size: 14px;
}
.comparison-new {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    font-size: 15px;
    color: #7C3AED;
}
.comparison-row-total .comparison-new { font-size: 18px; color: #6D28D9; }
html.dark-mode .comparison-new { color: #C4B5FD; }
html.dark-mode .comparison-row-total .comparison-new { color: #DDD6FE; }

/* ============================================================
   ACTIONS
   ============================================================ */
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
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFF;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(217, 119, 6, 0.5);
    color: #FFF;
}
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-secondary {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-input); color: var(--text-primary); }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .providers-grid { grid-template-columns: repeat(2, 1fr); }
    .source-info-body { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .providers-grid { grid-template-columns: 1fr; }
    .source-info-body { grid-template-columns: 1fr; }
    .source-detail { border-right: none; border-bottom: 1px solid var(--border-color); }
    .source-detail:last-child { border-bottom: none; }
    .actions-bottom { flex-direction: column-reverse; }
    .actions-bottom .btn { width: 100%; justify-content: center; }
    .info-banner { flex-direction: column; }
    .comparison-values { flex-direction: column; align-items: flex-start; gap: 4px; }
    .comparison-values i { transform: rotate(90deg); }
}
</style>

<script>
// ============================================================
// MONEY FORMAT
// ============================================================
function formatMoneyInput(input) {
    const cursorPos = input.selectionStart;
    const oldLength = input.value.length;
    let value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = '0'; recalcTotals(); return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);
    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    const newCursorPos = cursorPos + (formatted.length - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) {}
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

function formatMoney(num) {
    return 'TSh ' + Number(num).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// RECALCULATE TOTALS
// ============================================================
function recalcTotals() {
    let totalFloat = 0;
    document.querySelectorAll('.provider-float-input').forEach(function(input) {
        totalFloat += parseMoney(input.value);
    });

    const cash = <?php echo floatval($current_cash); ?>;
    const total = totalFloat + cash;

    document.getElementById('sum_float').textContent = formatMoney(totalFloat);
    document.getElementById('sum_cash').textContent = formatMoney(cash);
    document.getElementById('sum_total').textContent = formatMoney(total);

    // Update comparison
    document.getElementById('comp_float').textContent = formatMoney(totalFloat);
    document.getElementById('comp_total').textContent = formatMoney(total);
}

// ============================================================
// VALIDATE
// ============================================================
function validateEdit() {
    const stockDate = document.getElementById('stock_date').value;
    if (!stockDate) {
        alert('Please select stock date.');
        return false;
    }

    // Check date not in future
    const today = new Date().toISOString().split('T')[0];
    if (stockDate > today) {
        alert('Stock date cannot be in the future.');
        return false;
    }

    // Validate provider floats
    let invalid = false;
    let totalFloat = 0;
    document.querySelectorAll('.provider-float-input').forEach(function(input) {
        const val = parseMoney(input.value);
        totalFloat += val;
        if (val < 0) {
            invalid = true;
            input.style.borderColor = '#DC2626';
        } else {
            input.style.borderColor = '';
        }
    });

    if (invalid) {
        alert('Provider closing floats cannot be negative.');
        return false;
    }

    if (totalFloat <= 0) {
        alert('Total closing float must be greater than 0.');
        return false;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    return true;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    recalcTotals();

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
            setTimeout(function() { if (successAlert.parentElement) successAlert.remove(); }, 400);
        }, 5000);
    }
});
</script>

</body>
</html>