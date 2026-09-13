<?php
// ================================================================
// FILE: modules/commissions/edit_capital.php
// WAKALA FINANCIAL SYSTEM - EDIT CAPITAL
// ✅ Edit capital date, amount, notes
// ✅ Edit provider float allocations
// ✅ Auto-update daily_reports
// ✅ Show available profit & current capital
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

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: index.php');
    exit();
}

$capital_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($capital_id <= 0) {
    $_SESSION['error_message'] = 'Invalid capital ID.';
    header('Location: add_capital.php');
    exit();
}

// ============================================================
// GET CAPITAL ENTRY
// ============================================================
$stmt = $db->prepare("
    SELECT 
        c.*,
        emp.full_name as employee_name,
        b.branch_name,
        b.branch_code,
        b.location as branch_location
    FROM commissions c
    LEFT JOIN employees emp ON c.employee_id = emp.id
    LEFT JOIN branches b ON c.branch_id = b.id
    WHERE c.id = ? AND c.commission_number LIKE 'CAP-%'
");
$stmt->execute([$capital_id]);
$capital = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$capital) {
    $_SESSION['error_message'] = 'Capital entry not found.';
    header('Location: add_capital.php');
    exit();
}

$branch_id = intval($capital['branch_id']);

// ============================================================
// PARSE OLD PROVIDER DATA
// ============================================================
$old_provider_data = json_decode($capital['provider_data'] ?? '{}', true);
$old_target = $old_provider_data['capital_target'] ?? 'float';
$old_entries = $old_provider_data['entries'] ?? [];
$old_amount = floatval($capital['allocated_amount']);

// Build old provider amounts map (provider_id => amount)
$old_provider_amounts = [];
if ($old_target === 'float' && is_array($old_entries)) {
    foreach ($old_entries as $entry) {
        if (is_array($entry) && isset($entry['provider_id'])) {
            $pid = intval($entry['provider_id']);
            $old_provider_amounts[$pid] = floatval($entry['amount'] ?? 0);
        }
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_capital') {
    try {
        $capital_date = $_POST['capital_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $new_target = $_POST['capital_target'] ?? 'float';
        
        // Recalculate available profit (EXCLUDING this entry)
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as total FROM commissions WHERE branch_id = ?");
        $stmt->execute([$branch_id]);
        $comm = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        
        $stmt = $db->prepare("SELECT COALESCE(SUM(other_income), 0) as total FROM commissions WHERE branch_id = ?");
        $stmt->execute([$branch_id]);
        $other = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        
        $exp = 0; $sal = 0;
        try {
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE branch_id = ? AND is_business_expense = 1");
            $stmt->execute([$branch_id]);
            $exp = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        } catch (Exception $e) { }
        
        try {
            $stmt = $db->prepare("SELECT COALESCE(SUM(net_pay), 0) as total FROM employee_salaries WHERE branch_id = ? AND status = 'paid'");
            $stmt->execute([$branch_id]);
            $sal = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        } catch (Exception $e) { }
        
        $earnings = ($comm + $other) - ($exp + $sal);
        
        // Total capital added EXCLUDING current entry
        $stmt = $db->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total FROM commissions WHERE branch_id = ? AND commission_number LIKE 'CAP-%' AND id != ?");
        $stmt->execute([$branch_id, $capital_id]);
        $other_capital = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        
        $available = $earnings - $other_capital;
        if ($available < 0) $available = 0;
        
        $db->beginTransaction();
        
        // Get latest daily report
        $stmt = $db->prepare("
            SELECT id, current_cash, current_capital 
            FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$latest_dr) {
            throw new Exception('No daily report found for this branch.');
        }
        
        $dr_id = $latest_dr['id'];
        
        // ========================================================
        // STEP 1: REVERSE OLD CAPITAL ENTRY
        // ========================================================
        if ($old_target === 'float') {
            // Reverse old provider floats
            foreach ($old_provider_amounts as $pid => $amt) {
                $stmt = $db->prepare("
                    SELECT id, current_float 
                    FROM daily_report_providers 
                    WHERE daily_report_id = ? AND provider_id = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$dr_id, $pid]);
                $drp = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($drp) {
                    $new_float = max(0, floatval($drp['current_float']) - $amt);
                    $stmt = $db->prepare("UPDATE daily_report_providers SET current_float = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_float, $drp['id']]);
                }
            }
            
            // Reduce branch capital
            $reversed_capital = max(0, floatval($latest_dr['current_capital']) - $old_amount);
            $stmt = $db->prepare("UPDATE daily_reports SET current_capital = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$reversed_capital, $dr_id]);
            
            // Refresh latest_dr
            $stmt = $db->prepare("SELECT current_cash, current_capital FROM daily_reports WHERE id = ?");
            $stmt->execute([$dr_id]);
            $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } else {
            // Reverse cash
            $reversed_cash = max(0, floatval($latest_dr['current_cash']) - $old_amount);
            $reversed_capital = max(0, floatval($latest_dr['current_capital']) - $old_amount);
            $stmt = $db->prepare("UPDATE daily_reports SET current_cash = ?, current_capital = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$reversed_cash, $reversed_capital, $dr_id]);
            
            // Refresh
            $stmt = $db->prepare("SELECT current_cash, current_capital FROM daily_reports WHERE id = ?");
            $stmt->execute([$dr_id]);
            $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // ========================================================
        // STEP 2: APPLY NEW CAPITAL ENTRY
        // ========================================================
        $new_grand_total = 0;
        $new_entries = [];
        
        if ($new_target === 'float') {
            $provider_amounts = $_POST['provider_amounts'] ?? [];
            
            if (empty($provider_amounts)) {
                throw new Exception('Please enter at least one provider amount.');
            }
            
            foreach ($provider_amounts as $provider_id => $amount_raw) {
                $provider_id = intval($provider_id);
                $amount = floatval(str_replace(',', '', $amount_raw));
                
                if ($amount <= 0 || $provider_id <= 0) continue;
                
                // Verify provider exists in branch
                $stmt = $db->prepare("
                    SELECT p.provider_name, bp.provider_code 
                    FROM providers p
                    INNER JOIN branch_providers bp ON p.id = bp.provider_id AND bp.branch_id = ?
                    WHERE p.id = ?
                ");
                $stmt->execute([$branch_id, $provider_id]);
                $provider_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$provider_info) continue;
                
                if (($new_grand_total + $amount) > $available) {
                    throw new Exception(
                        'Total amount exceeds available profit (' . formatCurrency($available) . ').'
                    );
                }
                
                // Update provider float
                $stmt = $db->prepare("
                    SELECT id, current_float 
                    FROM daily_report_providers 
                    WHERE daily_report_id = ? AND provider_id = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$dr_id, $provider_id]);
                $drp = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($drp) {
                    $new_float = floatval($drp['current_float']) + $amount;
                    $stmt = $db->prepare("UPDATE daily_report_providers SET current_float = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_float, $drp['id']]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO daily_report_providers 
                        (daily_report_id, provider_id, provider_code, provider_name,
                         morning_float, morning_cash, current_float, current_cash,
                         total_deposits, total_withdrawals, created_at)
                        VALUES (?, ?, ?, ?, 0, 0, ?, 0, 0, 0, NOW())
                    ");
                    $stmt->execute([
                        $dr_id, $provider_id,
                        $provider_info['provider_code'],
                        $provider_info['provider_name'],
                        $amount
                    ]);
                }
                
                $new_grand_total += $amount;
                $new_entries[] = [
                    'provider_id' => $provider_id,
                    'provider_name' => $provider_info['provider_name'],
                    'amount' => $amount
                ];
            }
            
            if ($new_grand_total <= 0) {
                throw new Exception('Please enter valid amounts for at least one provider.');
            }
            
            // Update branch capital
            $new_capital = floatval($latest_dr['current_capital']) + $new_grand_total;
            $stmt = $db->prepare("UPDATE daily_reports SET current_capital = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_capital, $dr_id]);
            
        } else {
            $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
            
            if ($amount <= 0) {
                throw new Exception('Please enter a valid amount.');
            }
            
            if ($amount > $available) {
                throw new Exception('Amount exceeds available profit (' . formatCurrency($available) . ').');
            }
            
            $new_cash = floatval($latest_dr['current_cash']) + $amount;
            $new_capital = floatval($latest_dr['current_capital']) + $amount;
            
            $stmt = $db->prepare("UPDATE daily_reports SET current_cash = ?, current_capital = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_cash, $new_capital, $dr_id]);
            
            $new_grand_total = $amount;
            $new_entries[] = 'Branch Cash: ' . formatCurrency($amount);
        }
        
        // ========================================================
        // STEP 3: UPDATE COMMISSION RECORD
        // ========================================================
        $new_provider_json = json_encode([
            'capital_target' => $new_target,
            'entries' => $new_entries
        ]);
        
        $notes_combined = '[CAPITAL ADDITION] ' . 
            ($new_target === 'float' ? 'To Provider Float' : 'To Branch Cash');
        if (!empty($notes)) {
            $notes_combined .= ' - ' . $notes;
        }
        
        $stmt = $db->prepare("UPDATE commissions SET 
            commission_date = ?,
            provider_data = ?,
            allocated_amount = ?,
            notes = ?,
            updated_at = NOW()
            WHERE id = ?");
        
        $stmt->execute([
            $capital_date,
            $new_provider_json,
            $new_grand_total,
            $notes_combined,
            $capital_id
        ]);
        
        $db->commit();
        
        logActivity($user_id, 'Edit Capital', 'Commissions', $capital_id, 
            json_encode(['old_amount' => $old_amount]),
            'Edited capital: ' . $capital['commission_number'] . ' - new amount: ' . formatCurrency($new_grand_total));
        
        $_SESSION['success_message'] = 'Capital updated successfully! New amount: ' . formatCurrency($new_grand_total);
        header('Location: view_capital.php?id=' . $capital_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// ============================================================
// GET BRANCH DATA (Available profit excluding this entry)
// ============================================================
$stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as total FROM commissions WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$total_commission = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$stmt = $db->prepare("SELECT COALESCE(SUM(other_income), 0) as total FROM commissions WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$total_other_income = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

$total_expenses = 0; $total_salaries = 0;
try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE branch_id = ? AND is_business_expense = 1");
    $stmt->execute([$branch_id]);
    $total_expenses = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (Exception $e) { }

try {
    $stmt = $db->prepare("SELECT COALESCE(SUM(net_pay), 0) as total FROM employee_salaries WHERE branch_id = ? AND status = 'paid'");
    $stmt->execute([$branch_id]);
    $total_salaries = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (Exception $e) { }

$total_earnings = ($total_commission + $total_other_income) - ($total_expenses + $total_salaries);

// Capital added EXCLUDING current entry
$stmt = $db->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total FROM commissions WHERE branch_id = ? AND commission_number LIKE 'CAP-%' AND id != ?");
$stmt->execute([$branch_id, $capital_id]);
$other_capital = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// Available = earnings - other capital (kwa sababu tuna-remove current entry)
$available_profit = $total_earnings - $other_capital;
if ($available_profit < 0) $available_profit = 0;

// ============================================================
// GET CURRENT STATUS
// ============================================================
$current_cash = 0;
$current_capital = 0;
$current_float = 0;

$stmt = $db->prepare("
    SELECT current_cash, current_capital 
    FROM daily_reports 
    WHERE branch_id = ? 
    ORDER BY report_date DESC, id DESC 
    LIMIT 1
");
$stmt->execute([$branch_id]);
$dr = $stmt->fetch(PDO::FETCH_ASSOC);
if ($dr) {
    $current_cash = floatval($dr['current_cash'] ?? 0);
    $current_capital = floatval($dr['current_capital'] ?? 0);
}

$stmt = $db->prepare("
    SELECT COALESCE(SUM(drp.current_float), 0) as total_float
    FROM daily_report_providers drp
    INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
    WHERE dr.branch_id = ?
    AND dr.id = (SELECT MAX(id) FROM daily_reports WHERE branch_id = ?)
");
$stmt->execute([$branch_id, $branch_id]);
$current_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);

// ============================================================
// GET ALL PROVIDERS FOR BRANCH
// ============================================================
$stmt = $db->prepare("
    SELECT 
        p.id,
        p.provider_name,
        p.provider_code as main_code,
        p.icon_class,
        p.color_code,
        p.provider_type,
        bp.provider_code as branch_provider_code,
        COALESCE(
            (SELECT drp.current_float FROM daily_report_providers drp
             INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
             WHERE dr.branch_id = ? AND drp.provider_id = p.id
             ORDER BY dr.report_date DESC, dr.id DESC LIMIT 1),
            0
        ) as current_float
    FROM providers p
    INNER JOIN branch_providers bp ON p.id = bp.provider_id
    WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
    ORDER BY p.display_order, p.provider_name
");
$stmt->execute([$branch_id, $branch_id]);
$providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        
        <!-- BRANCH CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-edit"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Editing Capital For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($capital['branch_name'] ?? 'Unknown'); ?></span>
                <?php if (!empty($capital['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($capital['branch_code']); ?></span>
                <?php endif; ?>
            </div>
            <a href="view_capital.php?id=<?php echo $capital_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Details</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-edit"></i> Edit Capital</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($capital['commission_number']); ?></span>
            </div>
        </div>

        <!-- MESSAGES -->
        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo htmlspecialchars($success_message_session); ?></span>
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

        <!-- ORIGINAL INFO BAR -->
        <div class="original-info-bar">
            <div class="oib-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="oib-content">
                <span class="oib-label">Editing Existing Capital Entry</span>
                <span class="oib-text">
                    Original: <strong><?php echo formatCurrency($old_amount); ?></strong>
                    (<?php echo $old_target === 'float' ? 'Provider Float' : 'Branch Cash'; ?>)
                    added on <strong><?php echo date('d M Y', strtotime($capital['commission_date'])); ?></strong>
                </span>
            </div>
        </div>

        <!-- 4 SUMMARY CARDS -->
        <div class="capital-cards-grid-edit">
            <div class="cce-card cce-capital">
                <div class="cce-icon cce-icon-blue"><i class="fas fa-vault"></i></div>
                <div class="cce-content">
                    <span class="cce-label">CURRENT CAPITAL</span>
                    <span class="cce-value cce-blue"><?php echo formatCurrency($current_capital); ?></span>
                    <span class="cce-sub">Float + Cash</span>
                </div>
            </div>
            
            <div class="cce-card cce-available">
                <div class="cce-icon cce-icon-yellow"><i class="fas fa-chart-line"></i></div>
                <div class="cce-content">
                    <span class="cce-label">AVAILABLE PROFIT</span>
                    <span class="cce-value cce-yellow"><?php echo formatCurrency($available_profit); ?></span>
                    <span class="cce-sub">Max new amount</span>
                </div>
            </div>
            
            <div class="cce-card cce-original">
                <div class="cce-icon cce-icon-orange"><i class="fas fa-history"></i></div>
                <div class="cce-content">
                    <span class="cce-label">ORIGINAL AMOUNT</span>
                    <span class="cce-value cce-orange"><?php echo formatCurrency($old_amount); ?></span>
                    <span class="cce-sub">Before edit</span>
                </div>
            </div>
            
            <div class="cce-card cce-new">
                <div class="cce-icon cce-icon-purple"><i class="fas fa-rocket"></i></div>
                <div class="cce-content">
                    <span class="cce-label">NEW CAPITAL</span>
                    <span class="cce-value cce-purple" id="newCapitalDisplay"><?php echo formatCurrency($current_capital); ?></span>
                    <span class="cce-sub">After new amount</span>
                </div>
            </div>
        </div>

        <!-- FORM -->
        <div class="form-container">
            <form method="POST" action="" id="editCapitalForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_capital">
                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                <input type="hidden" name="available_profit" id="availableProfitInput" value="<?php echo $available_profit; ?>">
                <input type="hidden" name="current_capital" id="currentCapitalInput" value="<?php echo $current_capital; ?>">
                <input type="hidden" name="old_amount" id="oldAmountInput" value="<?php echo $old_amount; ?>">
                <input type="hidden" name="old_target" value="<?php echo htmlspecialchars($old_target); ?>">
                
                <!-- CAPITAL INFO -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Capital Information</h3>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="capital_date">Capital Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="capital_date" name="capital_date" 
                                       value="<?php echo htmlspecialchars($capital['commission_date']); ?>" 
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Branch</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <input type="text" value="<?php echo htmlspecialchars($capital['branch_name']); ?>" class="form-control" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- CAPITAL TARGET -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-bullseye"></i> Where to Add Capital?</h3>
                    </div>
                    
                    <div class="target-options">
                        <label class="target-card">
                            <input type="radio" name="capital_target" value="float" 
                                   onchange="onTargetChange('float')" 
                                   <?php echo ($old_target === 'float') ? 'checked' : ''; ?>>
                            <div class="target-content">
                                <div class="target-icon target-icon-float">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="target-text">
                                    <span class="target-title">Provider Float</span>
                                    <span class="target-desc">Add to one or more provider floats</span>
                                </div>
                            </div>
                        </label>
                        
                        <label class="target-card">
                            <input type="radio" name="capital_target" value="cash"
                                   onchange="onTargetChange('cash')"
                                   <?php echo ($old_target === 'cash') ? 'checked' : ''; ?>>
                            <div class="target-content">
                                <div class="target-icon target-icon-cash">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="target-text">
                                    <span class="target-title">Branch Cash</span>
                                    <span class="target-desc">Add to branch cash balance</span>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- PROVIDER SECTION -->
                <div class="form-section" id="providerSection" style="<?php echo ($old_target === 'float') ? 'display:block;' : 'display:none;'; ?>">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Amounts</h3>
                        <span class="section-badge">Check providers and enter amounts</span>
                    </div>
                    
                    <?php if (count($providers_list) > 0): ?>
                        <div class="providers-header-row">
                            <div class="ph-checkbox"></div>
                            <div class="ph-provider">Provider</div>
                            <div class="ph-code">Code</div>
                            <div class="ph-float">Current Float</div>
                            <div class="ph-amount">New Amount</div>
                        </div>
                        
                        <div class="providers-list">
                            <?php foreach ($providers_list as $p): 
                                $was_checked = isset($old_provider_amounts[$p['id']]);
                                $old_val = $was_checked ? $old_provider_amounts[$p['id']] : 0;
                                $old_val_formatted = $old_val > 0 ? number_format($old_val) : '';
                            ?>
                                <div class="provider-row <?php echo $was_checked ? 'checked' : ''; ?>">
                                    <div class="pr-checkbox">
                                        <input type="checkbox" 
                                               class="provider-checkbox" 
                                               data-provider-id="<?php echo $p['id']; ?>"
                                               id="chk_<?php echo $p['id']; ?>"
                                               onchange="onProviderCheck(this)"
                                               <?php echo $was_checked ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="pr-provider">
                                        <div class="provider-icon-small" style="background: <?php echo htmlspecialchars($p['color_code']); ?>;">
                                            <i class="<?php echo htmlspecialchars($p['icon_class']); ?>"></i>
                                        </div>
                                        <span class="provider-name-small"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                    </div>
                                    <div class="pr-code">
                                        <span class="code-pill"><?php echo htmlspecialchars($p['branch_provider_code'] ?? $p['main_code']); ?></span>
                                    </div>
                                    <div class="pr-float">
                                        <span class="float-pill"><?php echo formatCurrency($p['current_float']); ?></span>
                                    </div>
                                    <div class="pr-amount">
                                        <input type="text" 
                                               name="provider_amounts[<?php echo $p['id']; ?>]" 
                                               id="amt_<?php echo $p['id']; ?>"
                                               class="form-control amount-input" 
                                               placeholder="0"
                                               value="<?php echo $old_val_formatted; ?>"
                                               oninput="formatMoneyInput(this); updatePreview();"
                                               <?php echo $was_checked ? '' : 'disabled'; ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="providers-footer">
                            <div class="pf-selected">Selected: <strong id="selectedCount"><?php echo count($old_provider_amounts); ?></strong> / <?php echo count($providers_list); ?></div>
                            <div class="pf-total">New Total: <strong id="totalProvidersAmount"><?php echo formatCurrency($old_amount); ?></strong></div>
                        </div>
                    <?php else: ?>
                        <div class="empty-providers">
                            <i class="fas fa-info-circle"></i>
                            <p>No providers found for this branch.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- CASH SECTION -->
                <div class="form-section" id="cashSection" style="<?php echo ($old_target === 'cash') ? 'display:block;' : 'display:none;'; ?>">
                    <div class="section-header">
                        <h3><i class="fas fa-money-bill-wave"></i> New Cash Amount</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Amount to Add (TSh) <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="text" id="amount" name="amount" 
                                       class="form-control money-input" 
                                       placeholder="0"
                                       value="<?php echo ($old_target === 'cash') ? number_format($old_amount) : ''; ?>"
                                       oninput="formatMoneyInput(this); updatePreview();">
                            </div>
                            <small>Max: <strong><?php echo formatCurrency($available_profit); ?></strong></small>
                        </div>
                        <div class="form-group">
                            <label>Current Branch Cash</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-wallet"></i></span>
                                <input type="text" value="<?php echo formatCurrency($current_cash); ?>" class="form-control" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- NOTES -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-sticky-note"></i> Additional Notes</h3>
                    </div>
                    <div class="form-row">
                        <div class="form-group full-width">
                            <textarea id="notes" name="notes" class="form-control textarea-control" rows="3" placeholder="Additional notes (optional)..."><?php 
                                $clean_notes = str_replace('[CAPITAL ADDITION] To Provider Float - ', '', $capital['notes'] ?? '');
                                $clean_notes = str_replace('[CAPITAL ADDITION] To Branch Cash - ', '', $clean_notes);
                                $clean_notes = str_replace('[CAPITAL ADDITION] To Provider Float', '', $clean_notes);
                                $clean_notes = str_replace('[CAPITAL ADDITION] To Branch Cash', '', $clean_notes);
                                echo htmlspecialchars(trim($clean_notes));
                            ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- SUMMARY PREVIEW -->
                <div class="form-section summary-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Edit Summary</h3>
                    </div>
                    <div class="summary-preview">
                        <div class="summary-preview-item">
                            <span class="sp-label">Original Amount:</span>
                            <span class="sp-value sp-original"><?php echo formatCurrency($old_amount); ?></span>
                        </div>
                        <div class="summary-preview-item">
                            <span class="sp-label">New Amount:</span>
                            <span class="sp-value sp-amount" id="previewAmount"><?php echo formatCurrency($old_amount); ?></span>
                        </div>
                        <div class="summary-preview-item highlight">
                            <span class="sp-label">New Capital:</span>
                            <span class="sp-value sp-after" id="previewAfter"><?php echo formatCurrency($current_capital); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- ACTIONS -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Capital
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="view_capital.php?id=<?php echo $capital_id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; }
.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    padding: 16px 20px !important;
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
    --bg-hover: #f3f4f6;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --bg-hover: #2d3a4f;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* BRANCH CARD */
.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    border-radius: 12px; margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(245, 158, 11, 0.35);
    flex-wrap: wrap; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 50px; height: 50px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1; position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name { font-size: 18px; font-weight: 700; color: #FFFFFF; }
.branch-status-code {
    font-size: 11px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; padding: 0 4px; flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 20px; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.page-header-left h2 i { color: #F59E0B; margin-right: 8px; }
.page-subtitle {
    font-size: 13px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 3px 12px; border-radius: 12px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 8px;
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
    background: transparent; border: none;
    font-size: 22px; color: inherit; cursor: pointer;
    padding: 0 4px; opacity: 0.6;
}

/* ORIGINAL INFO BAR */
.original-info-bar {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    margin-bottom: 16px;
    color: #92400E;
    flex-wrap: wrap;
}
.original-info-bar .oib-icon {
    width: 44px; height: 44px;
    background: rgba(217, 119, 6, 0.15);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #D97706;
    flex-shrink: 0;
}
.oib-content { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.oib-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #92400E;
}
.oib-text { font-size: 13px; font-weight: 600; color: #78350F; }
.oib-text strong { font-weight: 800; font-family: 'Courier New', monospace; }
html.dark-mode .original-info-bar { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #F59E0B; color: #FCD34D; }
html.dark-mode .oib-label { color: #FCD34D; }
html.dark-mode .oib-text { color: #FEF3C7; }

/* 4 CARDS */
.capital-cards-grid-edit {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.cce-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    min-width: 0;
}
.cce-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 5px; height: 100%;
}
.cce-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px var(--shadow-hover); }
.cce-capital::before { background: #1D4ED8; }
.cce-available::before { background: #F59E0B; }
.cce-original::before { background: #EA580C; }
.cce-new::before { background: #7C3AED; }

.cce-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.cce-icon-blue { background: #DBEAFE; color: #1D4ED8; }
.cce-icon-yellow { background: #FEF3C7; color: #D97706; }
.cce-icon-orange { background: #FFEDD5; color: #EA580C; }
.cce-icon-purple { background: #EDE9FE; color: #7C3AED; }
html.dark-mode .cce-icon-blue { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .cce-icon-yellow { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .cce-icon-orange { background: #7C2D12; color: #FB923C; }
html.dark-mode .cce-icon-purple { background: #4C1D95; color: #A78BFA; }

.cce-content { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.cce-label {
    font-size: 10px; font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cce-value {
    font-size: 18px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
}
.cce-blue { color: #1D4ED8; }
.cce-yellow { color: #D97706; }
.cce-orange { color: #EA580C; }
.cce-purple { color: #7C3AED; }
html.dark-mode .cce-blue { color: #60A5FA; }
html.dark-mode .cce-yellow { color: #FBBF24; }
html.dark-mode .cce-orange { color: #FB923C; }
html.dark-mode .cce-purple { color: #A78BFA; }

.cce-sub {
    font-size: 10px; font-weight: 600;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

/* FORM CONTAINER */
.form-container {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px var(--shadow-color);
}
.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 16px;
    flex-wrap: wrap; gap: 8px;
}
.section-header h3 { font-size: 15px; font-weight: 700; color: var(--text-primary); margin: 0; }
.section-header h3 i { color: #F59E0B; margin-right: 8px; }
.section-badge {
    font-size: 11px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 2px 12px; border-radius: 12px;
}

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-row .full-width { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.form-group label .required { color: #DC2626; font-weight: 700; }
.input-group { position: relative; display: flex; align-items: center; }
.input-icon {
    position: absolute; left: 12px;
    color: var(--text-light); font-size: 14px;
    z-index: 1; pointer-events: none;
}
.form-control {
    width: 100%;
    padding: 10px 14px 10px 40px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--bg-input);
    color: var(--text-primary);
}
.form-control.textarea-control { padding: 12px 14px; min-height: 80px; resize: vertical; }
.form-control:focus {
    border-color: #F59E0B;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
}
.form-control:disabled { opacity: 0.7; cursor: not-allowed; }
.form-group small { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

.target-options { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.target-card { position: relative; display: block; cursor: pointer; border-radius: 12px; overflow: hidden; }
.target-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.target-content {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    transition: all 0.3s ease;
}
.target-card:hover .target-content { border-color: #FCD34D; }
.target-card input[type="radio"]:checked + .target-content {
    border-color: #F59E0B;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
}
html.dark-mode .target-card input[type="radio"]:checked + .target-content {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
}
.target-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.target-icon-float { background: #DBEAFE; color: #1D4ED8; }
.target-icon-cash { background: #D1FAE5; color: #059669; }
.target-text { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.target-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
.target-desc { font-size: 11px; color: var(--text-muted); line-height: 1.4; }

.providers-header-row {
    display: grid;
    grid-template-columns: 40px 2fr 1fr 1.2fr 1.5fr;
    gap: 12px;
    padding: 10px 14px;
    background: var(--bg-hover);
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-bottom: 8px;
}
.providers-list { display: flex; flex-direction: column; gap: 8px; }
.provider-row {
    display: grid;
    grid-template-columns: 40px 2fr 1fr 1.2fr 1.5fr;
    gap: 12px;
    padding: 12px 14px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    align-items: center;
    transition: all 0.3s ease;
}
.provider-row.checked {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    border-color: #FCD34D;
}
html.dark-mode .provider-row.checked {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    border-color: #F59E0B;
}
.pr-checkbox { display: flex; align-items: center; justify-content: center; }
.provider-checkbox {
    width: 20px; height: 20px;
    cursor: pointer;
    accent-color: #F59E0B;
}
.pr-provider { display: flex; align-items: center; gap: 10px; min-width: 0; }
.provider-icon-small {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 14px; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.provider-name-small {
    font-size: 13px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pr-code { display: flex; align-items: center; }
.code-pill {
    font-size: 10px; font-weight: 700;
    color: #1D4ED8; background: #DBEAFE;
    padding: 3px 10px; border-radius: 8px;
    font-family: 'Courier New', monospace;
}
html.dark-mode .code-pill { background: #1E3A5F; color: #60A5FA; }
.pr-float { display: flex; align-items: center; }
.float-pill {
    font-size: 12px; font-weight: 800;
    color: #059669; background: #D1FAE5;
    padding: 4px 10px; border-radius: 8px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .float-pill { background: #065F46; color: #34D399; }
.pr-amount { display: flex; align-items: center; }
.amount-input {
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    border: 2px solid var(--border-color);
    font-size: 13px;
    font-weight: 700;
    text-align: right;
    font-family: 'Courier New', monospace;
    background: var(--bg-card);
    color: var(--text-primary);
    outline: none;
}
.amount-input:focus { border-color: #F59E0B; }
.amount-input:disabled { opacity: 0.4; cursor: not-allowed; background: var(--bg-hover); }

.providers-footer {
    display: flex; justify-content: space-between;
    align-items: center; margin-top: 14px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    border-radius: 10px;
    border: 1.5px solid #FCD34D;
    flex-wrap: wrap; gap: 10px;
}
html.dark-mode .providers-footer { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #F59E0B; }
.pf-selected, .pf-total { font-size: 13px; font-weight: 600; color: #92400E; }
.pf-selected strong, .pf-total strong { font-weight: 800; color: #78350F; font-size: 15px; }

.empty-providers {
    text-align: center; padding: 30px 20px;
    color: var(--text-muted);
    background: var(--bg-input);
    border-radius: 10px;
    border: 1px dashed var(--border-color);
}

.summary-section { background: var(--bg-hover); }
.summary-preview { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.summary-preview-item {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 14px 18px;
    display: flex; flex-direction: column; gap: 4px;
    border: 1px solid var(--border-color);
}
.summary-preview-item.highlight {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    border-color: #FCD34D;
}
html.dark-mode .summary-preview-item.highlight { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #F59E0B; }
.sp-label {
    font-size: 11px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
}
.sp-value {
    font-size: 17px; font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    color: var(--text-primary);
    word-break: break-word;
}
.sp-original { color: #EA580C; }
.sp-amount { color: #F59E0B; }
.sp-after { color: #7C3AED; }

.form-actions {
    display: flex; gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 10px 24px; border-radius: 8px;
    font-weight: 600; font-size: 14px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
}
.btn-submit { background: #F59E0B; color: white; }
.btn-submit:hover { background: #D97706; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); }
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-reset, .btn-cancel {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-reset:hover { background: var(--border-color); }
.btn-cancel:hover { background: #FEE2E2; color: #991B1B; border-color: #FECACA; }
html.dark-mode .btn-cancel:hover { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

/* RESPONSIVE */
@media (max-width: 1200px) {
    .capital-cards-grid-edit { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1024px) {
    .cce-value { font-size: 16px; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .capital-cards-grid-edit { grid-template-columns: 1fr; }
    .target-options { grid-template-columns: 1fr; }
    .summary-preview { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .form-row .full-width { grid-column: span 1; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { justify-content: center; width: 100%; }
    .providers-header-row { display: none; }
    .provider-row { grid-template-columns: 40px 1fr; gap: 8px; }
    .pr-provider { grid-column: 2; }
    .pr-code, .pr-float, .pr-amount { grid-column: 2; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .cce-value { font-size: 15px; }
    .form-section { padding: 16px 14px; }
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

function onTargetChange(target) {
    var providerSection = document.getElementById('providerSection');
    var cashSection = document.getElementById('cashSection');
    if (target === 'float') {
        providerSection.style.display = 'block';
        cashSection.style.display = 'none';
    } else {
        providerSection.style.display = 'none';
        cashSection.style.display = 'block';
    }
    updatePreview();
}

function onProviderCheck(checkbox) {
    var providerId = checkbox.getAttribute('data-provider-id');
    var amountInput = document.getElementById('amt_' + providerId);
    var row = checkbox.closest('.provider-row');
    
    if (checkbox.checked) {
        amountInput.disabled = false;
        row.classList.add('checked');
        amountInput.focus();
    } else {
        amountInput.disabled = true;
        amountInput.value = '';
        row.classList.remove('checked');
    }
    updatePreview();
}

function updatePreview() {
    var currentCapital = parseFloat(document.getElementById('currentCapitalInput').value) || 0;
    var oldAmount = parseFloat(document.getElementById('oldAmountInput').value) || 0;
    var target = document.querySelector('input[name="capital_target"]:checked');
    var newTotal = 0;
    var selectedCount = 0;
    
    if (target && target.value === 'float') {
        document.querySelectorAll('.provider-checkbox:checked').forEach(function(chk) {
            var providerId = chk.getAttribute('data-provider-id');
            var amountInput = document.getElementById('amt_' + providerId);
            if (amountInput && amountInput.value) {
                var amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
                newTotal += amount;
                selectedCount++;
            }
        });
        document.getElementById('selectedCount').textContent = selectedCount;
        document.getElementById('totalProvidersAmount').textContent = 'TSh ' + newTotal.toLocaleString('en-US');
    } else {
        var amountInput = document.getElementById('amount');
        if (amountInput && amountInput.value) {
            newTotal = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
        }
    }
    
    // Update preview amount
    var amountEl = document.getElementById('previewAmount');
    if (amountEl) amountEl.textContent = 'TSh ' + newTotal.toLocaleString('en-US');
    
    // Calculate new capital = current - old + new
    var newCapital = currentCapital - oldAmount + newTotal;
    var newCapitalEl = document.getElementById('newCapitalDisplay');
    if (newCapitalEl) {
        newCapitalEl.textContent = 'TSh ' + newCapital.toLocaleString('en-US');
    }
    
    var previewAfterEl = document.getElementById('previewAfter');
    if (previewAfterEl) {
        previewAfterEl.textContent = 'TSh ' + newCapital.toLocaleString('en-US');
    }
}

function validateForm() {
    var target = document.querySelector('input[name="capital_target"]:checked');
    if (!target) { alert('Please select where to add capital.'); return false; }
    
    var available = parseFloat(document.getElementById('availableProfitInput').value) || 0;
    var newTotal = 0;
    
    if (target.value === 'float') {
        var hasSelection = false;
        document.querySelectorAll('.provider-checkbox:checked').forEach(function(chk) {
            var providerId = chk.getAttribute('data-provider-id');
            var amountInput = document.getElementById('amt_' + providerId);
            if (amountInput && amountInput.value) {
                var amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
                if (amount > 0) { hasSelection = true; newTotal += amount; }
            }
        });
        if (!hasSelection) {
            alert('Please select at least one provider and enter an amount.');
            return false;
        }
    } else {
        var amountInput = document.getElementById('amount');
        if (amountInput && amountInput.value) {
            newTotal = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
        }
        if (newTotal <= 0) {
            alert('Please enter a valid amount.');
            amountInput.focus();
            return false;
        }
    }
    
    if (newTotal > available) {
        alert('The new total amount (' + newTotal.toLocaleString() + ' TSh) exceeds available profit (' + available.toLocaleString() + ' TSh).');
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAny unsaved changes will be lost.');
}

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
});
</script>
</body>
</html>