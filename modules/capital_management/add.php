<?php
// ================================================================
// FILE: modules/capital_management/add.php
// WAKALA FINANCIAL SYSTEM - ADD CAPITAL TRANSACTION
// ✅ GREEN THEME + BEAUTIFUL CARDS (matching na view.php)
// ✅ Cash on TOP, Providers on BOTTOM
// ✅ 3 providers per row
// ✅ FIXED: Cash Out INAKATAA kama hakuna daily_report
// ✅ FIXED: Validation ya current cash/float
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// ✅ HELPER: Pata current capital kwa branch
// ============================================================
function getCurrentCapitalForBranch($db, $branch_id) {
    $result = ['cash' => 0, 'float' => 0, 'capital' => 0, 'source' => 'none', 'has_daily_report' => false];
    
    // STEP 1: Jaribu daily_reports kwanza
    $stmt = $db->prepare("
        SELECT id, current_cash, current_capital, current_float
        FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dr) {
        $cash = floatval($dr['current_cash'] ?? 0);
        $capital = floatval($dr['current_capital'] ?? 0);
        $float = floatval($dr['current_float'] ?? 0);
        
        if ($cash > 0 || $float > 0 || $capital > 0) {
            $result['cash'] = $cash;
            $result['float'] = $float;
            $result['capital'] = $capital;
            $result['source'] = 'daily_reports';
            $result['has_daily_report'] = true;
            return $result;
        }
    }
    
    // STEP 2: Fallback - soma kutoka capital_management
    $stmt = $db->prepare("
        SELECT 
            cm.reference_module,
            cm.reference_id,
            cm.amount,
            cm.transaction_type
        FROM capital_management cm
        WHERE cm.branch_id = ?
          AND cm.id IN (
              SELECT MAX(id) FROM capital_management 
              WHERE branch_id = ? 
                AND reference_module = cm.reference_module
                AND (reference_id = cm.reference_id OR (reference_id IS NULL AND cm.reference_id IS NULL))
              GROUP BY reference_module, reference_id
          )
    ");
    $stmt->execute([$branch_id, $branch_id]);
    $latest_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_cash = 0;
    $total_float = 0;
    
    foreach ($latest_records as $rec) {
        $amount = floatval($rec['amount']);
        $is_outgoing = in_array($rec['transaction_type'], ['cash_out', 'adjustment']);
        
        if ($rec['reference_module'] === 'provider') {
            $total_float += $is_outgoing ? -$amount : $amount;
        } else {
            $total_cash += $is_outgoing ? -$amount : $amount;
        }
    }
    
    $result['cash'] = max(0, $total_cash);
    $result['float'] = max(0, $total_float);
    $result['capital'] = $result['cash'] + $result['float'];
    $result['source'] = 'capital_management';
    $result['has_daily_report'] = false;
    
    return $result;
}

/**
 * Pata current float ya kila provider kwa branch
 */
function getProviderFloats($db, $branch_id) {
    $floats = [];
    
    // STEP 1: Jaribu daily_report_providers
    $stmt = $db->prepare("
        SELECT drp.provider_id, drp.current_float
        FROM daily_report_providers drp
        INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
        WHERE dr.branch_id = ?
          AND dr.id = (SELECT MAX(id) FROM daily_reports WHERE branch_id = ?)
    ");
    $stmt->execute([$branch_id, $branch_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $floats[intval($row['provider_id'])] = floatval($row['current_float']);
    }
    
    if (!empty($floats)) {
        return $floats;
    }
    
    // STEP 2: Fallback - soma kutoka capital_management
    $stmt = $db->prepare("
        SELECT 
            bp.provider_id,
            cm.amount,
            cm.transaction_type,
            cm.id
        FROM capital_management cm
        INNER JOIN branch_providers bp ON cm.reference_id = bp.id
        WHERE cm.branch_id = ?
          AND cm.reference_module = 'provider'
          AND cm.id IN (
              SELECT MAX(id) FROM capital_management 
              WHERE branch_id = ? AND reference_module = 'provider'
              GROUP BY reference_id
          )
    ");
    $stmt->execute([$branch_id, $branch_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = intval($row['provider_id']);
        $amt = floatval($row['amount']);
        $is_out = in_array($row['transaction_type'], ['cash_out', 'adjustment']);
        $floats[$pid] = max(0, $is_out ? -$amt : $amt);
    }
    
    return $floats;
}

/**
 * ✅ Check kama branch ina daily_report
 */
function hasDailyReport($db, $branch_id) {
    $stmt = $db->prepare("
        SELECT id FROM daily_reports 
        WHERE branch_id = ? 
          AND (current_cash > 0 OR current_capital > 0 OR current_float > 0)
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    return $stmt->fetch() !== false;
}

// ============================================================
// GET BRANCH FROM URL
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
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
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
    try {
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $transaction_type = $_POST['transaction_type'] ?? 'additional';
        $description = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        $cash_amount = floatval(str_replace(',', '', $_POST['cash_amount'] ?? 0));
        $provider_amounts = $_POST['provider_amounts'] ?? [];
        
        if ($branch_id <= 0) throw new Exception('Please select a branch.');
        if (!in_array($transaction_type, ['opening', 'additional', 'profit_allocation', 'cash_out', 'adjustment'])) {
            throw new Exception('Invalid transaction type.');
        }
        
        $has_cash = $cash_amount > 0;
        $has_providers = false;
        $total_provider = 0;
        foreach ($provider_amounts as $pid => $amt) {
            $a = floatval(str_replace(',', '', $amt));
            if ($a > 0) {
                $has_providers = true;
                $total_provider += $a;
            }
        }
        
        if (!$has_cash && !$has_providers) {
            throw new Exception('Please enter at least one amount (Cash or Provider).');
        }
        
        $branch_name = '';
        foreach ($all_branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        $is_out = in_array($transaction_type, ['cash_out', 'adjustment']);
        
        // ============================================================
        // ✅ VALIDATION KWA CASH OUT / ADJUSTMENT
        // ============================================================
        if ($is_out) {
            $current = getCurrentCapitalForBranch($db, $branch_id);
            $has_dr = hasDailyReport($db, $branch_id);
            
            if ($has_cash && $cash_amount > 0) {
                if (!$has_dr && $current['cash'] <= 0) {
                    throw new Exception(
                        'Huwezi kufanya Cash Out bila Daily Report. ' .
                        'Tafadhali tengeneza Opening Capital kwanza.'
                    );
                }
                
                if ($cash_amount > $current['cash']) {
                    throw new Exception(
                        'Cash haitoshi. ' .
                        'Iliyopo: ' . formatCurrency($current['cash']) . ', ' .
                        'Unaomba: ' . formatCurrency($cash_amount) . '.'
                    );
                }
            }
            
            if ($has_providers) {
                $provider_floats = getProviderFloats($db, $branch_id);
                
                foreach ($provider_amounts as $pid => $amt_raw) {
                    $pid = intval($pid);
                    $amt = floatval(str_replace(',', '', $amt_raw));
                    
                    if ($amt <= 0) continue;
                    
                    $available = $provider_floats[$pid] ?? 0;
                    
                    $stmt = $db->prepare("SELECT provider_name FROM providers WHERE id = ?");
                    $stmt->execute([$pid]);
                    $pname = $stmt->fetchColumn() ?: 'Unknown';
                    
                    if (!$has_dr && $available <= 0) {
                        throw new Exception(
                            'Huwezi kufanya Cash Out kwa ' . $pname . ' bila Daily Report. ' .
                            'Tafadhali tengeneza Opening Capital kwanza.'
                        );
                    }
                    
                    if ($amt > $available) {
                        throw new Exception(
                            'Float haitoshi kwa ' . $pname . '. ' .
                            'Iliyopo: ' . formatCurrency($available) . ', ' .
                            'Unaomba: ' . formatCurrency($amt) . '.'
                        );
                    }
                }
            }
            
            $total_out = $cash_amount;
            foreach ($provider_amounts as $amt_raw) {
                $total_out += floatval(str_replace(',', '', $amt_raw));
            }
            
            if ($total_out > $current['capital']) {
                throw new Exception(
                    'Jumla inazidi Capital iliyopo. ' .
                    'Iliyopo: ' . formatCurrency($current['capital']) . ', ' .
                    'Unaomba: ' . formatCurrency($total_out) . '.'
                );
            }
        }
        
        $db->beginTransaction();
        
        // Get latest daily report (kama ipo)
        $stmt = $db->prepare("
            SELECT id, current_cash, current_capital 
            FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        $dr_id = $latest_dr ? $latest_dr['id'] : null;
        
        // Kama hakuna daily_report NA ni INCOMING → tengeneza
        if (!$dr_id && !$is_out) {
            $report_number = 'DR-' . date('Ymd') . '-' . mt_rand(1000, 9999);
            $stmt = $db->prepare("
                INSERT INTO daily_reports 
                (report_number, employee_id, branch, branch_id, report_date,
                 current_cash, current_float, current_capital, created_at)
                VALUES (?, ?, ?, ?, ?, 0, 0, 0, NOW())
            ");
            $stmt->execute([
                $report_number, $user_id, $branch_name, $branch_id, $transaction_date
            ]);
            $dr_id = $db->lastInsertId();
            $latest_dr = ['id' => $dr_id, 'current_cash' => 0, 'current_capital' => 0];
        }
        
        $total_added = 0;
        $transactions_created = [];
        
        // ====================================================
        // 1. PROCESS CASH
        // ====================================================
        if ($has_cash) {
            $capital_number = 'CAP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("
                INSERT INTO capital_management 
                (capital_number, branch_id, employee_id, transaction_date, transaction_type,
                 reference_module, reference_id, amount, description, notes, created_at)
                VALUES (?, ?, ?, ?, ?, 'cash', NULL, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $capital_number, $branch_id, $user_id, $transaction_date, $transaction_type,
                $cash_amount, $description ?: 'Cash transaction', $notes
            ]);
            $transactions_created[] = $capital_number;
            
            if ($dr_id) {
                $old_cash = floatval($latest_dr['current_cash']);
                $old_capital = floatval($latest_dr['current_capital']);
                
                if ($is_out) {
                    $new_cash = max(0, $old_cash - $cash_amount);
                    $new_capital = max(0, $old_capital - $cash_amount);
                } else {
                    $new_cash = $old_cash + $cash_amount;
                    $new_capital = $old_capital + $cash_amount;
                }
                
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET current_cash = ?, current_capital = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_cash, $new_capital, $dr_id]);
                
                $latest_dr['current_cash'] = $new_cash;
                $latest_dr['current_capital'] = $new_capital;
            }
            
            $total_added += $cash_amount;
        }
        
        // ====================================================
        // 2. PROCESS PROVIDERS
        // ====================================================
        if ($has_providers) {
            foreach ($provider_amounts as $provider_id => $amount_raw) {
                $provider_id = intval($provider_id);
                $amount = floatval(str_replace(',', '', $amount_raw));
                
                if ($amount <= 0 || $provider_id <= 0) continue;
                
                $capital_number = 'CAP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $stmt = $db->prepare("
                    INSERT INTO capital_management 
                    (capital_number, branch_id, employee_id, transaction_date, transaction_type,
                     reference_module, reference_id, amount, description, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, 'provider', ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $capital_number, $branch_id, $user_id, $transaction_date, $transaction_type,
                    $provider_id, $amount, $description ?: 'Provider float transaction', $notes
                ]);
                $transactions_created[] = $capital_number;
                
                if ($dr_id) {
                    $stmt = $db->prepare("
                        SELECT id, current_float 
                        FROM daily_report_providers 
                        WHERE daily_report_id = ? AND provider_id = ?
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmt->execute([$dr_id, $provider_id]);
                    $drp = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($drp) {
                        $old_float = floatval($drp['current_float']);
                        $new_float = $is_out ? max(0, $old_float - $amount) : $old_float + $amount;
                        
                        $stmt = $db->prepare("
                            UPDATE daily_report_providers 
                            SET current_float = ?, updated_at = NOW() 
                            WHERE id = ?
                        ");
                        $stmt->execute([$new_float, $drp['id']]);
                    } else {
                        $stmt = $db->prepare("
                            SELECT p.provider_name, bp.provider_code 
                            FROM providers p
                            INNER JOIN branch_providers bp ON p.id = bp.provider_id AND bp.branch_id = ?
                            WHERE p.id = ?
                        ");
                        $stmt->execute([$branch_id, $provider_id]);
                        $pinfo = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($pinfo) {
                            $new_float = $is_out ? 0 : $amount;
                            $stmt = $db->prepare("
                                INSERT INTO daily_report_providers 
                                (daily_report_id, provider_id, provider_code, provider_name,
                                 morning_float, morning_cash, current_float, current_cash,
                                 total_deposits, total_withdrawals, created_at)
                                VALUES (?, ?, ?, ?, 0, 0, ?, 0, 0, 0, NOW())
                            ");
                            $stmt->execute([
                                $dr_id, $provider_id,
                                $pinfo['provider_code'], $pinfo['provider_name'],
                                $new_float
                            ]);
                        }
                    }
                    
                    $stmt = $db->prepare("SELECT current_capital, current_float FROM daily_reports WHERE id = ?");
                    $stmt->execute([$dr_id]);
                    $cap_row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $current_cap = floatval($cap_row['current_capital']);
                    $current_float_dr = floatval($cap_row['current_float'] ?? 0);
                    
                    $new_capital = $is_out ? max(0, $current_cap - $amount) : $current_cap + $amount;
                    $new_float_dr = $is_out ? max(0, $current_float_dr - $amount) : $current_float_dr + $amount;
                    
                    $stmt = $db->prepare("
                        UPDATE daily_reports 
                        SET current_capital = ?, current_float = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$new_capital, $new_float_dr, $dr_id]);
                    
                    $latest_dr['current_capital'] = $new_capital;
                }
                
                $total_added += $amount;
            }
        }
        
        logActivity(
            $user_id,
            'Add Capital Transaction',
            'Capital Management',
            0,
            '',
            'Added ' . $transaction_type . ': Total ' . formatCurrency($total_added) . 
            ' at ' . $branch_name . ' (' . count($transactions_created) . ' records)'
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = 'Capital transaction of ' . formatCurrency($total_added) . 
                                       ' added successfully! (' . count($transactions_created) . ' records)';
        header('Location: index.php?branch=' . $branch_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// ============================================================
// GET CURRENT CAPITAL
// ============================================================
$current_cash = 0;
$current_float = 0;
$current_capital = 0;
$has_daily_report = false;

if ($selected_branch > 0) {
    $data = getCurrentCapitalForBranch($db, $selected_branch);
    $current_cash = $data['cash'];
    $current_float = $data['float'];
    $current_capital = $data['capital'];
    $has_daily_report = $data['has_daily_report'];
}

// ============================================================
// GET PROVIDERS WITH THEIR CURRENT FLOATS
// ============================================================
$all_providers = [];
if ($selected_branch > 0) {
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.provider_name,
            p.provider_code as main_code,
            p.icon_class,
            p.color_code,
            p.provider_type,
            bp.provider_code as branch_provider_code
        FROM providers p
        INNER JOIN branch_providers bp ON p.id = bp.provider_id
        WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
        ORDER BY p.display_order, p.provider_name
    ");
    $stmt->execute([$selected_branch]);
    $all_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $provider_floats = getProviderFloats($db, $selected_branch);
    
    foreach ($all_providers as &$p) {
        $pid = intval($p['id']);
        $p['current_float'] = $provider_floats[$pid] ?? 0;
    }
    unset($p);
}

// Type labels
$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'green', 'desc' => 'Initial capital', 'gradient' => 'linear-gradient(135deg, #059669, #047857)'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green', 'desc' => 'More capital', 'gradient' => 'linear-gradient(135deg, #10B981, #059669)'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple', 'desc' => 'Profit to capital', 'gradient' => 'linear-gradient(135deg, #7C3AED, #6D28D9)'],
    'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red', 'desc' => 'Reduce capital', 'gradient' => 'linear-gradient(135deg, #DC2626, #B91C1C)'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange', 'desc' => 'Correction', 'gradient' => 'linear-gradient(135deg, #F59E0B, #D97706)']
];

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
        
        <!-- GREEN BRANCH CARD -->
        <div class="branch-status-card <?php echo $selected_branch > 0 ? 'branch-selected' : 'branch-all'; ?>">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Adding Transaction For' : 'Select Branch Below'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($selected_branch_name); ?></span>
                <?php if ($selected_branch > 0 && $selected_branch_code): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($selected_branch_code); ?></span>
                <?php endif; ?>
            </div>
            <a href="index.php<?php echo $selected_branch > 0 ? '?branch=' . $selected_branch : ''; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Capital Transaction</h2>
                <p class="text-muted">Jaza Cash na Providers kwa pamoja</p>
            </div>
        </div>

        <!-- ALERTS -->
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

        <!-- NO BRANCH WARNING -->
        <?php if ($selected_branch == 0): ?>
            <div class="no-branch-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Please select a branch first</strong>
                    <p>Capital transactions must be applied to a specific branch. Select a branch from the list below.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- ✅ NO DAILY REPORT WARNING -->
        <?php if ($selected_branch > 0 && !$has_daily_report && $current_capital <= 0): ?>
            <div class="no-daily-report-warning">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Hakuna Opening Capital kwa branch hii</strong>
                    <p>
                        Unaweza kuongeza <strong>Opening Capital</strong>, <strong>Additional Capital</strong>, 
                        au <strong>Profit Allocation</strong>. 
                        Lakini <strong>Cash Out haitaruhusiwa</strong> mpaka uwe na capital ya kutosha.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- ✅ SUMMARY CARDS - ZENYE DESIGN NZURI -->
        <?php if ($selected_branch > 0): ?>
        <div class="summary-cards-row">
            <div class="summary-mini-card mini-cash">
                <div class="smc-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="smc-content">
                    <span class="smc-label">Current Cash</span>
                    <span class="smc-value"><?php echo formatCurrency($current_cash); ?></span>
                </div>
            </div>
            
            <div class="summary-mini-card mini-float">
                <div class="smc-icon"><i class="fas fa-university"></i></div>
                <div class="smc-content">
                    <span class="smc-label">Current Float</span>
                    <span class="smc-value"><?php echo formatCurrency($current_float); ?></span>
                </div>
            </div>
            
            <div class="summary-mini-card mini-capital">
                <div class="smc-icon"><i class="fas fa-vault"></i></div>
                <div class="smc-content">
                    <span class="smc-label">Total Capital</span>
                    <span class="smc-value"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- MAIN FORM -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="capitalForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_transaction">
                
                <!-- BRANCH SELECTION -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-store-alt"></i> Select Branch</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <?php if ($selected_branch > 0): ?>
                        <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                        <div class="selected-branch-locked">
                            <div class="sbl-icon"><i class="fas fa-store-alt"></i></div>
                            <div class="sbl-info">
                                <span class="sbl-name"><?php echo htmlspecialchars($selected_branch_name); ?></span>
                                <?php if ($selected_branch_code): ?>
                                    <span class="sbl-code"><?php echo htmlspecialchars($selected_branch_code); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="add.php" class="sbl-change">
                                <i class="fas fa-exchange-alt"></i> Change
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="branch-selection-grid">
                            <?php foreach ($all_branches as $b): ?>
                                <label class="branch-option">
                                    <input type="radio" name="branch_id" value="<?php echo $b['id']; ?>" required onchange="onBranchChange(<?php echo $b['id']; ?>)">
                                    <div class="bo-content">
                                        <div class="bo-icon"><i class="fas fa-store-alt"></i></div>
                                        <div class="bo-info">
                                            <span class="bo-name"><?php echo htmlspecialchars($b['branch_name']); ?></span>
                                            <?php if (!empty($b['branch_code'])): ?>
                                                <span class="bo-code"><?php echo htmlspecialchars($b['branch_code']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="bo-check"><i class="fas fa-check-circle"></i></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($selected_branch > 0): ?>
                
                <!-- ✅ TRANSACTION TYPE - CARDS NZURI -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-tag"></i> Transaction Type</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <div class="type-options-grid">
                        <?php foreach ($type_labels as $key => $label): 
                            $is_disabled = in_array($key, ['cash_out', 'adjustment']) && $current_capital <= 0;
                            $gradient = $label['gradient'] ?? 'linear-gradient(135deg, #059669, #047857)';
                        ?>
                            <label class="type-option-card type-option-<?php echo $label['color']; ?> <?php echo $is_disabled ? 'type-option-disabled' : ''; ?>">
                                <input type="radio" 
                                       name="transaction_type" 
                                       value="<?php echo $key; ?>" 
                                       <?php echo $key == 'additional' ? 'checked' : ''; ?>
                                       <?php echo $is_disabled ? 'disabled' : ''; ?>
                                       onchange="updatePreview()">
                                <div class="toc-content">
                                    <div class="toc-icon" style="background: <?php echo $gradient; ?>;">
                                        <i class="fas <?php echo $label['icon']; ?>"></i>
                                    </div>
                                    <div class="toc-text">
                                        <span class="toc-title"><?php echo $label['label']; ?></span>
                                        <span class="toc-desc">
                                            <?php if ($is_disabled): ?>
                                                <span style="color: #DC2626; font-weight: 700;">
                                                    <i class="fas fa-lock"></i> No capital
                                                </span>
                                            <?php else: ?>
                                                <?php echo $label['desc']; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="toc-check"><i class="fas fa-check-circle"></i></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- ✅ CASH SECTION - DESIGN NZURI -->
                <div class="form-section cash-section">
                    <div class="section-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Cash Amount</h3>
                        <span class="section-badge">
                            Optional • Current: <?php echo formatCurrency($current_cash); ?>
                        </span>
                    </div>
                    
                    <div class="cash-input-card" id="cashCard">
                        <div class="cic-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="cic-content">
                            <label class="cic-label">
                                Amount to Add/Reduce (TSh)
                                <?php if ($current_cash > 0): ?>
                                    <span style="opacity: 0.8; margin-left: 8px; font-weight: 500;">
                                        (Available: <?php echo formatCurrency($current_cash); ?>)
                                    </span>
                                <?php endif; ?>
                            </label>
                            <div class="cic-input-wrapper">
                                <span class="cic-currency">TSh</span>
                                <input type="text" 
                                       name="cash_amount" 
                                       id="cashAmountInput"
                                       class="cic-input money-input" 
                                       placeholder="0"
                                       inputmode="numeric"
                                       data-available="<?php echo $current_cash; ?>"
                                       oninput="formatMoneyInput(this); updatePreview();">
                            </div>
                            <div class="cic-hint" id="cashHint">
                                <i class="fas fa-info-circle"></i>
                                Acha wazi kama hutaki kubadilisha cash
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ✅ PROVIDERS SECTION - GREEN THEME CARDS -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Floats</h3>
                        <span class="section-badge">Optional • <?php echo count($all_providers); ?> providers</span>
                    </div>
                    
                    <?php if (count($all_providers) > 0): ?>
                        <div class="providers-amount-grid">
                            <?php foreach ($all_providers as $p): ?>
                                <div class="provider-amount-card provider-amount-green">
                                    <div class="pac-header">
                                        <div class="pac-icon" style="background: <?php echo htmlspecialchars($p['color_code']); ?>;">
                                            <i class="<?php echo htmlspecialchars($p['icon_class']); ?>"></i>
                                        </div>
                                        <div class="pac-info">
                                            <span class="pac-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                            <span class="pac-code"><?php echo htmlspecialchars($p['branch_provider_code'] ?? $p['main_code']); ?></span>
                                        </div>
                                    </div>
                                    <div class="pac-current">
                                        <span class="pac-current-label">Current Float:</span>
                                        <span class="pac-current-value">
                                            <?php echo formatCurrency($p['current_float']); ?>
                                        </span>
                                    </div>
                                    <div class="pac-input-wrapper">
                                        <span class="pac-currency">TSh</span>
                                        <input type="text" 
                                               name="provider_amounts[<?php echo $p['id']; ?>]" 
                                               class="pac-input provider-amount-input money-input" 
                                               placeholder="0"
                                               inputmode="numeric"
                                               data-provider-id="<?php echo $p['id']; ?>"
                                               data-available="<?php echo $p['current_float']; ?>"
                                               oninput="formatMoneyInput(this); updatePreview();">
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
                
                <!-- BASIC INFO -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Transaction Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt"></i>
                                Date <span class="required">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar"></i></span>
                                <input type="date" name="transaction_date" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       max="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i>
                                Description
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-pen"></i></span>
                                <input type="text" name="description" class="form-control" 
                                       placeholder="Brief description">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-sticky-note"></i>
                                Notes
                            </label>
                            <textarea name="notes" class="form-control textarea-control" rows="3" 
                                      placeholder="Additional notes..."></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- ✅ PREVIEW - DESIGN NZURI -->
                <div class="form-section preview-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Preview</h3>
                    </div>
                    
                    <div class="preview-grid">
                        <div class="preview-item preview-item-direction">
                            <span class="pi-label">Direction</span>
                            <span class="pi-value" id="previewDirection">
                                <span class="direction-badge badge-in">
                                    <i class="fas fa-arrow-down"></i>
                                    INCOMING
                                </span>
                            </span>
                        </div>
                        
                        <div class="preview-item preview-item-amount">
                            <span class="pi-label">Total Amount</span>
                            <span class="pi-value pi-amount" id="previewAmount">TSh 0</span>
                        </div>
                        
                        <div class="preview-item preview-highlight">
                            <span class="pi-label">New Capital After</span>
                            <span class="pi-value" id="previewNewCapital">
                                <?php echo formatCurrency($current_capital); ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- ✅ Warning Preview -->
                    <div class="preview-warning" id="previewWarning" style="display:none;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span id="previewWarningText"></span>
                    </div>
                </div>
                
                <!-- ACTIONS -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Transaction
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="index.php?branch=<?php echo $selected_branch; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
                
                <?php else: ?>
                    <div class="form-section">
                        <div class="select-branch-message">
                            <i class="fas fa-arrow-up"></i>
                            <p>Please select a branch from above to continue</p>
                        </div>
                    </div>
                <?php endif; ?>
                
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   VARIABLES - GREEN THEME
   ============================================================ */
:root {
    --ca-bg: #F3F4F6;
    --ca-text: #1F2937;
    --ca-text-secondary: #6B7280;
    --ca-text-light: #9CA3AF;
    --ca-border: #E5E7EB;
    --ca-card-bg: #FFFFFF;
    --ca-input-bg: #F9FAFB;
    --ca-hover: #F3F4F6;
    --ca-shadow: rgba(0,0,0,0.06);
    --ca-shadow-md: rgba(0,0,0,0.1);
    
    --green-primary: #059669;
    --green-dark: #047857;
    --green-darker: #065F46;
    --green-light: #D1FAE5;
    --green-lighter: #A7F3D0;
    --green-accent: #10B981;
    --green-bright: #34D399;
}
html.dark-mode {
    --ca-bg: #0F172A;
    --ca-text: #F9FAFB;
    --ca-text-secondary: #9CA3AF;
    --ca-text-light: #6B7280;
    --ca-border: #334155;
    --ca-card-bg: #1E293B;
    --ca-input-bg: #334155;
    --ca-hover: #334155;
    --ca-shadow: rgba(0,0,0,0.3);
    --ca-shadow-md: rgba(0,0,0,0.5);
    
    --green-light: #065F46;
    --green-lighter: #047857;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }

body { background: var(--ca-bg) !important; color: var(--ca-text); }
.main-wrapper { background: var(--ca-bg) !important; }
.main-content { 
    background: var(--ca-bg) !important; 
    padding: 16px 20px !important; 
    max-width: 100% !important;
}

/* BRANCH CARD */
.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px; border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
    flex-wrap: wrap; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-status-card.branch-all {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}
.branch-status-card.branch-selected {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D;
    flex-shrink: 0; position: relative; z-index: 1;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; position: relative; z-index: 1; flex: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.branch-status-name { font-size: 18px; font-weight: 800; color: #FFFFFF; }
.branch-status-code {
    font-size: 11px; font-weight: 700; color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    font-family: 'Courier New', monospace;
}
.btn-back-card {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 20px;
    gap: 16px; flex-wrap: wrap;
}
.header-left h2 {
    font-size: 22px; font-weight: 800;
    color: var(--ca-text); margin: 0;
    display: flex; align-items: center; gap: 10px;
}
.header-left h2 i { color: #059669; }
.header-left .text-muted { font-size: 13px; color: var(--ca-text-secondary); margin: 4px 0 0 0; }

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 12px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 22px;
    color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6;
}

/* NO BRANCH WARNING */
.no-branch-warning {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; background: #FEF3C7;
    border: 2px solid #FDE68A; border-radius: 12px;
    margin-bottom: 16px; color: #92400E;
}
.no-branch-warning i { font-size: 24px; flex-shrink: 0; margin-top: 2px; color: #D97706; }
.no-branch-warning strong { font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px; }
.no-branch-warning p { font-size: 13px; margin: 0; line-height: 1.5; }
html.dark-mode .no-branch-warning { background: #5F3A1E; border-color: #92400E; color: #FBBF24; }

/* NO DAILY REPORT WARNING */
.no-daily-report-warning {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 2px solid #60A5FA; border-radius: 12px;
    margin-bottom: 16px; color: #1E40AF;
}
html.dark-mode .no-daily-report-warning {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6; color: #93C5FD;
}
.no-daily-report-warning i { font-size: 24px; flex-shrink: 0; margin-top: 2px; }
.no-daily-report-warning strong { font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px; }
.no-daily-report-warning p { font-size: 13px; margin: 0; line-height: 1.5; }

/* ============================================================
   ✅ SUMMARY MINI CARDS - BEAUTIFUL DESIGN
   ============================================================ */
.summary-cards-row {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; margin-bottom: 20px;
}
.summary-mini-card {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%, #A7F3D0 100%);
    border-radius: 16px; padding: 20px 22px;
    border: 2px solid #6EE7B7;
    display: flex; align-items: center; gap: 16px;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.15);
    transition: all 0.3s ease;
    position: relative; overflow: hidden; min-width: 0;
}
html.dark-mode .summary-mini-card {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%);
    border-color: #10B981;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
}
.summary-mini-card::before {
    content: ''; position: absolute;
    top: -40px; right: -40px;
    width: 140px; height: 140px;
    background: rgba(16, 185, 129, 0.15);
    border-radius: 50%;
    pointer-events: none;
}
.summary-mini-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 32px rgba(5, 150, 105, 0.3);
    border-color: #059669;
}
html.dark-mode .summary-mini-card:hover {
    border-color: #34D399;
    box-shadow: 0 12px 32px rgba(16, 185, 129, 0.4);
}

.smc-icon {
    width: 56px; height: 56px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
    background: linear-gradient(135deg, #059669, #047857);
    color: #FFFFFF;
    border: 2px solid rgba(255, 255, 255, 0.5);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
    position: relative; z-index: 1;
}
.smc-content { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; position: relative; z-index: 1; }
.smc-label {
    font-size: 11px; font-weight: 800;
    color: #047857;
    text-transform: uppercase; letter-spacing: 1.2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
html.dark-mode .smc-label { color: #6EE7B7; }
.smc-value {
    font-size: 22px; font-weight: 900;
    color: #065F46;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word; line-height: 1.15;
    letter-spacing: -0.5px;
}
html.dark-mode .smc-value { color: #D1FAE5; }

/* FORM CONTAINER */
.form-container {
    background: var(--ca-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--ca-border);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--ca-shadow);
}
.form-section {
    padding: 22px 26px;
    border-bottom: 1px solid var(--ca-border);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 18px;
    flex-wrap: wrap; gap: 8px;
}
.section-header h3 {
    font-size: 14px; font-weight: 800;
    color: var(--ca-text); margin: 0;
    display: flex; align-items: center; gap: 10px;
    text-transform: uppercase; letter-spacing: 0.8px;
}
.section-header h3 i { color: #059669; font-size: 15px; }
.section-badge {
    font-size: 10px; font-weight: 700;
    color: var(--ca-text-secondary);
    background: var(--ca-hover);
    padding: 3px 12px; border-radius: 12px;
    text-transform: uppercase; letter-spacing: 0.5px;
}

/* SELECTED BRANCH LOCKED */
.selected-branch-locked {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border: 2px solid #10B981;
    border-radius: 12px; flex-wrap: wrap;
}
html.dark-mode .selected-branch-locked {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 100%);
}
.sbl-icon {
    width: 52px; height: 52px;
    background: #059669; color: #FFFFFF;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.sbl-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.sbl-name { font-size: 16px; font-weight: 800; color: #065F46; }
html.dark-mode .sbl-name { color: #D1FAE5; }
.sbl-code {
    font-size: 11px; font-weight: 700; color: #059669;
    background: rgba(16, 185, 129, 0.15);
    padding: 3px 10px; border-radius: 8px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
.sbl-change {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: #FFFFFF; color: #059669;
    border-radius: 8px; text-decoration: none;
    font-size: 12px; font-weight: 700;
    transition: all 0.25s ease;
    border: 1.5px solid #10B981;
}
.sbl-change:hover { background: #059669; color: #FFFFFF; transform: translateX(3px); }

/* BRANCH SELECTION GRID */
.branch-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
}
.branch-option {
    position: relative; cursor: pointer;
    display: block; border-radius: 12px; overflow: hidden;
}
.branch-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.bo-content {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px;
    background: var(--ca-input-bg);
    border: 2px solid var(--ca-border);
    border-radius: 12px;
    transition: all 0.3s ease;
}
.branch-option:hover .bo-content { border-color: #059669; transform: translateY(-2px); }
.branch-option input[type="radio"]:checked + .bo-content {
    border-color: #059669;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.3);
}
html.dark-mode .branch-option input[type="radio"]:checked + .bo-content {
    background: linear-gradient(135deg, #065F46, #047857);
}
.bo-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: linear-gradient(135deg, #059669, #047857);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.bo-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.bo-name { font-size: 14px; font-weight: 800; color: var(--ca-text); }
.bo-code {
    font-size: 10px; font-weight: 700; color: #059669;
    background: #D1FAE5; padding: 2px 8px;
    border-radius: 6px; font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .bo-code { background: #065F46; color: #34D399; }
.bo-check { opacity: 0; color: #059669; font-size: 20px; flex-shrink: 0; }
.branch-option input[type="radio"]:checked + .bo-content .bo-check { opacity: 1; }

/* ============================================================
   ✅ TYPE OPTIONS GRID - BEAUTIFUL CARDS
   ============================================================ */
.type-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}
.type-option-card {
    position: relative; cursor: pointer;
    display: block; border-radius: 14px; overflow: hidden;
    transition: all 0.3s ease;
}
.type-option-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.toc-content {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    background: var(--ca-input-bg);
    border: 2px solid var(--ca-border);
    border-radius: 14px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.type-option-card:hover .toc-content { 
    border-color: #6EE7B7; 
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.15);
}
.type-option-card input[type="radio"]:checked + .toc-content {
    border-color: #059669;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.25);
}
html.dark-mode .type-option-card input[type="radio"]:checked + .toc-content {
    background: linear-gradient(135deg, #065F46, #047857);
}
.toc-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.4);
}
.toc-text { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.toc-title { font-size: 14px; font-weight: 800; color: var(--ca-text); letter-spacing: -0.2px; }
.toc-desc { font-size: 11px; color: var(--ca-text-secondary); font-weight: 500; }
.toc-check { 
    opacity: 0; 
    color: #059669; 
    font-size: 22px; 
    transition: all 0.3s ease;
    transform: scale(0.5);
}
.type-option-card input[type="radio"]:checked + .toc-content .toc-check { 
    opacity: 1; 
    transform: scale(1);
}

/* DISABLED TYPE OPTION */
.type-option-disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.type-option-disabled .toc-content {
    background: #F3F4F6;
    border-color: #D1D5DB;
}
html.dark-mode .type-option-disabled .toc-content {
    background: #1F2937;
    border-color: #374151;
}
.type-option-disabled:hover .toc-content {
    border-color: #D1D5DB;
    transform: none;
    box-shadow: none;
}
.type-option-disabled .toc-icon {
    filter: grayscale(0.5);
}

/* ============================================================
   ✅ CASH SECTION - BEAUTIFUL CARD
   ============================================================ */
.cash-section { 
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); 
}
html.dark-mode .cash-section { 
    background: linear-gradient(135deg, #064E3B 0%, #065F46 100%); 
}
.cash-input-card {
    display: flex; align-items: center; gap: 20px;
    padding: 24px 28px;
    background: linear-gradient(135deg, #059669 0%, #047857 50%, #065F46 100%);
    border-radius: 16px;
    box-shadow: 0 8px 32px rgba(5, 150, 105, 0.35);
    color: #FFFFFF;
    flex-wrap: wrap;
    position: relative; overflow: hidden;
    transition: all 0.3s ease;
    border: 2px solid rgba(255, 255, 255, 0.15);
}
.cash-input-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.cash-input-card::after {
    content: ''; position: absolute; bottom: -60%; left: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.cash-input-card.danger {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    box-shadow: 0 8px 32px rgba(220, 38, 38, 0.35);
    border-color: rgba(255, 255, 255, 0.2);
}
.cic-icon {
    width: 72px; height: 72px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
    backdrop-filter: blur(10px);
}
.cic-content {
    display: flex; flex-direction: column; gap: 10px;
    flex: 1; min-width: 240px;
    position: relative; z-index: 1;
}
.cic-label {
    font-size: 12px; font-weight: 800;
    color: rgba(255, 255, 255, 0.9);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.cic-input-wrapper {
    display: flex; align-items: center;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 14px; padding: 6px 12px;
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
}
.cic-input-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 4px rgba(252, 211, 77, 0.4);
    transform: scale(1.01);
}
.cic-currency {
    font-size: 16px; font-weight: 900;
    color: #059669;
    padding: 0 14px 0 8px;
    border-right: 2px solid #D1FAE5;
    margin-right: 10px;
    font-family: 'Courier New', monospace;
}
.cic-input {
    flex: 1;
    border: none; background: transparent;
    padding: 14px 10px;
    font-size: 24px;
    font-weight: 900;
    color: #065F46;
    font-family: 'Courier New', monospace;
    text-align: right;
    outline: none;
    letter-spacing: 0.5px;
}
.cic-hint {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; color: rgba(255, 255, 255, 0.85);
    font-weight: 600;
}
.cic-hint i { color: #FCD34D; }

/* ============================================================
   ✅ PROVIDERS AMOUNT GRID - GREEN THEME CARDS
   ============================================================ */
.providers-amount-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

/* ✅ PROVIDER AMOUNT CARD - GREEN THEME (kama view.php) */
.provider-amount-card {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%, #A7F3D0 100%);
    border: 2px solid #6EE7B7;
    border-radius: 14px;
    overflow: hidden;
    transition: all 0.3s ease;
    position: relative;
    min-width: 0;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.12);
}
.provider-amount-card::before {
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
html.dark-mode .provider-amount-card {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%);
    border-color: #10B981;
    box-shadow: 0 4px 16px rgba(16, 185, 129, 0.2);
}
html.dark-mode .provider-amount-card::before {
    background: rgba(16, 185, 129, 0.2);
}
.provider-amount-card:hover {
    border-color: #059669;
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(5, 150, 105, 0.25);
}
html.dark-mode .provider-amount-card:hover {
    border-color: #34D399;
    box-shadow: 0 12px 28px rgba(16, 185, 129, 0.35);
}

.pac-header {
    padding: 12px 14px;
    background: rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(10px);
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.2);
    display: flex; align-items: center; gap: 10px;
    position: relative;
    z-index: 1;
}
html.dark-mode .pac-header {
    background: rgba(15, 23, 42, 0.3);
    border-bottom-color: rgba(16, 185, 129, 0.3);
}
.pac-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 17px; flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
}
.pac-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.pac-name {
    font-size: 13px; font-weight: 800;
    color: #065F46;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    letter-spacing: -0.2px;
}
html.dark-mode .pac-name { color: #D1FAE5; }
.pac-code {
    font-size: 10px; font-weight: 700;
    color: #047857;
    background: rgba(255, 255, 255, 0.7);
    padding: 2px 8px;
    border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
    border: 1px solid rgba(5, 150, 105, 0.3);
}
html.dark-mode .pac-code {
    background: rgba(15, 23, 42, 0.4);
    color: #6EE7B7;
    border-color: rgba(16, 185, 129, 0.4);
}

/* PAC CURRENT */
.pac-current {
    padding: 10px 14px;
    background: rgba(255, 255, 255, 0.4);
    display: flex; justify-content: space-between; align-items: center;
    font-size: 11px;
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.15);
    position: relative;
    z-index: 1;
}
html.dark-mode .pac-current {
    background: rgba(15, 23, 42, 0.2);
    border-bottom-color: rgba(16, 185, 129, 0.2);
}
.pac-current-label { 
    color: #047857; 
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 10px;
}
html.dark-mode .pac-current-label { color: #6EE7B7; }
.pac-current-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #059669;
    font-size: 13px;
}
html.dark-mode .pac-current-value { color: #34D399; }

/* PAC INPUT */
.pac-input-wrapper {
    display: flex; align-items: center;
    padding: 10px 14px;
    background: rgba(255, 255, 255, 0.85);
    position: relative;
    z-index: 1;
}
html.dark-mode .pac-input-wrapper {
    background: rgba(15, 23, 42, 0.4);
}
.pac-currency {
    font-size: 13px; font-weight: 900;
    color: #059669;
    padding: 0 10px 0 0;
    border-right: 2px solid #D1FAE5;
    margin-right: 10px;
    font-family: 'Courier New', monospace;
}
html.dark-mode .pac-currency { 
    border-color: #065F46; 
    color: #34D399; 
}
.pac-input {
    flex: 1;
    border: none; background: transparent;
    padding: 12px 6px;
    font-size: 18px;
    font-weight: 900;
    color: #065F46;
    font-family: 'Courier New', monospace;
    text-align: right;
    outline: none;
    min-width: 0;
    letter-spacing: 0.5px;
}
html.dark-mode .pac-input { color: #A7F3D0; }
.pac-input::placeholder { color: rgba(5, 150, 105, 0.4); font-weight: 700; }
html.dark-mode .pac-input::placeholder { color: rgba(167, 243, 208, 0.3); }

.empty-providers {
    text-align: center; padding: 30px 20px;
    background: var(--ca-input-bg);
    border-radius: 10px;
    border: 1px dashed var(--ca-border);
    color: var(--ca-text-secondary);
}
.empty-providers i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

/* FORM ROWS */
.form-row {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 16px; margin-bottom: 16px;
}
.form-row:last-child { margin-bottom: 0; }
.form-row .full-width { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.form-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700;
    color: var(--ca-text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-label i { color: #059669; font-size: 13px; }
.form-label .required { color: #DC2626; font-weight: 800; }

.input-group { position: relative; display: flex; align-items: center; }
.input-icon {
    position: absolute; left: 14px;
    color: #059669; font-size: 14px;
    z-index: 1; pointer-events: none;
}
.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border-radius: 10px;
    border: 1.5px solid var(--ca-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--ca-input-bg);
    color: var(--ca-text);
    font-weight: 500;
}
.form-control::placeholder { color: var(--ca-text-light); }
.form-control:focus {
    border-color: #059669;
    box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.12);
    background: var(--ca-card-bg);
}
.form-control.textarea-control {
    padding: 12px 14px;
    min-height: 80px;
    resize: vertical;
    line-height: 1.6;
}

/* ============================================================
   ✅ PREVIEW SECTION - BEAUTIFUL
   ============================================================ */
.preview-section { 
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%); 
}
html.dark-mode .preview-section { 
    background: linear-gradient(135deg, #065F46 0%, #047857 100%); 
}
.preview-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.preview-item {
    display: flex; flex-direction: column; gap: 8px;
    padding: 18px 20px;
    background: rgba(255, 255, 255, 0.85);
    border-radius: 14px;
    border: 2px solid rgba(5, 150, 105, 0.3);
    min-width: 0;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.1);
    transition: all 0.3s ease;
}
html.dark-mode .preview-item {
    background: rgba(15, 23, 42, 0.5);
    border-color: rgba(110, 231, 183, 0.3);
}
.preview-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(5, 150, 105, 0.2);
}
.preview-item.preview-highlight {
    background: linear-gradient(135deg, #059669, #047857);
    border-color: #059669;
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.4);
}
.preview-item.preview-highlight:hover {
    box-shadow: 0 12px 32px rgba(5, 150, 105, 0.5);
}
.pi-label {
    font-size: 10px; font-weight: 800;
    color: #065F46;
    text-transform: uppercase; letter-spacing: 1.2px;
}
html.dark-mode .pi-label { color: #A7F3D0; }
.preview-highlight .pi-label { color: rgba(255, 255, 255, 0.9); }
.pi-value {
    font-size: 18px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word; line-height: 1.2;
    color: #065F46;
}
html.dark-mode .pi-value { color: #D1FAE5; }
.pi-amount { color: #059669; }
.preview-highlight .pi-value { 
    color: #FFFFFF; 
    font-size: 22px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

/* PREVIEW WARNING */
.preview-warning {
    margin-top: 16px;
    padding: 14px 18px;
    background: #FEE2E2;
    border: 2px solid #FCA5A5;
    border-radius: 12px;
    color: #991B1B;
    display: flex; align-items: center; gap: 12px;
    font-size: 13px; font-weight: 600;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.15);
}
html.dark-mode .preview-warning {
    background: #7F1D1D;
    border-color: #991B1B;
    color: #FEE2E2;
}
.preview-warning i { font-size: 20px; flex-shrink: 0; }

.direction-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 10px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.8px;
    border: 2px solid;
}
.direction-badge.badge-in {
    background: #D1FAE5; color: #065F46;
    border-color: #10B981;
}
.direction-badge.badge-out {
    background: #FEE2E2; color: #991B1B;
    border-color: #DC2626;
}

/* FORM ACTIONS */
.form-actions {
    display: flex; gap: 12px;
    padding: 20px 26px;
    border-top: 1px solid var(--ca-border);
    background: var(--ca-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px; border-radius: 10px;
    font-weight: 700; font-size: 14px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none; white-space: nowrap;
}
.btn-submit {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.45);
    color: white;
}
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-reset, .btn-cancel {
    background: var(--ca-card-bg);
    color: var(--ca-text-secondary);
    border: 1.5px solid var(--ca-border);
}
.btn-reset:hover { background: var(--ca-border); color: var(--ca-text); }
.btn-cancel:hover {
    background: #FEE2E2; color: #991B1B;
    border-color: #FECACA;
}
html.dark-mode .btn-cancel:hover {
    background: #7F1D1D; color: #FEE2E2;
    border-color: #991B1B;
}

.select-branch-message {
    text-align: center; padding: 40px 20px;
    color: var(--ca-text-secondary);
}
.select-branch-message i {
    font-size: 48px; color: #059669;
    display: block; margin-bottom: 14px;
    animation: bounceUpDown 2s ease-in-out infinite;
}
@keyframes bounceUpDown {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
.select-branch-message p { font-size: 16px; font-weight: 600; margin: 0; }

/* RESPONSIVE */
@media (max-width: 1024px) {
    .providers-amount-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .summary-cards-row { grid-template-columns: 1fr; }
    .branch-selection-grid { grid-template-columns: 1fr; }
    .type-options-grid { grid-template-columns: 1fr; }
    .providers-amount-grid { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .form-row .full-width { grid-column: span 1; }
    .preview-grid { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .form-section { padding: 18px 20px; }
    .cash-input-card { flex-direction: column; align-items: flex-start; padding: 20px; }
    .cic-icon { width: 60px; height: 60px; font-size: 26px; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .header-left h2 { font-size: 18px; }
    .cic-input { font-size: 20px; }
    .pac-input { font-size: 16px; }
    .smc-value { font-size: 18px; }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    var value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; updatePreview(); return; }
    
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
    updatePreview();
}

// ============================================================
// UPDATE PREVIEW
// ============================================================
function updatePreview() {
    var cashInput = document.getElementById('cashAmountInput');
    var cashAmount = 0;
    if (cashInput && cashInput.value) {
        cashAmount = parseFloat(cashInput.value.replace(/,/g, '')) || 0;
    }
    
    var totalProviders = 0;
    document.querySelectorAll('.provider-amount-input').forEach(function(input) {
        if (input.value) {
            totalProviders += parseFloat(input.value.replace(/,/g, '')) || 0;
        }
    });
    
    var totalAmount = cashAmount + totalProviders;
    
    var type = document.querySelector('input[name="transaction_type"]:checked');
    var isOut = type && ['cash_out', 'adjustment'].includes(type.value);
    
    var directionEl = document.getElementById('previewDirection');
    if (directionEl) {
        if (isOut) {
            directionEl.innerHTML = '<span class="direction-badge badge-out"><i class="fas fa-arrow-up"></i> OUTGOING</span>';
        } else {
            directionEl.innerHTML = '<span class="direction-badge badge-in"><i class="fas fa-arrow-down"></i> INCOMING</span>';
        }
    }
    
    var amountEl = document.getElementById('previewAmount');
    if (amountEl) amountEl.textContent = 'TSh ' + totalAmount.toLocaleString('en-US');
    
    var currentCapital = <?php echo floatval($current_capital); ?>;
    var currentCash = <?php echo floatval($current_cash); ?>;
    
    var newCapital = isOut ? Math.max(0, currentCapital - totalAmount) : currentCapital + totalAmount;
    var newCapitalEl = document.getElementById('previewNewCapital');
    if (newCapitalEl) newCapitalEl.textContent = 'TSh ' + newCapital.toLocaleString('en-US');
    
    var warningEl = document.getElementById('previewWarning');
    var warningText = document.getElementById('previewWarningText');
    
    if (isOut && warningEl && warningText) {
        var warnings = [];
        
        if (cashAmount > currentCash) {
            warnings.push('Cash: Unaomba TSh ' + cashAmount.toLocaleString() + 
                          ', iliyopo TSh ' + currentCash.toLocaleString());
        }
        
        if (totalAmount > currentCapital) {
            warnings.push('Jumla: Unaomba TSh ' + totalAmount.toLocaleString() + 
                          ', Capital iliyopo TSh ' + currentCapital.toLocaleString());
        }
        
        if (warnings.length > 0) {
            warningEl.style.display = 'flex';
            warningText.innerHTML = '<strong>⚠️ Haiwezi kuendelea:</strong> ' + warnings.join(' • ');
        } else {
            warningEl.style.display = 'none';
        }
    } else if (warningEl) {
        warningEl.style.display = 'none';
    }
    
    var cashCard = document.getElementById('cashCard');
    if (cashCard) {
        if (isOut && cashAmount > 0) {
            cashCard.classList.add('danger');
        } else {
            cashCard.classList.remove('danger');
        }
    }
}

// ============================================================
// BRANCH CHANGE
// ============================================================
function onBranchChange(branchId) {
    window.location.href = 'add.php?branch=' + branchId;
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var branch = document.querySelector('input[name="branch_id"]:checked');
    var branchHidden = document.querySelector('input[name="branch_id"][type="hidden"]');
    
    if (!branch && !branchHidden) {
        alert('Please select a branch.');
        return false;
    }
    
    var type = document.querySelector('input[name="transaction_type"]:checked');
    if (!type) {
        alert('Please select a transaction type.');
        return false;
    }
    
    var cashInput = document.getElementById('cashAmountInput');
    var cashAmount = cashInput ? parseFloat(cashInput.value.replace(/,/g, '')) || 0 : 0;
    
    var totalProviders = 0;
    document.querySelectorAll('.provider-amount-input').forEach(function(input) {
        if (input.value) totalProviders += parseFloat(input.value.replace(/,/g, '')) || 0;
    });
    
    if (cashAmount <= 0 && totalProviders <= 0) {
        alert('Please enter at least one amount (Cash or Provider).');
        return false;
    }
    
    var isOut = ['cash_out', 'adjustment'].includes(type.value);
    var totalAmount = cashAmount + totalProviders;
    var currentCapital = <?php echo floatval($current_capital); ?>;
    var currentCash = <?php echo floatval($current_cash); ?>;
    
    if (isOut) {
        if (cashAmount > 0 && currentCash <= 0) {
            alert('❌ Huwezi kufanya Cash Out.\n\nHakuna Daily Report au Cash iliyopo kwa branch hii.\nTafadhali tengeneza Opening Capital kwanza.');
            return false;
        }
        
        if (cashAmount > currentCash) {
            alert('❌ Cash haitoshi.\n\n' +
                  'Iliyopo: TSh ' + currentCash.toLocaleString() + '\n' +
                  'Unaomba: TSh ' + cashAmount.toLocaleString());
            return false;
        }
        
        if (totalAmount > currentCapital) {
            alert('❌ Jumla inazidi Capital iliyopo.\n\n' +
                  'Iliyopo: TSh ' + currentCapital.toLocaleString() + '\n' +
                  'Unaomba: TSh ' + totalAmount.toLocaleString());
            return false;
        }
    }
    
    var confirmMsg = 'Confirm Transaction:\n\n' +
                     'Type: ' + type.parentElement.querySelector('.toc-title').textContent + '\n' +
                     'Cash: TSh ' + cashAmount.toLocaleString() + '\n' +
                     'Providers: TSh ' + totalProviders.toLocaleString() + '\n' +
                     'Total: TSh ' + totalAmount.toLocaleString() + '\n' +
                     'Direction: ' + (isOut ? 'OUTGOING' : 'INCOMING') + '\n\n' +
                     'Continue?';
    
    if (!confirm(confirmMsg)) return false;
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAny unsaved data will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
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