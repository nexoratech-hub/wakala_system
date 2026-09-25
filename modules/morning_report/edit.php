<?php
// ================================================================
// FILE: modules/morning_report/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT MORNING REPORT (ADMIN) - FINAL
// 
// FEATURES:
//    - Edit existing morning report
//    - Loads current data from morning_reports
//    - Updates morning_reports + morning_report_providers
//    - Syncs to daily_reports + daily_report_providers
//    - Cannot edit if is_locked = 1
//    - Cannot edit future dates
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
// GET REPORT ID
// ============================================================
$report_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($report_id <= 0) {
    $_SESSION['error_message'] = 'Invalid morning report ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH MORNING REPORT
// ============================================================
$stmt = $db->prepare("
    SELECT 
        mr.*,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        b.location AS branch_location,
        es.stock_number AS source_stock_number,
        es.stock_date AS source_stock_date
    FROM morning_reports mr
    LEFT JOIN employees e ON mr.employee_id = e.id
    LEFT JOIN branches b ON mr.branch_id = b.id
    LEFT JOIN evening_stocks es ON mr.source_evening_stock_id = es.id
    WHERE mr.id = ?
");
$stmt->execute([$report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['error_message'] = 'Morning report not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// BLOCK EDIT IF LOCKED
// ============================================================
if (intval($report['is_locked']) === 1) {
    $_SESSION['error_message'] = 'Cannot edit a locked morning report.';
    header('Location: view.php?id=' . $report_id);
    exit();
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$branch_id = intval($report['branch_id']);
$branch_name = $report['branch_display_name'] ?? 'Unknown';
$branch_code = $report['branch_display_code'] ?? '';
$branch_location = $report['branch_location'] ?? '';

// ============================================================
// FETCH EXISTING PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT 
        mrp.*,
        p.icon_class, 
        p.color_code, 
        p.provider_type, 
        p.display_order
    FROM morning_report_providers mrp
    LEFT JOIN providers p ON mrp.provider_id = p.id
    WHERE mrp.report_id = ?
    ORDER BY p.display_order, mrp.provider_name
");
$stmt->execute([$report_id]);
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

// Build provider list for the form (combine existing + branch)
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
        'float_balance' => $existing ? floatval($existing['float_balance']) : 0,
        'has_existing' => $existing ? true : false,
    ];
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$current_total_float = 0;
foreach ($existing_providers as $ep) {
    $current_total_float += floatval($ep['float_balance']);
}
$current_cash = floatval(str_replace(',', '', $report['cash_balance'] ?? 0));
$current_cumm = $current_total_float + $current_cash;

// ============================================================
// HANDLE POST - UPDATE
// ============================================================
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_morning_report') {
    try {
        $db->beginTransaction();

        $post_date       = $_POST['report_date'] ?? $report['report_date'];
        $post_cash       = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $post_notes      = trim($_POST['notes'] ?? '');
        $post_providers  = $_POST['providers'] ?? [];

        // ------------------------------------------------------------
        // VALIDATION
        // ------------------------------------------------------------
        if ($post_cash < 0) {
            throw new Exception('Cash balance cannot be negative.');
        }
        if (strtotime($post_date) > strtotime(date('Y-m-d'))) {
            throw new Exception('Report date cannot be in the future.');
        }

        // Re-check lock
        $stmt = $db->prepare("SELECT is_locked FROM morning_reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $lock_check = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lock_check || intval($lock_check['is_locked']) === 1) {
            throw new Exception('Cannot edit a locked morning report.');
        }

        // ------------------------------------------------------------
        // Calculate total float
        // ------------------------------------------------------------
        $total_float = 0;
        foreach ($post_providers as $pid => $float) {
            $fv = floatval(str_replace(',', '', $float));
            if ($fv < 0) {
                throw new Exception('Provider float cannot be negative.');
            }
            $total_float += $fv;
        }
        $cumm_total = $total_float + $post_cash;

        if ($total_float <= 0 && $post_cash <= 0) {
            throw new Exception('Report cannot be empty. Provide at least one float or cash balance.');
        }

        // Save old values for log
        $old_report_number = $report['report_number'];
        $old_cash = floatval($report['cash_balance']);
        $old_total = floatval($report['cumm_total']);

        // ------------------------------------------------------------
        // 1) UPDATE morning_reports
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            UPDATE morning_reports 
            SET report_date = ?,
                cash_balance = ?,
                cumm_total = ?,
                notes = ?,
                provider_data = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $post_date,
            $post_cash,
            $cumm_total,
            $post_notes,
            json_encode($post_providers),
            $report_id
        ]);

        // ------------------------------------------------------------
        // 2) DELETE old morning_report_providers
        // ------------------------------------------------------------
        $stmt = $db->prepare("DELETE FROM morning_report_providers WHERE report_id = ?");
        $stmt->execute([$report_id]);

        // ------------------------------------------------------------
        // 3) INSERT new morning_report_providers
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            INSERT INTO morning_report_providers
            (report_id, provider_id, provider_code, provider_name, 
             float_balance, cash_balance, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");

        // Provider map
        $provider_map = [];
        $stmt2 = $db->prepare("
            SELECT p.id, p.provider_name, bp.provider_code
            FROM providers p
            INNER JOIN branch_providers bp ON bp.provider_id = p.id
            WHERE bp.branch_id = ? AND bp.is_active = 1
        ");
        $stmt2->execute([$branch_id]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $provider_map[intval($row['id'])] = $row;
        }

        foreach ($post_providers as $pid => $float) {
            $pid = intval($pid);
            $fv = floatval(str_replace(',', '', $float));
            if ($pid <= 0 || $fv <= 0) continue;

            $pinfo = $provider_map[$pid] ?? null;
            if (!$pinfo) continue;

            $stmt->execute([
                $report_id, 
                $pid, 
                $pinfo['provider_code'], 
                $pinfo['provider_name'], 
                $fv
            ]);
        }

        // ------------------------------------------------------------
        // 4) UPDATE daily_reports
        // ------------------------------------------------------------
        $stmt_dr = $db->prepare("
            UPDATE daily_reports 
            SET report_date = ?,
                morning_total = ?,
                current_cash = ?,
                current_float = ?,
                total_business_income = ?,
                current_capital = ?,
                notes = ?,
                updated_at = NOW()
            WHERE morning_report_id = ?
        ");
        $stmt_dr->execute([
            $post_date,
            $cumm_total,
            $post_cash,
            $total_float,
            $cumm_total,
            $cumm_total,
            $post_notes,
            $report_id
        ]);

        // ------------------------------------------------------------
        // 5) Get daily_report_id for updating providers
        // ------------------------------------------------------------
        $stmt = $db->prepare("SELECT id FROM daily_reports WHERE morning_report_id = ? LIMIT 1");
        $stmt->execute([$report_id]);
        $dr = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dr) {
            $daily_report_id = intval($dr['id']);

            // Delete old daily_report_providers
            $stmt = $db->prepare("DELETE FROM daily_report_providers WHERE daily_report_id = ?");
            $stmt->execute([$daily_report_id]);

            // Insert new daily_report_providers
            $stmt_drp = $db->prepare("
                INSERT INTO daily_report_providers 
                (daily_report_id, provider_id, provider_code, provider_name,
                 morning_float, morning_cash, current_float, current_cash,
                 total_deposits, total_withdrawals, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, 0, NOW())
            ");

            foreach ($post_providers as $pid => $float) {
                $pid = intval($pid);
                $fv = floatval(str_replace(',', '', $float));
                if ($pid <= 0 || $fv <= 0) continue;

                $pinfo = $provider_map[$pid] ?? null;
                if (!$pinfo) continue;

                $stmt_drp->execute([
                    $daily_report_id, 
                    $pid, 
                    $pinfo['provider_code'], 
                    $pinfo['provider_name'],
                    $fv, 
                    $fv
                ]);
            }
        }

        // ------------------------------------------------------------
        // LOG ACTIVITY
        // ------------------------------------------------------------
        logActivity(
            $user_id, 
            'Edit Morning Report', 
            'Morning Report', 
            $report_id, 
            $old_report_number,
            'Updated ' . $old_report_number . 
            ' - Cash: ' . number_format($old_cash) . ' → ' . number_format($post_cash) . 
            ' | Total: ' . number_format($old_total) . ' → ' . number_format($cumm_total)
        );

        $db->commit();

        $_SESSION['success_message'] = 'Morning Report ' . $report['report_number'] . ' updated successfully!';
        header('Location: view.php?id=' . $report_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
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
                <a href="view.php?id=<?php echo $report_id; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Report</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#F59E0B;"></i> Edit Morning Report</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($report['report_number']); ?>
                </p>
            </div>
        </div>

        <!-- ALERT -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- INFO BANNER -->
        <div class="info-banner">
            <div class="info-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="info-content">
                <h4>Editing Existing Report</h4>
                <p>
                    You are editing the morning report for 
                    <strong><?php echo date('d M Y', strtotime($report['report_date'])); ?></strong>.
                    Original values are pre-loaded. Changes will sync to the linked daily report automatically.
                </p>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- SOURCE INFO CARD (Read-only) -->
        <!-- ============================================================ -->
        <div class="source-info-card <?php echo !empty($report['source_stock_number']) ? 'source-evening' : 'source-capital'; ?>">
            <div class="source-info-header">
                <?php if (!empty($report['source_stock_number'])): ?>
                    <i class="fas fa-moon"></i>
                    <span>Original Source: Evening Stock</span>
                <?php else: ?>
                    <i class="fas fa-coins"></i>
                    <span>Original Source: Opening Capital</span>
                <?php endif; ?>
            </div>
            <div class="source-info-body">
                <div class="source-detail">
                    <span class="source-detail-label">Reference</span>
                    <span class="source-detail-value">
                        <?php echo htmlspecialchars($report['source_stock_number'] ?? 'OPENING-CAPITAL'); ?>
                    </span>
                </div>
                <div class="source-detail">
                    <span class="source-detail-label">Date</span>
                    <span class="source-detail-value">
                        <?php echo !empty($report['source_stock_date']) 
                            ? date('d M Y', strtotime($report['source_stock_date'])) 
                            : date('d M Y', strtotime($report['report_date'])); ?>
                    </span>
                </div>
                <div class="source-detail">
                    <span class="source-detail-label">Submitted By</span>
                    <span class="source-detail-value">
                        <?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- FORM -->
        <!-- ============================================================ -->
        <form method="POST" action="" class="edit-form" id="editForm" onsubmit="return validateEdit()">
            <input type="hidden" name="action" value="edit_morning_report">

            <!-- CASH BALANCE -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-money-bill-wave"></i>
                    <h3>Cash Balance</h3>
                </div>
                <div class="form-card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Report Date <span class="required">*</span></label>
                            <input type="date" name="report_date" id="report_date" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($report['report_date']); ?>" 
                                   required>
                            <small class="form-hint">You can change the report date</small>
                        </div>
                        <div class="form-group">
                            <label>Cash Balance (TSh) <span class="required">*</span></label>
                            <input type="text" name="cash_balance" id="cash_balance" 
                                   class="form-control money-input" 
                                   value="<?php echo number_format($current_cash); ?>" 
                                   required
                                   oninput="formatMoneyInput(this); recalcTotals();">
                            <small class="form-hint">Editable field</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PROVIDERS -->
            <div class="form-card">
                <div class="form-card-header">
                    <i class="fas fa-university"></i>
                    <h3>Provider Floats</h3>
                    <span class="providers-count"><?php echo count($form_providers); ?> providers</span>
                </div>
                <div class="form-card-body">
                    <?php if (count($form_providers) > 0): ?>
                        <div class="providers-grid">
                            <?php foreach ($form_providers as $fp):
                                $pid = intval($fp['provider_id']);
                                $color = $fp['color_code'] ?? '#0B5ED7';
                                $icon = $fp['icon_class'] ?? 'fas fa-university';
                                $float_val = floatval($fp['float_balance']);
                                $has_value = $float_val > 0;
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
                                    <div class="provider-input-body">
                                        <label>Float Balance (TSh)</label>
                                        <input type="text" 
                                               name="providers[<?php echo $pid; ?>]" 
                                               class="form-control money-input provider-float-input" 
                                               value="<?php echo $has_value ? number_format($float_val) : ''; ?>"
                                               placeholder="0"
                                               data-provider-id="<?php echo $pid; ?>"
                                               oninput="formatMoneyInput(this); recalcTotals();">
                                    </div>
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
                                  placeholder="Additional notes..."><?php echo htmlspecialchars($report['notes'] ?? ''); ?></textarea>
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
                        <span class="summary-value" id="sum_float">TSh 0</span>
                    </div>
                    <div class="summary-line">
                        <span>Cash Balance:</span>
                        <span class="summary-value" id="sum_cash">TSh 0</span>
                    </div>
                    <div class="summary-line summary-line-total">
                        <span>Cumm. Total:</span>
                        <span class="summary-value" id="sum_total">TSh 0</span>
                    </div>
                </div>
            </div>

            <!-- COMPARISON CARD -->
            <div class="comparison-card">
                <div class="comparison-header">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Comparison (Original vs New)</span>
                </div>
                <div class="comparison-body">
                    <div class="comparison-row">
                        <div class="comparison-label">Cash Balance</div>
                        <div class="comparison-values">
                            <span class="comparison-old"><?php echo formatCurrency($current_cash); ?></span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="comparison-new" id="comp_cash"><?php echo formatCurrency($current_cash); ?></span>
                        </div>
                    </div>
                    <div class="comparison-row">
                        <div class="comparison-label">Total Float</div>
                        <div class="comparison-values">
                            <span class="comparison-old"><?php echo formatCurrency($current_total_float); ?></span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="comparison-new" id="comp_float"><?php echo formatCurrency($current_total_float); ?></span>
                        </div>
                    </div>
                    <div class="comparison-row comparison-row-total">
                        <div class="comparison-label">Grand Total</div>
                        <div class="comparison-values">
                            <span class="comparison-old"><?php echo formatCurrency($current_cumm); ?></span>
                            <i class="fas fa-arrow-right"></i>
                            <span class="comparison-new" id="comp_total"><?php echo formatCurrency($current_cumm); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACTIONS -->
            <div class="actions-bottom">
                <a href="view.php?id=<?php echo $report_id; ?>" class="btn btn-secondary">
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
    --shadow-color: rgba(30, 64, 175, 0.08);
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
   BRANCH INDICATOR
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
   ALERT
   ============================================================ */
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px var(--shadow-color); }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

/* ============================================================
   INFO BANNER
   ============================================================ */
.info-banner {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 1.5px solid #93C5FD;
    border-left: 5px solid #1E40AF;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 18px;
    display: flex; align-items: flex-start; gap: 14px;
}
html.dark-mode .info-banner { background: linear-gradient(135deg, #1E3A5F, #1E40AF); border-color: #3B82F6; border-left-color: #60A5FA; }
.info-icon {
    width: 44px; height: 44px; border-radius: 50%;
    background: #1E40AF; color: #FFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
html.dark-mode .info-icon { background: #3B82F6; }
.info-content h4 { font-size: 14px; font-weight: 800; color: #1E40AF; margin: 0 0 6px 0; }
html.dark-mode .info-content h4 { color: #93C5FD; }
.info-content p { font-size: 13px; color: #1E3A8A; margin: 0; line-height: 1.6; }
html.dark-mode .info-content p { color: #DBEAFE; }
.info-content strong { background: rgba(255,255,255,0.4); padding: 1px 6px; border-radius: 4px; font-weight: 800; }
html.dark-mode .info-content strong { background: rgba(0,0,0,0.25); color: #93C5FD; }

/* ============================================================
   SOURCE INFO CARD
   ============================================================ */
.source-info-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 2px solid;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.source-evening { border-color: #7C3AED; }
.source-capital { border-color: #F59E0B; }
.source-info-header {
    padding: 12px 20px;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800;
    color: #FFFFFF;
    text-transform: uppercase; letter-spacing: 1px;
}
.source-evening .source-info-header {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
}
.source-capital .source-info-header {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
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
.form-card-body { padding: 20px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
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
   PROVIDERS GRID
   ============================================================ */
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

.provider-input-body { padding: 12px 14px; }
.provider-input-body label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px; }
.provider-input-body .form-control { font-size: 14px; padding: 9px 12px; text-align: right; font-family: 'Courier New', monospace; font-weight: 700; }

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
    if (value === '') { input.value = ''; return; }
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

    const cash = parseMoney(document.getElementById('cash_balance').value);
    const total = totalFloat + cash;

    document.getElementById('sum_float').textContent = formatMoney(totalFloat);
    document.getElementById('sum_cash').textContent = formatMoney(cash);
    document.getElementById('sum_total').textContent = formatMoney(total);

    // Update comparison
    document.getElementById('comp_cash').textContent = formatMoney(cash);
    document.getElementById('comp_float').textContent = formatMoney(totalFloat);
    document.getElementById('comp_total').textContent = formatMoney(total);
}

// ============================================================
// VALIDATE
// ============================================================
function validateEdit() {
    const reportDate = document.getElementById('report_date').value;
    if (!reportDate) {
        alert('Please select report date.');
        return false;
    }

    // Check date not in future
    const today = new Date().toISOString().split('T')[0];
    if (reportDate > today) {
        alert('Report date cannot be in the future.');
        return false;
    }

    const cash = parseMoney(document.getElementById('cash_balance').value);
    if (cash < 0) {
        alert('Cash balance cannot be negative.');
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
        alert('Provider floats cannot be negative.');
        return false;
    }

    if (totalFloat <= 0 && cash <= 0) {
        alert('Report cannot be empty. Provide at least one float or cash balance.');
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
});
</script>
</body>
</html>