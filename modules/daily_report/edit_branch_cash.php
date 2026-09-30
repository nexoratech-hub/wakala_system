<?php
// ================================================================
// FILE: modules/daily_report/edit_branch_cash.php
// WAKALA FINANCIAL SYSTEM - EDIT BRANCH CASH
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
$role = $_SESSION['role'] ?? 'employee';
$is_admin = ($role === 'admin' || $role === 'super_admin');

if (!$is_admin) {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// PARAMETERS
// ============================================================
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($report_id <= 0 || $branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// HANDLE AJAX REQUESTS
// ============================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        // ============================================================
        // ✅ UPDATE BRANCH CASH (Manual Set)
        // ============================================================
        if ($_POST['ajax_action'] === 'update_branch_cash') {
            $report_id = intval($_POST['report_id'] ?? 0);
            $branch_id = intval($_POST['branch_id'] ?? 0);
            $new_cash = floatval(str_replace(',', '', $_POST['new_cash'] ?? '0'));
            
            if ($report_id <= 0 || $branch_id <= 0) {
                throw new Exception('Invalid report or branch.');
            }
            if ($new_cash < 0) {
                throw new Exception('Cash cannot be negative.');
            }
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT current_cash, report_number FROM daily_reports WHERE id = ? AND branch_id = ?");
            $stmt->execute([$report_id, $branch_id]);
            $dr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dr) {
                throw new Exception('Report not found.');
            }
            
            $old_cash = floatval($dr['current_cash'] ?? 0);
            $difference = $new_cash - $old_cash;
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = ?,
                    current_capital = current_float + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_cash, $new_cash, $report_id]);
            
            logActivity($user_id, 'Edit Branch Cash', 'Daily Report', $report_id, 
                'Old: ' . number_format($old_cash),
                'Branch cash updated: ' . number_format($old_cash) . ' → ' . number_format($new_cash) . ' (Diff: ' . ($difference >= 0 ? '+' : '') . number_format($difference) . ')');
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Branch cash updated successfully.',
                'old_cash' => $old_cash,
                'new_cash' => $new_cash,
                'difference' => $difference
            ]);
            exit();
        }
        
        // ============================================================
        // ✅ ADJUST BRANCH CASH (+ or -)
        // ============================================================
        if ($_POST['ajax_action'] === 'adjust_branch_cash') {
            $report_id = intval($_POST['report_id'] ?? 0);
            $branch_id = intval($_POST['branch_id'] ?? 0);
            $adjustment_type = $_POST['adjustment_type'] ?? 'add';
            $amount = floatval(str_replace(',', '', $_POST['amount'] ?? '0'));
            
            if ($report_id <= 0 || $branch_id <= 0) {
                throw new Exception('Invalid report or branch.');
            }
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than 0.');
            }
            if (!in_array($adjustment_type, ['add', 'subtract'])) {
                throw new Exception('Invalid adjustment type.');
            }
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT current_cash, report_number FROM daily_reports WHERE id = ? AND branch_id = ?");
            $stmt->execute([$report_id, $branch_id]);
            $dr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dr) {
                throw new Exception('Report not found.');
            }
            
            $old_cash = floatval($dr['current_cash'] ?? 0);
            
            if ($adjustment_type === 'add') {
                $new_cash = $old_cash + $amount;
            } else {
                $new_cash = $old_cash - $amount;
                if ($new_cash < 0) {
                    throw new Exception('Insufficient cash. Current: ' . formatCurrency($old_cash));
                }
            }
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = ?,
                    current_capital = current_float + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_cash, $new_cash, $report_id]);
            
            $action_label = $adjustment_type === 'add' ? 'Added' : 'Subtracted';
            logActivity($user_id, 'Adjust Branch Cash', 'Daily Report', $report_id, 
                'Old: ' . number_format($old_cash),
                'Branch cash ' . $action_label . ': ' . number_format($amount));
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Branch cash adjusted: ' . ($adjustment_type === 'add' ? '+' : '-') . formatCurrency($amount),
                'old_cash' => $old_cash,
                'new_cash' => $new_cash
            ]);
            exit();
        }
        
        // ============================================================
        // ✅ RESET BRANCH CASH
        // ============================================================
        if ($_POST['ajax_action'] === 'reset_branch_cash') {
            $report_id = intval($_POST['report_id'] ?? 0);
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            if ($report_id <= 0 || $branch_id <= 0) {
                throw new Exception('Invalid report or branch.');
            }
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT current_cash FROM daily_reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $dr = $stmt->fetch(PDO::FETCH_ASSOC);
            $old_cash = floatval($dr['current_cash'] ?? 0);
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = 0,
                    current_capital = current_float,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$report_id]);
            
            logActivity($user_id, 'Reset Branch Cash', 'Daily Report', $report_id, 
                'Old: ' . number_format($old_cash),
                'Branch cash reset to 0');
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Branch cash reset to zero.'
            ]);
            exit();
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// ============================================================
// GET REPORT INFO
// ============================================================
$stmt = $db->prepare("
    SELECT dr.*, 
           b.branch_name, b.branch_code, b.location as branch_location,
           e.full_name as employee_name
    FROM daily_reports dr
    LEFT JOIN branches b ON dr.branch_id = b.id
    LEFT JOIN employees e ON dr.employee_id = e.id
    WHERE dr.id = ? AND dr.branch_id = ?
");
$stmt->execute([$report_id, $branch_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header('Location: index.php');
    exit();
}

$current_cash = floatval($report['current_cash'] ?? 0);
$current_float = floatval($report['current_float'] ?? 0);
$current_capital = floatval($report['current_capital'] ?? 0);

// ============================================================
// GET CASH TRANSACTIONS COUNT
// ============================================================
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total_count,
        COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
        COALESCE(SUM(CASE WHEN transaction_type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawals
    FROM daily_report_transactions
    WHERE daily_report_id = ?
");
$stmt->execute([$report_id]);
$txn_summary = $stmt->fetch(PDO::FETCH_ASSOC);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BREADCRUMB -->
        <div class="breadcrumb-bar">
            <a href="index.php" class="breadcrumb-link">
                <i class="fas fa-home"></i> Daily Reports
            </a>
            <i class="fas fa-chevron-right breadcrumb-sep"></i>
            <a href="view_branch_cash.php?report_id=<?php echo $report_id; ?>&branch_id=<?php echo $branch_id; ?>" 
               class="breadcrumb-link">
                <?php echo htmlspecialchars($report['report_number']); ?>
            </a>
            <i class="fas fa-chevron-right breadcrumb-sep"></i>
            <span class="breadcrumb-current">Edit Cash</span>
        </div>

        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-edit"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Edit Branch Cash</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($report['branch_name']); ?></span>
                    <?php if ($report['branch_code']): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($report['branch_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($report['branch_location']): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($report['branch_location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                </span>
            </div>
        </div>

        <!-- VALUES SUMMARY -->
        <div class="values-summary-grid">
            <div class="value-summary-card value-card-float">
                <div class="value-card-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="value-card-content">
                    <span class="value-card-label">Current Float</span>
                    <span class="value-card-value" id="summaryFloat"><?php echo formatCurrency($current_float); ?></span>
                    <span class="value-card-sub">From providers</span>
                </div>
            </div>
            
            <div class="value-summary-card value-card-cash">
                <div class="value-card-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="value-card-content">
                    <span class="value-card-label">Current Cash</span>
                    <span class="value-card-value" id="summaryCash"><?php echo formatCurrency($current_cash); ?></span>
                    <span class="value-card-sub">Shared branch cash</span>
                </div>
            </div>
            
            <div class="value-summary-card value-card-capital">
                <div class="value-card-icon">
                    <i class="fas fa-building"></i>
                </div>
                <div class="value-card-content">
                    <span class="value-card-label">Total Capital</span>
                    <span class="value-card-value" id="summaryCapital"><?php echo formatCurrency($current_capital); ?></span>
                    <span class="value-card-sub">Float + Cash</span>
                </div>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2>
                    <i class="fas fa-edit" style="color:#D97706;"></i> 
                    Edit Branch Cash
                </h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Update branch cash using three different methods
                </p>
            </div>
            <div class="header-right">
                <a href="view_branch_cash.php?report_id=<?php echo $report_id; ?>&branch_id=<?php echo $branch_id; ?>" 
                   class="btn-action-big btn-action-back">
                    <i class="fas fa-arrow-left"></i><span>Back</span>
                </a>
            </div>
        </div>

        <!-- EDIT OPTIONS GRID -->
        <div class="edit-options-grid">
            
            <!-- OPTION 1: SET NEW VALUE -->
            <div class="edit-card">
                <div class="edit-card-header edit-card-header-blue">
                    <div class="edit-card-icon">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <div class="edit-card-header-content">
                        <h3>Set New Cash Value</h3>
                        <p>Set a new cash value directly</p>
                    </div>
                </div>
                <div class="edit-card-body">
                    <div class="edit-info-banner edit-info-blue">
                        <i class="fas fa-info-circle"></i>
                        <span>Use this to change cash to a full amount (complete override)</span>
                    </div>
                    
                    <form id="setValueForm" onsubmit="submitSetValue(event)">
                        <input type="hidden" name="ajax_action" value="update_branch_cash">
                        <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        
                        <div class="edit-form-group">
                            <label>Current Cash</label>
                            <div class="edit-current-display">
                                <i class="fas fa-money-bill-wave"></i>
                                <span><?php echo formatCurrency($current_cash); ?></span>
                            </div>
                        </div>
                        
                        <div class="edit-form-group">
                            <label>New Cash Value (TSh) <span class="required">*</span></label>
                            <input type="text" 
                                   name="new_cash" 
                                   id="newCashInput"
                                   class="edit-form-control edit-amount-input" 
                                   placeholder="0"
                                   inputmode="numeric"
                                   value="<?php echo number_format($current_cash, 0, '.', ''); ?>"
                                   required
                                   oninput="formatMoneyInput(this)">
                            <span class="edit-input-hint">
                                <i class="fas fa-info-circle"></i>
                                New cash value for this branch
                            </span>
                        </div>
                        
                        <div class="edit-form-actions">
                            <button type="submit" class="edit-btn edit-btn-primary">
                                <i class="fas fa-save"></i> Save New Value
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- OPTION 2: ADJUST CASH -->
            <div class="edit-card">
                <div class="edit-card-header edit-card-header-green">
                    <div class="edit-card-icon">
                        <i class="fas fa-plus-minus"></i>
                    </div>
                    <div class="edit-card-header-content">
                        <h3>Adjust Cash (+/-)</h3>
                        <p>Add or subtract cash by an amount</p>
                    </div>
                </div>
                <div class="edit-card-body">
                    <div class="edit-info-banner edit-info-green">
                        <i class="fas fa-info-circle"></i>
                        <span>Use this to add or subtract cash by a specific amount</span>
                    </div>
                    
                    <form id="adjustForm" onsubmit="submitAdjust(event)">
                        <input type="hidden" name="ajax_action" value="adjust_branch_cash">
                        <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        
                        <div class="edit-form-group">
                            <label>Adjustment Type <span class="required">*</span></label>
                            <div class="adjust-type-options">
                                <label class="adjust-type-option">
                                    <input type="radio" name="adjustment_type" value="add" checked>
                                    <div class="adjust-type-content adjust-type-add">
                                        <i class="fas fa-plus-circle"></i>
                                        <div>
                                            <span class="adjust-type-title">Add Cash</span>
                                            <span class="adjust-type-desc">Increase cash</span>
                                        </div>
                                    </div>
                                </label>
                                <label class="adjust-type-option">
                                    <input type="radio" name="adjustment_type" value="subtract">
                                    <div class="adjust-type-content adjust-type-subtract">
                                        <i class="fas fa-minus-circle"></i>
                                        <div>
                                            <span class="adjust-type-title">Subtract Cash</span>
                                            <span class="adjust-type-desc">Decrease cash</span>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="edit-form-group">
                            <label>Amount (TSh) <span class="required">*</span></label>
                            <input type="text" 
                                   name="amount" 
                                   id="adjustAmountInput"
                                   class="edit-form-control edit-amount-input" 
                                   placeholder="0"
                                   inputmode="numeric"
                                   required
                                   oninput="formatMoneyInput(this); previewAdjustment();">
                            <span class="edit-input-hint" id="adjustPreview">
                                <i class="fas fa-info-circle"></i>
                                Enter the amount to add or subtract
                            </span>
                        </div>
                        
                        <div class="edit-form-actions">
                            <button type="submit" class="edit-btn edit-btn-success">
                                <i class="fas fa-check"></i> Apply Adjustment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- OPTION 3: RESET CASH -->
            <div class="edit-card">
                <div class="edit-card-header edit-card-header-red">
                    <div class="edit-card-icon">
                        <i class="fas fa-eraser"></i>
                    </div>
                    <div class="edit-card-header-content">
                        <h3>Reset Cash to Zero</h3>
                        <p>Clear all branch cash</p>
                    </div>
                </div>
                <div class="edit-card-body">
                    <div class="edit-info-banner edit-info-red">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Warning: This action will clear all cash. It cannot be undone!</span>
                    </div>
                    
                    <div class="reset-preview">
                        <div class="reset-preview-label">Current Cash</div>
                        <div class="reset-preview-value"><?php echo formatCurrency($current_cash); ?></div>
                        <div class="reset-preview-arrow">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="reset-preview-new">TSh 0</div>
                    </div>
                    
                    <form id="resetForm" onsubmit="submitReset(event)">
                        <input type="hidden" name="ajax_action" value="reset_branch_cash">
                        <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        
                        <div class="edit-form-actions">
                            <button type="submit" class="edit-btn edit-btn-danger">
                                <i class="fas fa-eraser"></i> Reset to Zero
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

        <!-- HISTORY CARD -->
        <div class="history-card">
            <div class="history-card-header">
                <div class="history-card-title">
                    <i class="fas fa-history"></i>
                    Recent Cash Transactions
                </div>
                <a href="view_branch_cash.php?report_id=<?php echo $report_id; ?>&branch_id=<?php echo $branch_id; ?>" 
                   class="history-view-all">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="history-card-body">
                <div class="history-stats">
                    <div class="history-stat">
                        <div class="history-stat-icon history-stat-deposit">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <div class="history-stat-info">
                            <span class="history-stat-label">Total Deposits</span>
                            <span class="history-stat-value">+<?php echo formatCurrency($txn_summary['total_deposits'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="history-stat">
                        <div class="history-stat-icon history-stat-withdrawal">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                        <div class="history-stat-info">
                            <span class="history-stat-label">Total Withdrawals</span>
                            <span class="history-stat-value">-<?php echo formatCurrency($txn_summary['total_withdrawals'] ?? 0); ?></span>
                        </div>
                    </div>
                    <div class="history-stat">
                        <div class="history-stat-icon history-stat-count">
                            <i class="fas fa-list"></i>
                        </div>
                        <div class="history-stat-info">
                            <span class="history-stat-label">Total Transactions</span>
                            <span class="history-stat-value"><?php echo number_format($txn_summary['total_count'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toastContainer"></div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
.main-wrapper, .main-wrapper *, .main-wrapper *::before, .main-wrapper *::after { box-sizing: border-box; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; }
.main-wrapper .main-content {
    padding: 16px 20px !important;
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

:root {
    --bg-body: #f3f4f6; --bg-card: #ffffff;
    --bg-table-even: #fafafa; --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --text-primary: #1f2937; --text-secondary: #374151;
    --text-muted: #6b7280; --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
}
html.dark-mode {
    --bg-body: #0f172a; --bg-card: #1e293b;
    --bg-table-even: #1a2332; --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9; --text-secondary: #cbd5e1;
    --text-muted: #94a3b8; --text-light: #64748b;
    --border-color: #334155;
}

/* BREADCRUMB */
.breadcrumb-bar {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 16px; background: var(--bg-card);
    border-radius: 10px; margin-bottom: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 6px var(--shadow-color);
    flex-wrap: wrap;
}
.breadcrumb-link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; color: #D97706;
    text-decoration: none; transition: all 0.2s ease;
    padding: 4px 10px; border-radius: 6px;
}
.breadcrumb-link:hover { background: #FEF3C7; color: #B45309; }
.breadcrumb-link i { font-size: 11px; }
.breadcrumb-sep { font-size: 9px; color: var(--text-light); }
.breadcrumb-current {
    font-size: 12px; font-weight: 800;
    color: var(--text-primary); padding: 4px 10px;
    background: var(--bg-input); border-radius: 6px;
}

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(217, 119, 6, 0.35);
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-indicator::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%; pointer-events: none;
}
.branch-indicator-left {
    display: flex; align-items: center; gap: 14px;
    flex-wrap: wrap; flex: 1; position: relative;
    z-index: 1; min-width: 0;
}
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.9;
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-indicator-name { font-weight: 800; font-size: 16px; }
.branch-indicator-code {
    font-size: 11px; font-weight: 700; padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2); border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
    font-family: 'Courier New', monospace;
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 11px;
    color: rgba(255,255,255,0.9); padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12); border-radius: 12px;
    white-space: nowrap;
}
.branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.date-display {
    font-size: 13px; color: rgba(255,255,255,0.95);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.15);
    border-radius: 16px; display: flex; align-items: center;
    gap: 6px; font-weight: 600;
}

/* VALUES SUMMARY GRID */
.values-summary-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 14px; margin-bottom: 20px;
}
.value-summary-card {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 22px; border-radius: 14px;
    border: 2px solid;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative; overflow: hidden; min-width: 0;
}
.value-summary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}
.value-summary-card::before {
    content: ''; position: absolute;
    top: -30px; right: -30px;
    width: 120px; height: 120px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    pointer-events: none;
}
.value-card-float {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-color: #93C5FD;
}
.value-card-float .value-card-icon {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
}
.value-card-float .value-card-value { color: #1D4ED8; }
.value-card-float .value-card-label { color: #1E40AF; }

.value-card-cash {
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border-color: #FCD34D;
}
.value-card-cash .value-card-icon {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
    color: #FFFFFF;
}
.value-card-cash .value-card-value { color: #B45309; }
.value-card-cash .value-card-label { color: #92400E; }

.value-card-capital {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border-color: #C4B5FD;
}
.value-card-capital .value-card-icon {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
}
.value-card-capital .value-card-value { color: #6D28D9; }
.value-card-capital .value-card-label { color: #5B21B6; }

.value-card-icon {
    width: 54px; height: 54px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    position: relative; z-index: 1;
}
.value-card-content {
    display: flex; flex-direction: column; gap: 2px;
    min-width: 0; flex: 1; position: relative; z-index: 1;
}
.value-card-label {
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 1px;
    opacity: 0.85;
}
.value-card-value {
    font-size: 20px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2;
    word-break: break-word;
}
.value-card-sub {
    font-size: 10px; font-weight: 600;
    color: var(--text-muted); opacity: 0.9;
}

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 18px;
    flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 6px 0 0 0;
    display: flex; align-items: center; gap: 6px;
}
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-action-big {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px; padding: 12px 22px; border: none; border-radius: 10px;
    font-size: 13px; font-weight: 800; cursor: pointer;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
    letter-spacing: 0.5px; text-transform: uppercase;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.btn-action-back {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-action-back:hover {
    background: var(--bg-table-hover); color: var(--text-primary);
    transform: translateY(-2px);
}

/* EDIT OPTIONS GRID */
.edit-options-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 18px; margin-bottom: 20px;
}
.edit-card {
    background: var(--bg-card); border-radius: 16px;
    border: 1.5px solid var(--border-color);
    overflow: hidden; box-shadow: 0 4px 16px var(--shadow-color);
    transition: all 0.3s ease;
    display: flex; flex-direction: column;
}
.edit-card:hover {
    box-shadow: 0 8px 28px var(--shadow-hover);
    transform: translateY(-3px);
}
.edit-card-header {
    padding: 18px 22px; display: flex;
    align-items: center; gap: 14px;
    color: #FFFFFF; position: relative; overflow: hidden;
}
.edit-card-header::before {
    content: ''; position: absolute;
    top: -50%; right: -20%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.edit-card-header-blue { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); }
.edit-card-header-green { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.edit-card-header-red { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }

.edit-card-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    position: relative; z-index: 1;
}
.edit-card-header-content {
    flex: 1; min-width: 0; position: relative; z-index: 1;
}
.edit-card-header-content h3 {
    font-size: 15px; font-weight: 800;
    margin: 0 0 2px 0; color: #FFFFFF;
}
.edit-card-header-content p {
    font-size: 11px; margin: 0;
    color: rgba(255, 255, 255, 0.85); font-weight: 500;
}
.edit-card-body {
    padding: 20px 22px; flex: 1;
    display: flex; flex-direction: column;
}

/* INFO BANNER */
.edit-info-banner {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; border-radius: 10px;
    margin-bottom: 16px; font-size: 12px;
    font-weight: 600; line-height: 1.4;
}
.edit-info-banner i { font-size: 14px; flex-shrink: 0; }
.edit-info-blue { background: #EFF6FF; color: #1E40AF; border: 1.5px solid #93C5FD; }
.edit-info-green { background: #ECFDF5; color: #047857; border: 1.5px solid #6EE7B7; }
.edit-info-red { background: #FEF2F2; color: #991B1B; border: 1.5px solid #FCA5A5; }
html.dark-mode .edit-info-blue { background: #1E3A5F; color: #93C5FD; border-color: #3B82F6; }
html.dark-mode .edit-info-green { background: #064E3B; color: #6EE7B7; border-color: #10B981; }
html.dark-mode .edit-info-red { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

/* FORM */
.edit-form-group { margin-bottom: 14px; }
.edit-form-group label {
    display: block; font-size: 11px; font-weight: 800;
    color: var(--text-muted); text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 6px;
}
.edit-form-group label .required { color: #DC2626; }
.edit-form-control {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px; font-size: 13px;
    color: var(--text-primary); background: var(--bg-input);
    font-family: 'Inter', sans-serif; transition: all 0.3s ease;
}
.edit-form-control:focus {
    outline: none; border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    background: var(--bg-card);
}
.edit-amount-input {
    font-size: 18px !important; font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 0.5px; text-align: right;
    padding-right: 18px;
}
.edit-input-hint {
    display: flex; align-items: center; gap: 5px;
    font-size: 10px; color: var(--text-muted);
    margin-top: 5px; font-weight: 500;
}
.edit-input-hint i { font-size: 10px; }

/* CURRENT DISPLAY */
.edit-current-display {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border: 1.5px solid #FCD34D;
    border-radius: 10px; font-size: 18px;
    font-weight: 900; font-family: 'Inter', 'Courier New', monospace;
    color: #B45309;
}
.edit-current-display i { font-size: 18px; color: #D97706; }
html.dark-mode .edit-current-display {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #FCD34D; color: #FCD34D;
}
html.dark-mode .edit-current-display i { color: #FCD34D; }

/* ADJUST TYPE OPTIONS */
.adjust-type-options {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
.adjust-type-option { cursor: pointer; position: relative; }
.adjust-type-option input[type="radio"] {
    position: absolute; opacity: 0; pointer-events: none;
}
.adjust-type-content {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 14px; background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 10px; transition: all 0.25s ease;
}
.adjust-type-content > i {
    font-size: 22px; flex-shrink: 0; transition: all 0.25s ease;
}
.adjust-type-content > div {
    display: flex; flex-direction: column;
    gap: 1px; min-width: 0;
}
.adjust-type-title {
    font-size: 12px; font-weight: 800; color: var(--text-primary);
}
.adjust-type-desc {
    font-size: 10px; font-weight: 500; color: var(--text-muted);
}
.adjust-type-add > i { color: #059669; }
.adjust-type-subtract > i { color: #DC2626; }
.adjust-type-option input[type="radio"]:checked + .adjust-type-add {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border-color: #10B981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);
}
.adjust-type-option input[type="radio"]:checked + .adjust-type-subtract {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-color: #DC2626;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}
.adjust-type-option:hover .adjust-type-content { border-color: #FCA5A5; }

/* RESET PREVIEW */
.reset-preview {
    display: flex; flex-direction: column;
    align-items: center; gap: 10px;
    padding: 20px;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border: 2px solid #FCA5A5;
    border-radius: 14px; margin-bottom: 16px;
}
html.dark-mode .reset-preview {
    background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%);
    border-color: #DC2626;
}
.reset-preview-label {
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 1px;
    color: #991B1B;
}
html.dark-mode .reset-preview-label { color: #FCA5A5; }
.reset-preview-value {
    font-size: 24px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #DC2626;
}
html.dark-mode .reset-preview-value { color: #FEE2E2; }
.reset-preview-arrow {
    font-size: 20px; color: #DC2626; opacity: 0.6;
}
.reset-preview-new {
    font-size: 24px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: #059669;
}
html.dark-mode .reset-preview-new { color: #6EE7B7; }

/* FORM ACTIONS */
.edit-form-actions {
    margin-top: auto; padding-top: 14px;
}
.edit-btn {
    display: inline-flex; align-items: center; justify-content: center;
    gap: 8px; width: 100%; padding: 13px 24px;
    border: none; border-radius: 10px;
    font-size: 13px; font-weight: 800;
    cursor: pointer; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.edit-btn-primary {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.3);
}
.edit-btn-primary:hover {
    background: linear-gradient(135deg, #1D4ED8 0%, #1E40AF 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5);
}
.edit-btn-success {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.3);
}
.edit-btn-success:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
}
.edit-btn-danger {
    background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.3);
}
.edit-btn-danger:hover {
    background: linear-gradient(135deg, #B91C1C 0%, #DC2626 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.5);
}
.edit-btn:disabled {
    opacity: 0.6; cursor: not-allowed; transform: none;
}

/* HISTORY CARD */
.history-card {
    background: var(--bg-card); border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color);
}
.history-card-header {
    display: flex; justify-content: space-between;
    align-items: center; padding: 16px 22px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
    flex-wrap: wrap; gap: 12px;
}
.history-card-title {
    display: flex; align-items: center; gap: 10px;
    font-size: 14px; font-weight: 800;
    color: var(--text-primary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.history-card-title i { color: #D97706; }
.history-view-all {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700;
    color: #D97706; text-decoration: none;
    padding: 6px 12px; border-radius: 8px;
    transition: all 0.2s ease;
}
.history-view-all:hover { background: #FEF3C7; color: #B45309; }
.history-card-body { padding: 20px 22px; }
.history-stats {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px;
}
.history-stat {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px; background: var(--bg-input);
    border-radius: 12px; border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
}
.history-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.08);
}
.history-stat-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0; border: 2px solid;
}
.history-stat-deposit {
    background: #DCFCE7; color: #15803D; border-color: #86EFAC;
}
.history-stat-withdrawal {
    background: #FEE2E2; color: #991B1B; border-color: #FCA5A5;
}
.history-stat-count {
    background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD;
}
html.dark-mode .history-stat-deposit { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .history-stat-withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .history-stat-count { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.history-stat-info {
    display: flex; flex-direction: column; gap: 2px; min-width: 0;
}
.history-stat-label {
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: var(--text-muted);
}
.history-stat-value {
    font-size: 16px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
}

/* TOAST NOTIFICATIONS */
.toast-container {
    position: fixed; top: 20px; right: 20px;
    z-index: 99999; display: flex; flex-direction: column;
    gap: 10px; pointer-events: none;
}
.toast {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 20px; background: var(--bg-card);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    border: 2px solid; font-size: 13px;
    font-weight: 700; min-width: 300px; max-width: 420px;
    pointer-events: auto;
    animation: slideInRight 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative; overflow: hidden;
}
.toast::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 4px;
}
.toast-success { border-color: #10B981; color: #065F46; }
.toast-success::before { background: #10B981; }
.toast-error { border-color: #DC2626; color: #991B1B; }
.toast-error::before { background: #DC2626; }
html.dark-mode .toast-success { color: #6EE7B7; background: #064E3B; }
html.dark-mode .toast-error { color: #FCA5A5; background: #7F1D1D; }

.toast-icon {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0; color: #FFFFFF;
}
.toast-success .toast-icon { background: #10B981; }
.toast-error .toast-icon { background: #DC2626; }
.toast-content { flex: 1; min-width: 0; }
.toast-title { font-size: 13px; font-weight: 800; margin-bottom: 2px; }
.toast-message { font-size: 11px; font-weight: 500; opacity: 0.85; }

@keyframes slideInRight {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes slideOutRight {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(120%); opacity: 0; }
}
.toast.hiding { animation: slideOutRight 0.3s ease forwards; }

/* RESPONSIVE */
@media (max-width: 1200px) {
    .values-summary-grid { grid-template-columns: repeat(3, 1fr); }
    .edit-options-grid { grid-template-columns: 1fr 1fr; }
    .edit-options-grid .edit-card:last-child { grid-column: span 2; }
}
@media (max-width: 900px) {
    .edit-options-grid { grid-template-columns: 1fr; }
    .edit-options-grid .edit-card:last-child { grid-column: span 1; }
}
@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    .values-summary-grid { grid-template-columns: 1fr; }
    .value-card-value { font-size: 18px; }
    .value-card-icon { width: 48px; height: 48px; font-size: 20px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn-action-big { flex: 1; justify-content: center; }
    .adjust-type-options { grid-template-columns: 1fr; }
    .history-stats { grid-template-columns: 1fr; }
    .history-stat-value { font-size: 14px; }
    .toast-container { top: 10px; right: 10px; left: 10px; }
    .toast { min-width: 0; max-width: none; width: 100%; }
}
@media (max-width: 480px) {
    .value-card-value { font-size: 16px; }
    .value-card-icon { width: 42px; height: 42px; font-size: 18px; }
    .edit-amount-input { font-size: 16px !important; }
    .edit-current-display { font-size: 16px; }
    .reset-preview-value, .reset-preview-new { font-size: 20px; }
    .breadcrumb-bar { padding: 8px 12px; gap: 5px; }
    .breadcrumb-link, .breadcrumb-current { font-size: 11px; padding: 3px 8px; }
}
</style>

<script>
// ============================================================
// MONEY FORMAT
// ============================================================
function formatMoneyInput(input) {
    const cursorPos = input.selectionStart;
    const oldValue = input.value;
    const oldLength = oldValue.length;
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
    const newLength = formatted.length;
    const newCursorPos = cursorPos + (newLength - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) { }
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

function formatMoney(num) {
    return 'TSh ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// CURRENT VALUES
// ============================================================
const CURRENT_CASH = <?php echo $current_cash; ?>;
const CURRENT_FLOAT = <?php echo $current_float; ?>;

// ============================================================
// PREVIEW ADJUSTMENT
// ============================================================
function previewAdjustment() {
    const amount = parseMoney(document.getElementById('adjustAmountInput').value);
    const type = document.querySelector('input[name="adjustment_type"]:checked').value;
    const preview = document.getElementById('adjustPreview');
    
    if (amount <= 0) {
        preview.innerHTML = '<i class="fas fa-info-circle"></i> Enter the amount to add or subtract';
        preview.style.color = 'var(--text-muted)';
        return;
    }
    
    let newCash;
    if (type === 'add') {
        newCash = CURRENT_CASH + amount;
        preview.innerHTML = `<i class="fas fa-arrow-right"></i> New Cash: <strong>${formatMoney(newCash)}</strong>`;
        preview.style.color = '#059669';
    } else {
        newCash = CURRENT_CASH - amount;
        if (newCash < 0) {
            preview.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Insufficient! (Current: ${formatMoney(CURRENT_CASH)})`;
            preview.style.color = '#DC2626';
        } else {
            preview.innerHTML = `<i class="fas fa-arrow-right"></i> New Cash: <strong>${formatMoney(newCash)}</strong>`;
            preview.style.color = '#DC2626';
        }
    }
}

document.querySelectorAll('input[name="adjustment_type"]').forEach(radio => {
    radio.addEventListener('change', previewAdjustment);
});

// ============================================================
// TOAST NOTIFICATION
// ============================================================
function showToast(type, title, message) {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
    
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas ${iconClass}"></i>
        </div>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ============================================================
// SUBMIT: SET NEW VALUE
// ============================================================
async function submitSetValue(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHtml = submitBtn.innerHTML;
    
    const newCash = parseMoney(formData.get('new_cash'));
    
    if (newCash < 0) {
        showToast('error', 'Error', 'Cash cannot be negative.');
        return;
    }
    
    if (!confirm(`Are you sure you want to change cash to ${formatMoney(newCash)}?\n\nFrom: ${formatMoney(CURRENT_CASH)}`)) {
        return;
    }
    
    formData.set('new_cash', newCash);
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            showToast('success', 'Success', data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast('error', 'Error', data.message || 'An error occurred.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    } catch (err) {
        showToast('error', 'Error', 'Network error. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
    }
}

// ============================================================
// SUBMIT: ADJUST CASH
// ============================================================
async function submitAdjust(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHtml = submitBtn.innerHTML;
    
    const amount = parseMoney(formData.get('amount'));
    const type = formData.get('adjustment_type');
    
    if (amount <= 0) {
        showToast('error', 'Error', 'Please enter an amount greater than 0.');
        return;
    }
    
    const typeLabel = type === 'add' ? 'add' : 'subtract';
    if (!confirm(`Are you sure you want to ${typeLabel} ${formatMoney(amount)}?\n\nCurrent: ${formatMoney(CURRENT_CASH)}`)) {
        return;
    }
    
    formData.set('amount', amount);
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying...';
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            showToast('success', 'Success', data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast('error', 'Error', data.message || 'An error occurred.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    } catch (err) {
        showToast('error', 'Error', 'Network error. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
    }
}

// ============================================================
// SUBMIT: RESET CASH
// ============================================================
async function submitReset(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalHtml = submitBtn.innerHTML;
    
    if (!confirm(`Are you sure you want to clear all cash?\n\nCurrent: ${formatMoney(CURRENT_CASH)}\n\nThis action cannot be undone!`)) {
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            showToast('success', 'Success', data.message);
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast('error', 'Error', data.message || 'An error occurred.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    } catch (err) {
        showToast('error', 'Error', 'Network error. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const active = document.activeElement;
        if (active && active.tagName === 'INPUT') active.blur();
    }
});

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    previewAdjustment();
});
</script>

</body>
</html>