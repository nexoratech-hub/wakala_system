<?php
// ================================================================
// FILE: modules/store_cash_out/create.php
// WAKALA FINANCIAL SYSTEM - ADD STORE CASH OUT
// 🔴 RED THEME — SCOPED CSS — SIDEBAR SAFE
// ✅ Money format: 1,000,000,000
// ✅ Default Source: PROFIT
// ✅ Capital = current_cash from latest daily report
// ✅ Profit = net_profit from daily reports
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
    $_SESSION['error_message'] = 'Only administrators can create cash out records.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET USER BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT branch_id, branch, full_name FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
$user_branch_id = intval($emp['branch_id'] ?? 0);
$user_branch_name = $emp['branch'] ?? 'Main';

$branches = [];
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// HELPER: Get available balances per branch
// ============================================================
if (!function_exists('getAvailableBalances')) {
    function getAvailableBalances($db, $branch_id) {
        // ✅ CAPITAL = current_cash kutoka daily report ya mwisho
        $stmt = $db->prepare("
            SELECT current_cash, current_float 
            FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        $available_cash = floatval($latest_dr['current_cash'] ?? 0);
        $available_float = floatval($latest_dr['current_float'] ?? 0);
        
        // ✅ PROFIT = net_profit (jumla) - profit iliyotumika
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(net_profit), 0) as total_profit
            FROM daily_reports 
            WHERE branch_id = ?
        ");
        $stmt->execute([$branch_id]);
        $profit_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_profit = floatval($profit_row['total_profit'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_profit_cashout
            FROM store_cash_out 
            WHERE branch_id = ? 
            AND source = 'profit'
            AND status = 'approved'
        ");
        $stmt->execute([$branch_id]);
        $cashout_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $profit_used = floatval($cashout_row['total_profit_cashout'] ?? 0);
        
        $available_profit = max(0, $total_profit - $profit_used);
        
        return [
            'capital' => $available_cash,   // ✅ Capital = current_cash
            'cash' => $available_cash,
            'float' => $available_float,
            'profit' => $available_profit,
        ];
    }
}

$branch_balances = [];
foreach ($branches as $b) {
    $branch_balances[$b['id']] = getAvailableBalances($db, $b['id']);
}

// ============================================================
// HELPER: Generate Capital Number
// ============================================================
if (!function_exists('generateCapitalNumberLocal')) {
    function generateCapitalNumberLocal($db) {
        $prefix = 'CAP-' . date('Ymd') . '-';
        $stmt = $db->prepare("SELECT capital_number FROM capital_management
                              WHERE capital_number LIKE ?
                              ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $new_num = $last ? intval(substr($last['capital_number'], -4)) + 1 : 1;
        return $prefix . str_pad($new_num, 4, '0', STR_PAD_LEFT);
    }
}

$cashout_number = generateCashOutNumber($db);

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cashout_date = isset($_POST['cashout_date']) ? trim($_POST['cashout_date']) : date('Y-m-d');
        $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : 0;
        $source = isset($_POST['source']) && in_array($_POST['source'], ['capital', 'profit']) 
            ? $_POST['source'] : 'profit';

        $amount_raw = isset($_POST['amount']) ? $_POST['amount'] : '0';
        $amount_clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $amount_raw));
        $amount = floatval($amount_clean);

        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
        $taken_by = isset($_POST['taken_by']) ? trim($_POST['taken_by']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        $status = 'approved';
        $approved_by = $emp['full_name'] ?? 'Admin';
        $approved_date = date('Y-m-d');

        $errors = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cashout_date)) {
            $errors[] = 'Invalid date format.';
        }

        if ($branch_id <= 0) {
            $errors[] = 'Please select a branch.';
        }

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than 0.';
        }

        // CHECK BALANCE
        if (empty($errors)) {
            $balances = getAvailableBalances($db, $branch_id);
            
            if ($source === 'capital') {
                if ($balances['capital'] < $amount) {
                    $errors[] = 'Insufficient Capital! Available: ' . 
                        formatCurrency($balances['capital']) . 
                        '. Requested: ' . formatCurrency($amount);
                }
            } elseif ($source === 'profit') {
                if ($balances['profit'] < $amount) {
                    $errors[] = 'Insufficient Profit! Available: ' . 
                        formatCurrency($balances['profit']) . 
                        '. Requested: ' . formatCurrency($amount);
                }
            }
        }

        if (empty($errors)) {
            $db->beginTransaction();
            $cashout_number = generateCashOutNumber($db);

            $branch_stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $branch_stmt->execute([$branch_id]);
            $branch_row = $branch_stmt->fetch(PDO::FETCH_ASSOC);
            $branch_name_store = $branch_row['branch_name'] ?? $user_branch_name;

            // INSERT store_cash_out
            $sql = "INSERT INTO store_cash_out (
                        cashout_number, employee_id, branch, branch_id,
                        cashout_date, amount, source, reason, taken_by,
                        description, status, approved_by, approved_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $cashout_number, $user_id, $branch_name_store, $branch_id,
                $cashout_date, $amount, $source, $reason, $taken_by,
                $description, $status, $approved_by, $approved_date
            ]);

            $new_id = $db->lastInsertId();

            // CAPITAL DEDUCTION
            if ($source === 'capital') {
                $capital_number = generateCapitalNumberLocal($db);
                $capital_description = 'Store Cash Out (' . $cashout_number . ')';
                if (!empty($reason)) $capital_description .= ' - ' . $reason;

                $cap_sql = "INSERT INTO capital_management (
                                capital_number, employee_id, branch, branch_id,
                                transaction_date, transaction_type, amount,
                                description, reference_id, reference_module, notes
                            ) VALUES (?, ?, ?, ?, ?, 'cash_out', ?, ?, ?, 'store_cash_out', ?)";
                $cap_stmt = $db->prepare($cap_sql);
                $cap_stmt->execute([
                    $capital_number, $user_id, $branch_name_store, $branch_id,
                    $cashout_date, $amount, $capital_description, $new_id, $description
                ]);

                // Deduct from latest daily report cash
                $latest_dr_stmt = $db->prepare("
                    SELECT id, current_cash FROM daily_reports 
                    WHERE branch_id = ? ORDER BY report_date DESC, id DESC LIMIT 1
                ");
                $latest_dr_stmt->execute([$branch_id]);
                $latest_dr = $latest_dr_stmt->fetch(PDO::FETCH_ASSOC);

                if ($latest_dr) {
                    $new_cash = max(0, floatval($latest_dr['current_cash']) - $amount);
                    $update_stmt = $db->prepare("
                        UPDATE daily_reports 
                        SET current_cash = ?, total_cash_out = COALESCE(total_cash_out, 0) + ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$new_cash, $amount, $latest_dr['id']]);
                }
            }

            // PROFIT DEDUCTION
            if ($source === 'profit') {
                $latest_dr_stmt = $db->prepare("
                    SELECT id, net_profit, net_profit_after_salaries 
                    FROM daily_reports 
                    WHERE branch_id = ? ORDER BY report_date DESC, id DESC LIMIT 1
                ");
                $latest_dr_stmt->execute([$branch_id]);
                $latest_dr = $latest_dr_stmt->fetch(PDO::FETCH_ASSOC);

                if ($latest_dr) {
                    $new_profit = max(0, floatval($latest_dr['net_profit']) - $amount);
                    $new_profit_after = max(0, floatval($latest_dr['net_profit_after_salaries']) - $amount);
                    $update_stmt = $db->prepare("
                        UPDATE daily_reports 
                        SET net_profit = ?, net_profit_after_salaries = ?, 
                            total_cash_out = COALESCE(total_cash_out, 0) + ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$new_profit, $new_profit_after, $amount, $latest_dr['id']]);
                }

                $capital_number = generateCapitalNumberLocal($db);
                $cap_sql = "INSERT INTO capital_management (
                                capital_number, employee_id, branch, branch_id,
                                transaction_date, transaction_type, amount,
                                description, reference_id, reference_module, notes
                            ) VALUES (?, ?, ?, ?, ?, 'cash_out', ?, ?, ?, 'store_cash_out_profit', ?)";
                $cap_stmt = $db->prepare($cap_sql);
                $cap_stmt->execute([
                    $capital_number, $user_id, $branch_name_store, $branch_id,
                    $cashout_date, $amount,
                    'Store Cash Out from Profit (' . $cashout_number . ')',
                    $new_id, $description
                ]);
            }

            if (function_exists('logActivity')) {
                logActivity(
                    $user_id, 'Add Store Cash Out', 'Store Cash Out', $new_id, '',
                    "Added cashout: $cashout_number - " . formatCurrency($amount) . " (Source: " . ucfirst($source) . ")"
                );
            }

            $db->commit();

            $_SESSION['success_message'] = "Cash out record added successfully! ($cashout_number) — Source: " . ucfirst($source);
            header('Location: index.php');
            exit();
        } else {
            $error_message = implode('<br>', $errors);
        }

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Add cashout error: " . $e->getMessage());
        $error_message = 'Failed to add cash out record: ' . htmlspecialchars($e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<style id="cashout-create-scoped">
/* ============================================================
   🔴 RED THEME VARIABLES
   ============================================================ */
.main-wrapper {
    --bg-body: #fef5f5;
    --bg-card: #ffffff;
    --bg-table-even: #fff5f5;
    --bg-table-hover: #fee2e2;
    --bg-input: #fef2f2;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #fecaca;
    --shadow-color: rgba(220, 38, 38, 0.08);
    --shadow-hover: rgba(220, 38, 38, 0.15);
}
html.dark-mode .main-wrapper {
    --bg-body: #1a0a0a;
    --bg-card: #2a1515;
    --bg-table-even: #2a1515;
    --bg-table-hover: #3a1d1d;
    --bg-input: #3a1d1d;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #7f1d1d;
}

.main-wrapper, .main-wrapper *, .main-wrapper *::before, .main-wrapper *::after { box-sizing: border-box; }
.main-wrapper { background: var(--bg-body) !important; overflow-x: hidden !important; max-width: 100% !important; }
.main-wrapper .main-content {
    padding: 16px 20px !important;
    background: var(--bg-body) !important;
    color: var(--text-primary);
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.main-wrapper .branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.35);
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.main-wrapper .branch-indicator::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255,255,255,0.08); border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .branch-indicator-left {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    flex: 1; position: relative; z-index: 1; min-width: 0;
}
.main-wrapper .branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.main-wrapper .branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.main-wrapper .branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; }
.main-wrapper .branch-indicator-name { font-weight: 800; font-size: 16px; }
.main-wrapper .branch-indicator-right { position: relative; z-index: 1; flex-shrink: 0; }
.main-wrapper .date-display {
    font-size: 13px; color: rgba(255,255,255,0.95);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.15);
    border-radius: 16px; display: flex; align-items: center;
    gap: 6px; font-weight: 600;
}

.main-wrapper .page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.main-wrapper .page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0; color: var(--text-primary);
    display: flex; align-items: center; gap: 10px;
}
.main-wrapper .page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted); margin: 6px 0 0 0;
    display: flex; align-items: center; gap: 6px;
}
.main-wrapper .header-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

.main-wrapper .btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.main-wrapper .btn-secondary {
    background: var(--bg-input); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.main-wrapper .btn-secondary:hover { background: var(--bg-card); color: var(--text-primary); }

.main-wrapper .alert {
    padding: 14px 18px; border-radius: 10px; margin-bottom: 16px;
    display: flex; align-items: flex-start; gap: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
    font-size: 13px; font-weight: 500;
}
.main-wrapper .alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.main-wrapper .alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .main-wrapper .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .main-wrapper .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.main-wrapper .alert i { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
.main-wrapper .alert-content { flex: 1; }

.main-wrapper .form-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    max-width: 900px;
    margin: 0 auto;
}
.main-wrapper .form-header {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    padding: 20px 24px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.main-wrapper .form-header::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}
.main-wrapper .form-header-content {
    display: flex; align-items: center; gap: 14px;
    position: relative; z-index: 1;
}
.main-wrapper .form-header-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    flex-shrink: 0;
}
.main-wrapper .form-header-title { flex: 1; min-width: 0; }
.main-wrapper .form-header-title h3 { font-size: 18px; font-weight: 800; margin: 0 0 4px 0; }
.main-wrapper .form-header-title p { font-size: 12px; margin: 0; opacity: 0.9; font-weight: 500; }
.main-wrapper .form-header-badge {
    background: rgba(255,255,255,0.22);
    padding: 6px 14px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
    white-space: nowrap;
    font-family: 'Courier New', monospace;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}

.main-wrapper .form-body { padding: 28px 24px; }
.main-wrapper .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.main-wrapper .form-grid-full { grid-column: 1 / -1; }

.main-wrapper .form-group { display: flex; flex-direction: column; gap: 6px; }
.main-wrapper .form-label {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.main-wrapper .form-label i { color: #DC2626; font-size: 12px; }
.main-wrapper .form-label .required { color: #DC2626; font-size: 14px; margin-left: 2px; }
.main-wrapper .form-label .optional {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: lowercase;
    letter-spacing: 0;
    background: var(--bg-input);
    padding: 1px 8px;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    margin-left: 4px;
    font-style: italic;
}

.main-wrapper .form-control {
    padding: 12px 16px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    width: 100%;
}
.main-wrapper .form-control:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--bg-card);
}
.main-wrapper .form-control::placeholder { color: var(--text-light); font-size: 13px; }

.main-wrapper textarea.form-control {
    resize: vertical;
    min-height: 90px;
    line-height: 1.5;
}

.main-wrapper .form-hint {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.main-wrapper .form-hint i { font-size: 10px; }

.main-wrapper .amount-input-wrapper { position: relative; }
.main-wrapper .amount-input-wrapper .currency-prefix {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    font-weight: 800;
    color: #DC2626;
    pointer-events: none;
    font-family: 'Courier New', monospace;
    z-index: 1;
}
.main-wrapper .amount-input-wrapper .form-control {
    padding-left: 60px;
    font-size: 18px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    text-align: right;
    color: #DC2626;
    background: linear-gradient(135deg, #FEF2F2 0%, #FFFFFF 100%);
    border-color: #FCA5A5;
}
html.dark-mode .main-wrapper .amount-input-wrapper .form-control {
    background: linear-gradient(135deg, #450A0A 0%, #2a1515 100%);
    color: #FCA5A5;
    border-color: #DC2626;
}
.main-wrapper .amount-input-wrapper .form-control:focus {
    background: #FFFFFF;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.2);
}
html.dark-mode .main-wrapper .amount-input-wrapper .form-control:focus { background: #2a1515; }
.main-wrapper .amount-helper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 6px;
    flex-wrap: wrap;
    gap: 6px;
}
.main-wrapper .amount-in-words {
    font-size: 11px;
    font-weight: 700;
    color: #DC2626;
    font-style: italic;
    text-transform: capitalize;
    min-height: 16px;
}
html.dark-mode .main-wrapper .amount-in-words { color: #FCA5A5; }
.main-wrapper .amount-format-hint {
    font-size: 10px;
    color: var(--text-muted);
    font-weight: 600;
    background: var(--bg-input);
    padding: 2px 8px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
}

/* SOURCE SELECTOR */
.main-wrapper .source-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 4px;
}
.main-wrapper .source-option {
    position: relative;
    cursor: pointer;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    padding: 16px 18px;
    background: var(--bg-input);
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.main-wrapper .source-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.main-wrapper .source-option:hover {
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}
.main-wrapper .source-option.selected {
    border-color: #DC2626;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.25);
}
html.dark-mode .main-wrapper .source-option.selected {
    background: linear-gradient(135deg, #450A0A 0%, #7F1D1D 100%);
}
.main-wrapper .source-option.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}
.main-wrapper .source-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.main-wrapper .source-option[data-source="capital"] .source-icon {
    background: #DBEAFE;
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}
.main-wrapper .source-option[data-source="profit"] .source-icon {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #86EFAC;
}
html.dark-mode .main-wrapper .source-option[data-source="capital"] .source-icon {
    background: #1E3A5F; color: #60A5FA; border-color: #3B82F6;
}
html.dark-mode .main-wrapper .source-option[data-source="profit"] .source-icon {
    background: #14532D; color: #4ADE80; border-color: #16A34A;
}
.main-wrapper .source-info {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 2px;
}
.main-wrapper .source-title {
    font-size: 14px; font-weight: 800;
    color: var(--text-primary);
    display: flex; align-items: center; gap: 6px;
}
.main-wrapper .source-title .check-icon {
    color: #DC2626;
    font-size: 14px;
    opacity: 0;
    transition: opacity 0.2s ease;
}
.main-wrapper .source-option.selected .source-title .check-icon { opacity: 1; }
.main-wrapper .source-desc {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 600;
}
.main-wrapper .source-balance {
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    padding: 3px 10px;
    border-radius: 6px;
    white-space: nowrap;
    margin-top: 4px;
    align-self: flex-start;
}
.main-wrapper .source-balance.capital {
    background: #DBEAFE;
    color: #1D4ED8;
    border: 1px solid #93C5FD;
}
.main-wrapper .source-balance.profit {
    background: #DCFCE7;
    color: #15803D;
    border: 1px solid #86EFAC;
}
html.dark-mode .main-wrapper .source-balance.capital { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .main-wrapper .source-balance.profit { background: #14532D; color: #4ADE80; }

.main-wrapper .warning-box {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1.5px solid #FCD34D;
    border-radius: 10px;
    margin-top: 8px;
    font-size: 12px;
    color: #B45309;
    font-weight: 600;
    line-height: 1.5;
}
html.dark-mode .main-wrapper .warning-box {
    background: linear-gradient(135deg, #5F3A1E 0%, #7F1D1D 100%);
    border-color: #D97706;
    color: #FBBF24;
}
.main-wrapper .warning-box i {
    color: #D97706;
    font-size: 18px;
    flex-shrink: 0;
    margin-top: 1px;
}

.main-wrapper .form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding-top: 20px;
    border-top: 1.5px solid var(--border-color);
    margin-top: 8px;
    flex-wrap: wrap;
}
.main-wrapper .btn-submit {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    color: #FFFFFF;
    padding: 14px 32px;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.main-wrapper .btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(220, 38, 38, 0.5);
}
.main-wrapper .btn-submit:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}
.main-wrapper .btn-cancel {
    background: var(--bg-input);
    color: var(--text-secondary);
    padding: 14px 28px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    text-decoration: none;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-family: 'Inter', sans-serif;
}
.main-wrapper .btn-cancel:hover {
    background: var(--bg-card);
    color: var(--text-primary);
    border-color: var(--text-muted);
}

.main-wrapper .admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    color: #FFFFFF;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}
.main-wrapper .admin-badge i { color: #FCD34D; font-size: 12px; }

@media (max-width: 768px) {
    .main-wrapper .main-content { padding: 12px !important; }
    .main-wrapper .branch-indicator { flex-direction: column; align-items: flex-start; }
    .main-wrapper .page-header { flex-direction: column; align-items: flex-start; }
    .main-wrapper .header-right { width: 100%; }
    .main-wrapper .form-grid { grid-template-columns: 1fr; gap: 16px; }
    .main-wrapper .form-body { padding: 20px 16px; }
    .main-wrapper .form-header { padding: 16px 18px; }
    .main-wrapper .source-selector { grid-template-columns: 1fr; }
    .main-wrapper .form-actions { flex-direction: column-reverse; }
    .main-wrapper .form-actions .btn-submit,
    .main-wrapper .form-actions .btn-cancel { width: 100%; justify-content: center; }
}
</style>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-plus-circle"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Add Cash Out</span>
                    <span class="branch-indicator-name" id="headerBranchName"><?php echo htmlspecialchars($user_branch_name); ?></span>
                </div>
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#DC2626;"></i> Add Store Cash Out</h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i>
                    Choose source (Capital or Profit) and enter details
                </p>
            </div>
            <div class="header-right">
                <span class="admin-badge">
                    <i class="fas fa-crown"></i>
                    Admin Only
                </span>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="alert-content">
                    <strong>Cannot proceed:</strong><br>
                    <?php echo $error_message; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <div class="form-header">
                <div class="form-header-content">
                    <div class="form-header-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="form-header-title">
                        <h3>New Cash Out Record</h3>
                        <p>Select source and enter the cash out details</p>
                    </div>
                    <div class="form-header-badge">
                        <i class="fas fa-hashtag"></i>
                        <?php echo htmlspecialchars($cashout_number); ?>
                    </div>
                </div>
            </div>

            <div class="form-body">
                <form method="POST" action="" id="cashoutForm">
                    
                    <div class="form-grid">
                        
                        <!-- Cashout Date -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-day"></i>
                                Cash Out Date <span class="required">*</span>
                            </label>
                            <input type="date" 
                                   name="cashout_date" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['cashout_date'] ?? date('Y-m-d')); ?>"
                                   max="<?php echo date('Y-m-d'); ?>"
                                   required>
                        </div>
                        
                        <!-- Branch -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-store-alt"></i>
                                Branch <span class="required">*</span>
                            </label>
                            <select name="branch_id" id="branchSelect" class="form-control" required>
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($branches as $b): 
                                    $bal = $branch_balances[$b['id']] ?? ['capital' => 0, 'profit' => 0];
                                ?>
                                    <option value="<?php echo $b['id']; ?>" 
                                        data-capital="<?php echo $bal['capital']; ?>"
                                        data-profit="<?php echo $bal['profit']; ?>"
                                        data-branch-name="<?php echo htmlspecialchars($b['branch_name']); ?>"
                                        <?php echo (isset($_POST['branch_id']) && $_POST['branch_id'] == $b['id']) ? 'selected' : ''; ?>
                                        <?php echo ($user_branch_id == $b['id'] && !isset($_POST['branch_id'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                        <?php if (!empty($b['branch_code'])): ?>
                                            (<?php echo htmlspecialchars($b['branch_code']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- SOURCE SELECTOR -->
                        <div class="form-group form-grid-full">
                            <label class="form-label">
                                <i class="fas fa-hand-holding-usd"></i>
                                Cash Out Source <span class="required">*</span>
                            </label>
                            
                            <div class="source-selector" id="sourceSelector">
                                
                                <!-- Capital Option -->
                                <label class="source-option" data-source="capital" id="capitalOption">
                                    <input type="radio" name="source" value="capital" 
                                           <?php echo (isset($_POST['source']) && $_POST['source'] == 'capital') ? 'checked' : ''; ?>>
                                    <div class="source-icon">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div class="source-info">
                                        <span class="source-title">
                                            <i class="fas fa-check-circle check-icon"></i>
                                            Capital
                                        </span>
                                        <span class="source-desc">Deduct from Available Cash</span>
                                        <span class="source-balance capital">
                                            Available: <span id="capitalAmount">—</span>
                                        </span>
                                    </div>
                                </label>
                                
                                <!-- Profit Option -->
                                <label class="source-option" data-source="profit" id="profitOption">
                                    <input type="radio" name="source" value="profit" 
                                           <?php echo (!isset($_POST['source']) || $_POST['source'] == 'profit') ? 'checked' : ''; ?>>
                                    <div class="source-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <div class="source-info">
                                        <span class="source-title">
                                            <i class="fas fa-check-circle check-icon"></i>
                                            Profit
                                        </span>
                                        <span class="source-desc">Deduct from Business Profit</span>
                                        <span class="source-balance profit">
                                            Available: <span id="profitAmount">—</span>
                                        </span>
                                    </div>
                                </label>
                                
                            </div>
                            
                            <div class="form-hint" style="margin-top: 8px;">
                                <i class="fas fa-info-circle"></i>
                                Choose <strong>Capital</strong> to deduct from cash balance, or <strong>Profit</strong> to deduct from business profit
                            </div>
                        </div>
                        
                        <!-- Amount -->
                        <div class="form-group form-grid-full">
                            <label class="form-label">
                                <i class="fas fa-money-bill-wave"></i>
                                Amount <span class="required">*</span>
                            </label>
                            <div class="amount-input-wrapper">
                                <span class="currency-prefix">TSh</span>
                                <input type="text" 
                                       name="amount" 
                                       id="amountInput"
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>"
                                       placeholder="0"
                                       inputmode="numeric"
                                       autocomplete="off"
                                       required>
                            </div>
                            <div class="amount-helper">
                                <span class="amount-in-words" id="amountInWords"></span>
                                <span class="amount-format-hint">
                                    <i class="fas fa-info-circle"></i> 1,000,000
                                </span>
                            </div>
                            
                            <div class="warning-box" id="balanceWarning" style="display: none;">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span id="warningMessage">Insufficient balance for this source.</span>
                            </div>
                        </div>
                        
                        <!-- Taken By -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user"></i>
                                Taken By <span class="optional">optional</span>
                            </label>
                            <input type="text" 
                                   name="taken_by" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['taken_by'] ?? ''); ?>"
                                   placeholder="Name of person who took the cash"
                                   maxlength="100">
                        </div>
                        
                        <!-- Reason -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-comment-alt"></i>
                                Reason <span class="optional">optional</span>
                            </label>
                            <input type="text" 
                                   name="reason" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['reason'] ?? ''); ?>"
                                   placeholder="e.g., Office supplies, transport..."
                                   maxlength="200">
                        </div>
                        
                        <!-- Notes -->
                        <div class="form-group form-grid-full">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i>
                                Notes <span class="optional">optional</span>
                            </label>
                            <textarea name="description" 
                                      class="form-control" 
                                      placeholder="Additional details about this cash out..."
                                      maxlength="1000"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                        
                    </div>

                    <div class="form-actions">
                        <a href="index.php" class="btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="fas fa-save"></i> Save Cash Out
                        </button>
                    </div>
                    
                </form>
            </div>
        </div>
    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<script>
// ============================================================
// 💰 NUMBER TO WORDS
// ============================================================
function numberToWords(num) {
    if (num === 0) return 'sifuri';
    const ones = ['', 'moja', 'mbili', 'tatu', 'nne', 'tano', 'sita', 'saba', 'nane', 'tisa'];
    const tens = ['', '', 'ishirini', 'thelathini', 'arobaini', 'hamsini', 'sitini', 'sabini', 'themanini', 'tisini'];
    const scales = ['', 'elfu', 'milioni', 'bilioni', 'trilioni'];
    function convertHundreds(n) {
        let result = '';
        if (n >= 100) { result += ones[Math.floor(n / 100)] + ' mia '; n %= 100; }
        if (n >= 20) { result += tens[Math.floor(n / 10)] + ' '; n %= 10; }
        if (n > 0) { result += ones[n] + ' '; }
        return result;
    }
    function convert(n) {
        if (n === 0) return '';
        let result = '', scaleIndex = 0;
        while (n > 0) {
            const chunk = n % 1000;
            if (chunk > 0) {
                let chunkWords = convertHundreds(chunk);
                if (scales[scaleIndex]) chunkWords += ' ' + scales[scaleIndex];
                result = chunkWords + (result ? ' na ' + result : '');
            }
            n = Math.floor(n / 1000);
            scaleIndex++;
        }
        return result.trim();
    }
    return convert(Math.floor(num)) + ' tu';
}

function formatNumberWithCommas(value) {
    let num = value.replace(/[^0-9]/g, '');
    if (num === '') return '';
    return num.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function parseFormattedNumber(value) {
    return value.replace(/,/g, '');
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amountInput');
    const amountInWords = document.getElementById('amountInWords');
    const branchSelect = document.getElementById('branchSelect');
    const sourceOptions = document.querySelectorAll('.source-option');
    const capitalOption = document.getElementById('capitalOption');
    const profitOption = document.getElementById('profitOption');
    const capitalAmount = document.getElementById('capitalAmount');
    const profitAmount = document.getElementById('profitAmount');
    const balanceWarning = document.getElementById('balanceWarning');
    const warningMessage = document.getElementById('warningMessage');
    const submitBtn = document.getElementById('submitBtn');
    const headerBranchName = document.getElementById('headerBranchName');
    
    let currentCapital = 0;
    let currentProfit = 0;
    let currentSource = 'profit';
    
    // ============================================================
    // UPDATE BALANCES + AUTO-SELECT SOURCE
    // ============================================================
    function updateBalances() {
        const selectedOption = branchSelect.options[branchSelect.selectedIndex];
        if (!selectedOption || !selectedOption.value) return;
        
        currentCapital = parseFloat(selectedOption.dataset.capital || 0);
        currentProfit = parseFloat(selectedOption.dataset.profit || 0);
        const branchName = selectedOption.dataset.branchName || '';
        
        // Update header branch name
        if (headerBranchName && branchName) {
            headerBranchName.textContent = branchName;
        }
        
        // Update balance displays
        capitalAmount.textContent = formatNumberWithCommas(Math.floor(currentCapital).toString()) + ' TSh';
        profitAmount.textContent = formatNumberWithCommas(Math.floor(currentProfit).toString()) + ' TSh';
        
        // ✅ AUTO-SELECT: Kama Capital ina cash, ichague; vinginevyo chagua Profit
        autoSelectSource();
        
        validateAmount();
    }
    
    function autoSelectSource() {
        // ✅ Kama branch ina cash → chagua Capital
        // ✅ Kama haina cash lakini ina profit → chagua Profit
        // ✅ Kama haina vyote → chagua Capital (default)
        
        let newSource = 'capital'; // default
        
        if (currentCapital > 0) {
            newSource = 'capital';
        } else if (currentProfit > 0) {
            newSource = 'profit';
        } else {
            newSource = 'capital';
        }
        
        // Set selection
        sourceOptions.forEach(function(o) { o.classList.remove('selected'); });
        const targetOption = newSource === 'capital' ? capitalOption : profitOption;
        if (targetOption) {
            targetOption.classList.add('selected');
            targetOption.querySelector('input[type="radio"]').checked = true;
        }
        currentSource = newSource;
    }
    
    function validateAmount() {
        const rawValue = parseFormattedNumber(amountInput.value);
        const amount = parseFloat(rawValue) || 0;
        
        let available = currentSource === 'capital' ? currentCapital : currentProfit;
        let sourceName = currentSource === 'capital' ? 'Capital' : 'Profit';
        
        if (amount > 0 && amount > available) {
            warningMessage.innerHTML = 'Insufficient <strong>' + sourceName + '</strong>! Available: <strong>' + 
                formatNumberWithCommas(Math.floor(available).toString()) + ' TSh</strong>, Requested: <strong>' + 
                formatNumberWithCommas(Math.floor(amount).toString()) + ' TSh</strong>';
            balanceWarning.style.display = 'flex';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
            return false;
        } else {
            balanceWarning.style.display = 'none';
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            return true;
        }
    }
    
    // ============================================================
    // SOURCE SELECTOR (Manual click)
    // ============================================================
    sourceOptions.forEach(function(opt) {
        opt.addEventListener('click', function() {
            sourceOptions.forEach(function(o) { o.classList.remove('selected'); });
            this.classList.add('selected');
            this.querySelector('input[type="radio"]').checked = true;
            currentSource = this.dataset.source;
            validateAmount();
        });
    });
    
    // ============================================================
    // BRANCH CHANGE
    // ============================================================
    if (branchSelect) {
        branchSelect.addEventListener('change', updateBalances);
    }
    
    // ============================================================
    // AMOUNT FORMAT
    // ============================================================
    if (amountInput) {
        if (amountInput.value) {
            const raw = parseFormattedNumber(amountInput.value);
            if (raw && !isNaN(raw)) {
                amountInput.value = formatNumberWithCommas(raw);
                updateAmountInWords(raw);
            }
        }
        
        amountInput.addEventListener('input', function() {
            const cursorPos = this.selectionStart;
            const oldValue = this.value;
            const raw = parseFormattedNumber(this.value);
            const formatted = formatNumberWithCommas(raw);
            this.value = formatted;
            const newPos = cursorPos + (formatted.length - oldValue.length);
            this.setSelectionRange(newPos, newPos);
            updateAmountInWords(raw);
            validateAmount();
        });
    }
    
    function updateAmountInWords(raw) {
        if (raw && !isNaN(raw) && parseFloat(raw) > 0) {
            amountInWords.textContent = numberToWords(parseFloat(raw));
        } else {
            amountInWords.textContent = '';
        }
    }
    
    // ============================================================
    // FORM SUBMIT
    // ============================================================
    const form = document.getElementById('cashoutForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const rawValue = parseFormattedNumber(amountInput.value);
            const amount = parseFloat(rawValue);
            
            if (isNaN(amount) || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount greater than 0.');
                amountInput.focus();
                return false;
            }
            
            if (!validateAmount()) {
                e.preventDefault();
                alert('Cannot proceed: Insufficient balance.');
                return false;
            }
            
            amountInput.value = rawValue;
            
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
            return true;
        });
    }
    
    // Initialize
    updateBalances();
    syncDarkMode();
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
});
</script>

</body>
</html>