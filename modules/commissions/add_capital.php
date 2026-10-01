<?php
// ================================================================
// FILE: modules/commissions/add_capital.php
// WAKALA FINANCIAL SYSTEM - ADD CAPITAL
// ✅ GREEN THEME + BEAUTIFUL CARDS
// ✅ Providers grid 3-per-row
// ✅ Cash + Providers PAMOJA (shared pool)
// ✅ Available Capital per provider (commission reference)
// ✅ LIVE FILTER: Available Profit ina-update unapoingiza
// ✅ History table with avatar + name + code
// ✅ ALL TEXT IN ENGLISH
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

// ============================================================
// HELPER: Calculate TOTAL FLOAT from LATEST record per provider
// ============================================================
function calculateTotalFloatFromReport($db, $daily_report_id) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(latest.current_float), 0) as total_float
        FROM (
            SELECT drp1.provider_id, drp1.current_float
            FROM daily_report_providers drp1
            INNER JOIN (
                SELECT provider_id, MAX(id) as max_id
                FROM daily_report_providers
                WHERE daily_report_id = ?
                GROUP BY provider_id
            ) drp2 ON drp1.id = drp2.max_id
        ) latest
    ");
    $stmt->execute([$daily_report_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return floatval($result['total_float'] ?? 0);
}

// ============================================================
// HANDLE DELETE CAPITAL ENTRY
// ============================================================
if (isset($_GET['delete_capital']) && !empty($_GET['delete_capital'])) {
    try {
        $delete_id = intval($_GET['delete_capital']);
        
        $stmt = $db->prepare("SELECT * FROM commissions WHERE id = ? AND commission_number LIKE 'CAP-%'");
        $stmt->execute([$delete_id]);
        $capital_entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$capital_entry) {
            throw new Exception('Capital entry not found.');
        }
        
        $branch_id_to_delete = intval($capital_entry['branch_id']);
        $amount_to_remove = floatval($capital_entry['allocated_amount']);
        $provider_data_decoded = json_decode($capital_entry['provider_data'] ?? '{}', true);
        $capital_target = $provider_data_decoded['capital_target'] ?? 'float';
        
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            SELECT id, current_cash, current_capital, current_float
            FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id_to_delete]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest_dr) {
            $dr_id = $latest_dr['id'];
            
            if (isset($provider_data_decoded['entries']) && is_array($provider_data_decoded['entries'])) {
                foreach ($provider_data_decoded['entries'] as $entry) {
                    if (!isset($entry['provider_id'])) continue;
                    
                    $pid = intval($entry['provider_id']);
                    $amt = floatval($entry['amount']);
                    
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
            }
            
            $new_total_float = calculateTotalFloatFromReport($db, $dr_id);
            $current_cash = floatval($latest_dr['current_cash']);
            $new_capital = $new_total_float + $current_cash;
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_float = ?, current_capital = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_total_float, $new_capital, $dr_id]);
        }
        
        $stmt = $db->prepare("DELETE FROM commissions WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        $db->commit();
        
        logActivity(
            $user_id, 
            'Delete Capital', 
            'Commissions', 
            $delete_id, 
            '', 
            'Deleted capital entry: ' . $capital_entry['commission_number'] . ' - ' . formatCurrency($amount_to_remove)
        );
        
        $_SESSION['success_message'] = 'Capital entry deleted successfully! ' . formatCurrency($amount_to_remove) . ' removed.';
        header('Location: add_capital.php?branch_id=' . $branch_id_to_delete);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        header('Location: add_capital.php');
        exit();
    }
}

// ============================================================
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

unset($_SESSION['selected_branch']);

$add_branch_name = 'All Branches';
$add_branch_code = '';
$add_branch_location = '';
if ($selected_branch > 0) {
    foreach ($all_branches as $b) {
        if ($b['id'] == $selected_branch) {
            $add_branch_name = $b['branch_name'];
            $add_branch_code = $b['branch_code'];
            $add_branch_location = $b['location'] ?? '';
            break;
        }
    }
}

// ============================================================
// CALCULATE ALL VALUES FOR BRANCH
// ============================================================
$total_commission = 0;
$total_other_income = 0;
$total_expenses = 0;
$total_salaries = 0;
$total_capital_added = 0;
$available_profit = 0;

$current_branch_float = 0;
$current_branch_cash = 0;
$current_branch_capital = 0;

if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_commission), 0) as total FROM commissions WHERE branch_id = ?");
    $stmt->execute([$selected_branch]);
    $total_commission = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    $stmt = $db->prepare("SELECT COALESCE(SUM(other_income), 0) as total FROM commissions WHERE branch_id = ?");
    $stmt->execute([$selected_branch]);
    $total_other_income = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE branch_id = ? AND is_business_expense = 1");
        $stmt->execute([$selected_branch]);
        $total_expenses = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    } catch (Exception $e) { }
    
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(net_pay), 0) as total FROM employee_salaries WHERE branch_id = ? AND status = 'paid'");
        $stmt->execute([$selected_branch]);
        $total_salaries = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    } catch (Exception $e) { }
    
    $total_earnings = ($total_commission + $total_other_income) - ($total_expenses + $total_salaries);
    
    $stmt = $db->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total FROM commissions WHERE branch_id = ? AND commission_number LIKE 'CAP-%'");
    $stmt->execute([$selected_branch]);
    $total_capital_added = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    $available_profit = $total_earnings - $total_capital_added;
    if ($available_profit < 0) $available_profit = 0;
    
    $stmt = $db->prepare("
        SELECT id, current_cash, current_capital, current_float
        FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_branch]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dr) {
        $current_branch_cash = floatval($dr['current_cash'] ?? 0);
        $current_branch_float = calculateTotalFloatFromReport($db, $dr['id']);
    }
    
    $current_branch_capital = $current_branch_float + $current_branch_cash;
}

// ============================================================
// GET ALL CAPITAL ADDITIONS (CAP- entries)
// ============================================================
$capital_additions = [];

$sql = "SELECT 
            c.id,
            c.commission_number,
            c.commission_date,
            c.provider_data,
            c.allocated_amount,
            c.notes,
            c.created_at,
            c.branch_id,
            emp.full_name as employee_name,
            emp.employee_id as employee_code,
            emp.profile_pic as employee_avatar,
            b.branch_name as branch_name,
            b.branch_code as branch_code
        FROM commissions c
        LEFT JOIN employees emp ON c.employee_id = emp.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE c.commission_number LIKE 'CAP-%'";

$params_cap = [];
if ($selected_branch > 0) {
    $sql .= " AND c.branch_id = ?";
    $params_cap[] = $selected_branch;
}

$sql .= " ORDER BY c.commission_date DESC, c.id DESC";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params_cap);
    $capital_additions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $capital_additions = [];
}

$total_capital_additions_count = count($capital_additions);

// ============================================================
// GET ALL PROVIDERS FOR SELECTED BRANCH
// ✅ Pia tunachukua commission ya kila provider
// ============================================================
$providers_list = [];
$provider_commissions = [];

if ($selected_branch > 0) {
    // Get latest daily report
    $stmt = $db->prepare("
        SELECT id FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_branch]);
    $latest_dr_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $latest_dr_id = $latest_dr_info ? $latest_dr_info['id'] : 0;
    
    // Get providers with float
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
                (SELECT drp.current_float 
                 FROM daily_report_providers drp
                 WHERE drp.daily_report_id = ? AND drp.provider_id = p.id
                 ORDER BY drp.id DESC LIMIT 1),
                0
            ) as current_float
        FROM providers p
        INNER JOIN branch_providers bp ON p.id = bp.provider_id
        WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
        ORDER BY p.display_order, p.provider_name
    ");
    $stmt->execute([$latest_dr_id, $selected_branch]);
    $providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ✅ Get commission per provider (from provider_data JSON)
    $stmt = $db->prepare("
        SELECT provider_data
        FROM commissions 
        WHERE branch_id = ? 
          AND (commission_number NOT LIKE 'CAP-%' OR commission_number IS NULL)
    ");
    $stmt->execute([$selected_branch]);
    $commission_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($commission_rows as $cr) {
        $data = json_decode($cr['provider_data'] ?? '{}', true);
        if (is_array($data)) {
            foreach ($data as $pid => $amt) {
                $pid = intval($pid);
                $amt = floatval($amt);
                if ($pid > 0 && $amt > 0) {
                    if (!isset($provider_commissions[$pid])) {
                        $provider_commissions[$pid] = 0;
                    }
                    $provider_commissions[$pid] += $amt;
                }
            }
        }
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_capital') {
    try {
        $capital_date = $_POST['capital_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        // Recalculate available
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
        
        $stmt = $db->prepare("SELECT COALESCE(SUM(allocated_amount), 0) as total FROM commissions WHERE branch_id = ? AND commission_number LIKE 'CAP-%'");
        $stmt->execute([$branch_id]);
        $already_added = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        
        $available = $earnings - $already_added;
        if ($available < 0) $available = 0;
        
        $branch_name = '';
        foreach ($all_branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            SELECT id, current_float, current_cash, current_capital 
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
        $grand_total_added = 0;
        $entries_added = [];
        $cash_amount_submitted = floatval(str_replace(',', '', $_POST['cash_amount'] ?? 0));
        $provider_amounts = $_POST['provider_amounts'] ?? [];
        
        // ✅ PROCESS CASH FIRST
        if ($cash_amount_submitted > 0) {
            if ($cash_amount_submitted > $available) {
                throw new Exception('Cash amount exceeds available profit (' . formatCurrency($available) . ').');
            }
            
            $new_cash = floatval($latest_dr['current_cash']) + $cash_amount_submitted;
            $new_total_float = calculateTotalFloatFromReport($db, $dr_id);
            $new_capital = $new_total_float + $new_cash;
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = ?, current_float = ?, current_capital = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$new_cash, $new_total_float, $new_capital, $dr_id]);
            
            $grand_total_added += $cash_amount_submitted;
            $entries_added[] = [
                'type' => 'cash',
                'name' => 'Branch Cash',
                'amount' => $cash_amount_submitted
            ];
            
            $latest_dr['current_cash'] = $new_cash;
        }
        
        // ✅ PROCESS PROVIDERS
        if (!empty($provider_amounts)) {
            foreach ($provider_amounts as $provider_id => $amount_raw) {
                $provider_id = intval($provider_id);
                $amount = floatval(str_replace(',', '', $amount_raw));
                
                if ($amount <= 0 || $provider_id <= 0) continue;
                
                $stmt = $db->prepare("
                    SELECT p.provider_name, bp.provider_code 
                    FROM providers p
                    INNER JOIN branch_providers bp ON p.id = bp.provider_id AND bp.branch_id = ?
                    WHERE p.id = ?
                ");
                $stmt->execute([$branch_id, $provider_id]);
                $provider_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$provider_info) continue;
                
                if (($grand_total_added + $amount) > $available) {
                    throw new Exception(
                        'Total amount exceeds available profit (' . formatCurrency($available) . ').'
                    );
                }
                
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
                    $stmt->execute([$dr_id, $provider_id, $provider_info['provider_code'], $provider_info['provider_name'], $amount]);
                }
                
                $grand_total_added += $amount;
                $entries_added[] = [
                    'type' => 'provider',
                    'provider_id' => $provider_id,
                    'provider_name' => $provider_info['provider_name'],
                    'amount' => $amount
                ];
            }
            
            // ✅ Recalculate float
            $new_total_float = calculateTotalFloatFromReport($db, $dr_id);
            $new_cash = floatval($latest_dr['current_cash']);
            $new_capital = $new_total_float + $new_cash;
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_float = ?, current_capital = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$new_total_float, $new_capital, $dr_id]);
        }
        
        if ($grand_total_added <= 0) {
            throw new Exception('Please enter at least one amount (Cash or Provider).');
        }
        
        // Determine main target for display
        $capital_target = (count($entries_added) === 1 && $entries_added[0]['type'] === 'cash') ? 'cash' : 'float';
        
        $commission_number = 'CAP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $provider_json = json_encode([
            'capital_target' => $capital_target,
            'entries' => $entries_added
        ]);
        
        $notes_combined = '[CAPITAL ADDITION]';
        if (!empty($notes)) {
            $notes_combined .= ' - ' . $notes;
        }
        
        $stmt = $db->prepare("INSERT INTO commissions 
            (commission_number, employee_id, branch, branch_id, commission_date, provider_data, 
             total_commission, other_income, total_business_income, allocate_to_capital, allocated_amount, notes) 
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 'yes', ?, ?)");
        
        $stmt->execute([
            $commission_number,
            $user_id,
            $branch_name,
            $branch_id,
            $capital_date,
            $provider_json,
            $grand_total_added,
            $notes_combined
        ]);
        
        $capital_id = $db->lastInsertId();
        
        $db->commit();
        
        logActivity($user_id, 'Add Capital', 'Commissions', $capital_id, '', 
            'Added capital: ' . $commission_number . ' - ' . formatCurrency($grand_total_added));
        
        $_SESSION['success_message'] = 'Capital of ' . formatCurrency($grand_total_added) . ' added successfully!';
        header('Location: add_capital.php?branch_id=' . $branch_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH STATUS CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">
                    <?php echo $selected_branch > 0 ? 'Adding Capital For' : 'Select Branch to Add Capital'; ?>
                </span>
                <span class="branch-status-name"><?php echo htmlspecialchars($add_branch_name); ?></span>
                <?php if ($selected_branch > 0 && !empty($add_branch_code)): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($add_branch_code); ?></span>
                <?php endif; ?>
            </div>
            <a href="index.php<?php echo $selected_branch > 0 ? '?branch_id=' . $selected_branch : ''; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Capital</h2>
                <span class="page-subtitle">Add capital from available profit</span>
            </div>
        </div>

        <!-- MESSAGES -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($selected_branch == 0): ?>
            <!-- NO BRANCH SELECTED -->
            <div class="no-branch-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Please select a branch first</strong>
                    <p>Capital is added to a specific branch. Select a branch below to continue.</p>
                </div>
            </div>
            
            <div class="form-container">
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-store-alt"></i> Select Branch</h3>
                    </div>
                    <div class="branch-selection-grid">
                        <?php foreach ($all_branches as $br): ?>
                            <a href="?branch_id=<?php echo $br['id']; ?>" class="branch-select-card">
                                <div class="branch-select-icon">
                                    <i class="fas fa-store-alt"></i>
                                </div>
                                <div class="branch-select-info">
                                    <span class="branch-select-name"><?php echo htmlspecialchars($br['branch_name']); ?></span>
                                    <?php if (!empty($br['branch_code'])): ?>
                                        <span class="branch-select-code"><?php echo htmlspecialchars($br['branch_code']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <i class="fas fa-arrow-right branch-select-arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
        <?php else: ?>
            
            <!-- ============================================================
                 ✅ 4 CARDS INSIDE ONE MAIN CARD - GREEN THEME
                 ============================================================ -->
            <div class="capital-summary-wrapper">
                <div class="capital-summary-header">
                    <div class="csh-left">
                        <div class="csh-icon"><i class="fas fa-vault"></i></div>
                        <div class="csh-info">
                            <span class="csh-title">Capital Overview</span>
                            <span class="csh-subtitle">Branch: <?php echo htmlspecialchars($add_branch_name); ?></span>
                        </div>
                    </div>
                    <div class="csh-badge">
                        <i class="fas fa-check-circle"></i> Max to add: <span id="maxToAddBadge"><?php echo formatCurrency($available_profit); ?></span>
                    </div>
                </div>
                
                <div class="capital-cards-inside">
                    <div class="cc-inner card-inner-capital">
                        <div class="cci-header">
                            <div class="cci-icon cci-icon-blue"><i class="fas fa-vault"></i></div>
                            <span class="cci-label">CAPITAL</span>
                        </div>
                        <div class="cci-value cci-value-blue"><?php echo formatCurrency($current_branch_capital); ?></div>
                        <div class="cci-sublabel"><i class="fas fa-calculator"></i> Float + Cash</div>
                    </div>
                    
                    <!-- ✅ AVAILABLE PROFIT - LIVE FILTER -->
                    <div class="cc-inner card-inner-available" id="availableProfitCard">
                        <div class="cci-header">
                            <div class="cci-icon cci-icon-yellow"><i class="fas fa-chart-line"></i></div>
                            <span class="cci-label">AVAILABLE PROFIT</span>
                        </div>
                        <div class="cci-value cci-value-yellow" id="availableProfitDisplay"><?php echo formatCurrency($available_profit); ?></div>
                        <div class="cci-sublabel">
                            <i class="fas fa-bolt"></i> 
                            <span id="availableSublabel">Max to add</span>
                        </div>
                    </div>
                    
                    <div class="cc-inner card-inner-added">
                        <div class="cci-header">
                            <div class="cci-icon cci-icon-green"><i class="fas fa-arrow-up"></i></div>
                            <span class="cci-label">CAPITAL ADDED</span>
                        </div>
                        <div class="cci-value cci-value-green"><?php echo formatCurrency($total_capital_added); ?></div>
                        <div class="cci-sublabel"><i class="fas fa-plus"></i> Already added</div>
                    </div>
                    
                    <div class="cc-inner card-inner-after">
                        <div class="cci-header">
                            <div class="cci-icon cci-icon-purple"><i class="fas fa-rocket"></i></div>
                            <span class="cci-label">CAPITAL AFTER ADD</span>
                        </div>
                        <div class="cci-value cci-value-purple" id="capitalAfterDisplay"><?php echo formatCurrency($current_branch_capital); ?></div>
                        <div class="cci-sublabel"><i class="fas fa-arrow-right"></i> After new capital</div>
                    </div>
                </div>
            </div>
            
            <!-- BREAKDOWN CARDS -->
            <div class="breakdown-grid">
                <div class="breakdown-card bd-commission">
                    <div class="bd-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="bd-content">
                        <span class="bd-label">Commissions</span>
                        <span class="bd-value"><?php echo formatCurrency($total_commission); ?></span>
                    </div>
                </div>
                <div class="breakdown-card bd-other">
                    <div class="bd-icon"><i class="fas fa-coins"></i></div>
                    <div class="bd-content">
                        <span class="bd-label">Other Income</span>
                        <span class="bd-value"><?php echo formatCurrency($total_other_income); ?></span>
                    </div>
                </div>
                <div class="breakdown-card bd-expense">
                    <div class="bd-icon"><i class="fas fa-receipt"></i></div>
                    <div class="bd-content">
                        <span class="bd-label">Expenses</span>
                        <span class="bd-value">- <?php echo formatCurrency($total_expenses); ?></span>
                    </div>
                </div>
                <div class="breakdown-card bd-salary">
                    <div class="bd-icon"><i class="fas fa-users"></i></div>
                    <div class="bd-content">
                        <span class="bd-label">Salaries</span>
                        <span class="bd-value">- <?php echo formatCurrency($total_salaries); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================
                 ✅ MAIN FORM - CASH + PROVIDERS PAMOJA
                 ============================================================ -->
            <div class="form-container">
                <form method="POST" action="" class="main-form" id="capitalForm" onsubmit="return validateForm()">
                    <input type="hidden" name="action" value="add_capital">
                    <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                    <input type="hidden" name="available_profit" id="availableProfitInput" value="<?php echo $available_profit; ?>">
                    <input type="hidden" name="current_capital" id="currentCapitalInput" value="<?php echo $current_branch_capital; ?>">
                    <input type="hidden" name="current_cash" id="currentCashInput" value="<?php echo $current_branch_cash; ?>">
                    
                    <!-- CAPITAL INFORMATION -->
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
                                    <input type="date" id="capital_date" name="capital_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Branch</label>
                                <div class="input-group">
                                    <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                    <input type="text" value="<?php echo htmlspecialchars($add_branch_name); ?>" class="form-control" disabled>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ✅ AVAILABLE PROFIT INFO BANNER -->
                    <div class="form-section available-profit-banner-section">
                        <div class="available-profit-banner">
                            <div class="apb-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="apb-content">
                                <span class="apb-label">Available Profit (Shared Pool)</span>
                                <span class="apb-value" id="apbRemainingDisplay"><?php echo formatCurrency($available_profit); ?></span>
                                <span class="apb-sub">This is the maximum you can distribute between Cash and Providers</span>
                            </div>
                            <div class="apb-badge">
                                <i class="fas fa-bolt"></i>
                                <span id="apbUsedBadge">Used: TSh 0</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ✅ CASH SECTION - PAMOJA NA PROVIDERS -->
                    <div class="form-section cash-section-container">
                        <div class="section-header">
                            <h3><i class="fas fa-money-bill-wave"></i> Add to Branch Cash (Optional)</h3>
                            <span class="section-badge">
                                Current Cash: <?php echo formatCurrency($current_branch_cash); ?>
                            </span>
                        </div>
                        
                        <div class="cash-input-wrapper">
                            <label for="cash_amount" class="cash-input-label">
                                <i class="fas fa-plus-circle"></i> Cash Amount (TSh)
                            </label>
                            <div class="cash-input-group">
                                <span class="cash-currency">TSh</span>
                                <input type="text" 
                                       id="cash_amount" 
                                       name="cash_amount" 
                                       class="cash-input-field" 
                                       placeholder="0" 
                                       oninput="formatMoneyInput(this); updatePreview();">
                            </div>
                            <div class="cash-input-hint">
                                <i class="fas fa-info-circle"></i>
                                Leave empty if you only want to add to Provider Float
                            </div>
                        </div>
                    </div>
                    
                    <!-- ✅ PROVIDERS SECTION - GRID 3-COL -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-university"></i> Add to Provider Float (Optional)</h3>
                            <span class="section-badge">
                                <?php echo count($providers_list); ?> providers • Check providers and enter amounts
                            </span>
                        </div>
                        
                        <?php if (count($providers_list) > 0): ?>
                            <div class="providers-cards-grid">
                                <?php foreach ($providers_list as $p): 
                                    $commission = $provider_commissions[$p['id']] ?? 0;
                                ?>
                                    <div class="provider-card-item" data-provider-id="<?php echo $p['id']; ?>">
                                        <div class="pc-checkbox-wrapper">
                                            <input type="checkbox" 
                                                   class="provider-checkbox" 
                                                   data-provider-id="<?php echo $p['id']; ?>"
                                                   id="chk_<?php echo $p['id']; ?>"
                                                   onchange="onProviderCheck(this)">
                                            <label for="chk_<?php echo $p['id']; ?>" class="pc-check-label"></label>
                                        </div>
                                        
                                        <div class="pc-header">
                                            <div class="pc-icon" style="background: <?php echo htmlspecialchars($p['color_code']); ?>;">
                                                <i class="<?php echo htmlspecialchars($p['icon_class']); ?>"></i>
                                            </div>
                                            <div class="pc-info">
                                                <span class="pc-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                                <span class="pc-code"><?php echo htmlspecialchars($p['branch_provider_code'] ?? $p['main_code']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="pc-float-row">
                                            <span class="pc-float-label">
                                                <i class="fas fa-coins"></i> Float:
                                            </span>
                                            <span class="pc-float-value"><?php echo formatCurrency($p['current_float']); ?></span>
                                        </div>
                                        
                                        <div class="pc-available-row">
                                            <span class="pc-available-label">
                                                <i class="fas fa-chart-line"></i> Commission:
                                            </span>
                                            <span class="pc-available-value"><?php echo formatCurrency($commission); ?></span>
                                        </div>
                                        
                                        <div class="pc-input-wrapper">
                                            <span class="pc-currency">TSh</span>
                                            <input type="text" 
                                                   name="provider_amounts[<?php echo $p['id']; ?>]" 
                                                   id="amt_<?php echo $p['id']; ?>"
                                                   class="pc-input amount-input" 
                                                   placeholder="0"
                                                   disabled
                                                   oninput="formatMoneyInput(this); updatePreview();">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="providers-footer">
                                <div class="pf-selected">
                                    <i class="fas fa-check-circle"></i>
                                    Selected: <strong id="selectedCount">0</strong> / <?php echo count($providers_list); ?>
                                </div>
                                <div class="pf-total">
                                    <i class="fas fa-calculator"></i>
                                    Total: <strong id="totalProvidersAmount">TSh 0</strong>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-providers">
                                <i class="fas fa-info-circle"></i>
                                <p>No providers found for this branch.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- NOTES -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-sticky-note"></i> Additional Notes</h3>
                        </div>
                        <div class="form-row">
                            <div class="form-group full-width">
                                <textarea id="notes" name="notes" class="form-control textarea-control" rows="3" placeholder="Additional notes (optional)..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ✅ SUMMARY - LIVE -->
                    <div class="form-section summary-section">
                        <div class="section-header">
                            <h3><i class="fas fa-calculator"></i> Live Summary</h3>
                        </div>
                        <div class="summary-preview">
                            <div class="summary-preview-item">
                                <span class="sp-label">Current Capital:</span>
                                <span class="sp-value"><?php echo formatCurrency($current_branch_capital); ?></span>
                            </div>
                            <div class="summary-preview-item">
                                <span class="sp-label">Cash Adding:</span>
                                <span class="sp-value sp-amount" id="previewCash">TSh 0</span>
                            </div>
                            <div class="summary-preview-item">
                                <span class="sp-label">Providers Adding:</span>
                                <span class="sp-value sp-amount" id="previewProviders">TSh 0</span>
                            </div>
                            <div class="summary-preview-item highlight">
                                <span class="sp-label">Total Adding:</span>
                                <span class="sp-value sp-total" id="previewTotal">TSh 0</span>
                            </div>
                            <div class="summary-preview-item highlight-purple">
                                <span class="sp-label">Capital After Add:</span>
                                <span class="sp-value sp-after" id="previewAfter"><?php echo formatCurrency($current_branch_capital); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ACTIONS -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit" id="submitBtn">
                            <i class="fas fa-save"></i> Save Capital
                        </button>
                        <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <a href="index.php?branch_id=<?php echo $selected_branch; ?>" class="btn btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- ============================================================
                 ✅ CAPITAL ADDITIONS HISTORY TABLE
                 ============================================================ -->
            <div class="table-container-history">
                
                <div class="history-header-row">
                    <div class="hhr-left">
                        <div class="history-search-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   id="historySearchInput" 
                                   placeholder="Search..."
                                   oninput="filterHistory(this)">
                            <button type="button" id="historySearchClear" onclick="clearHistorySearch()" style="display:none;">
                                <i class="fas fa-times"></i>
                            </button>
                            <span class="history-search-count" id="historySearchCount" style="display:none;">0</span>
                        </div>
                    </div>
                    
                    <div class="hhr-center">
                        <button type="button" class="scroll-btn-history" onclick="scrollHistoryTable('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="scroll-label-history">
                            <i class="fas fa-arrows-alt-h"></i> SCROLL
                        </span>
                        <button type="button" class="scroll-btn-history" onclick="scrollHistoryTable('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    
                    <div class="hhr-right">
                        <span class="history-count-badge">
                            <i class="fas fa-history"></i>
                            <?php echo $total_capital_additions_count; ?> record(s)
                        </span>
                    </div>
                </div>

                <?php if ($total_capital_additions_count > 0): ?>
                    <div class="history-table-wrapper" id="historyTableWrapper">
                        <table class="history-table" id="capitalHistoryTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Branch</th>
                                    <th>Added By</th>
                                    <th>Target</th>
                                    <th class="text-right">Amount</th>
                                    <th>Notes</th>
                                    <th style="width: 130px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $h_counter = 1;
                                foreach ($capital_additions as $cap): 
                                    $target_label = 'Unknown';
                                    $target_class = 'default';
                                    $provider_data_decoded = json_decode($cap['provider_data'] ?? '{}', true);
                                    if (isset($provider_data_decoded['capital_target'])) {
                                        if ($provider_data_decoded['capital_target'] === 'float') {
                                            $target_label = 'Provider Float';
                                            $target_class = 'float';
                                        } elseif ($provider_data_decoded['capital_target'] === 'cash') {
                                            $target_label = 'Branch Cash';
                                            $target_class = 'cash';
                                        }
                                    }
                                    
                                    $history_search = strtolower(
                                        $cap['commission_number'] . ' ' .
                                        ($cap['employee_name'] ?? '') . ' ' .
                                        ($cap['employee_code'] ?? '') . ' ' .
                                        ($cap['branch_name'] ?? '') . ' ' .
                                        $cap['commission_date'] . ' ' .
                                        $target_label . ' ' .
                                        ($cap['notes'] ?? '')
                                    );
                                    
                                    $emp_avatar = $cap['employee_avatar'] ?? '';
                                    $emp_initial = strtoupper(substr($cap['employee_name'] ?? 'N', 0, 1));
                                ?>
                                    <tr class="history-row-item" data-search="<?php echo htmlspecialchars($history_search); ?>">
                                        <td><span class="row-num-history"><?php echo $h_counter++; ?></span></td>
                                        <td>
                                            <span class="reference-badge">
                                                <i class="fas fa-hashtag"></i>
                                                <?php echo htmlspecialchars($cap['commission_number']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="date-cell-history">
                                                <i class="far fa-calendar"></i>
                                                <?php echo date('d M Y', strtotime($cap['commission_date'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="branch-badge-history">
                                                <i class="fas fa-store-alt"></i>
                                                <?php echo htmlspecialchars($cap['branch_name'] ?? 'Main'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="employee-cell">
                                                <?php if ($emp_avatar && file_exists('../../' . $emp_avatar)): ?>
                                                    <img src="../../<?php echo htmlspecialchars($emp_avatar); ?>" 
                                                         alt="<?php echo htmlspecialchars($cap['employee_name']); ?>"
                                                         class="employee-avatar-img"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="employee-avatar-letter" style="display:none;">
                                                        <?php echo $emp_initial; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="employee-avatar-letter">
                                                        <?php echo $emp_initial; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="employee-name-wrapper">
                                                    <span class="employee-name-text">
                                                        <?php echo htmlspecialchars($cap['employee_name'] ?? 'N/A'); ?>
                                                    </span>
                                                    <?php if (!empty($cap['employee_code'])): ?>
                                                        <span class="employee-code-text">
                                                            <?php echo htmlspecialchars($cap['employee_code']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="target-badge target-<?php echo $target_class; ?>">
                                                <i class="fas <?php echo $target_class === 'float' ? 'fa-university' : 'fa-money-bill-wave'; ?>"></i>
                                                <?php echo $target_label; ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-badge-history">
                                                <i class="fas fa-arrow-up"></i>
                                                <?php echo formatCurrency($cap['allocated_amount']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="notes-cell" title="<?php echo htmlspecialchars($cap['notes'] ?? ''); ?>">
                                                <?php 
                                                    $notes_display = $cap['notes'] ?? '-';
                                                    $notes_display = str_replace('[CAPITAL ADDITION] ', '', $notes_display);
                                                    echo htmlspecialchars(mb_substr($notes_display, 0, 40));
                                                    if (mb_strlen($notes_display) > 40) echo '...';
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="history-actions">
                                                <a href="view_capital.php?id=<?php echo $cap['id']; ?>" 
                                                   class="btn-history btn-history-view" 
                                                   title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <a href="edit_capital.php?id=<?php echo $cap['id']; ?>" 
                                                   class="btn-history btn-history-edit" 
                                                   title="Edit Capital">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                
                                                <a href="add_capital.php?delete_capital=<?php echo $cap['id']; ?>&branch_id=<?php echo $cap['branch_id']; ?>" 
                                                   class="btn-history btn-history-delete" 
                                                   onclick="return confirmDeleteCapital('<?php echo addslashes($cap['commission_number']); ?>', '<?php echo formatCurrency($cap['allocated_amount']); ?>')"
                                                   title="Delete Capital">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="no-history-results" id="noHistoryResults" style="display:none;">
                        <i class="fas fa-search-minus"></i>
                        <p>No capital additions match your search</p>
                        <button type="button" class="btn btn-reset" onclick="clearHistorySearch()">
                            <i class="fas fa-times"></i> Clear Search
                        </button>
                    </div>
                <?php else: ?>
                    <div class="empty-history">
                        <i class="fas fa-history"></i>
                        <h3>No Capital Additions Yet</h3>
                        <p>Capital additions will appear here once you add capital.</p>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
.main-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
}
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
    --green-primary: #059669;
    --green-dark: #047857;
    --green-light: #D1FAE5;
    --green-accent: #10B981;
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
    --green-light: #065F46;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* BRANCH CARD - GREEN */
.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 18px 24px;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    border-radius: 12px; margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(5, 150, 105, 0.35);
    flex-wrap: wrap; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 56px; height: 56px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 24px; color: #FCD34D; flex-shrink: 0;
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
.branch-status-name { font-size: 20px; font-weight: 700; color: #FFFFFF; }
.branch-status-code {
    font-size: 12px; font-weight: 600;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.3);
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; padding: 0 4px; flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 12px; }
.page-header-left h2 {
    font-size: 20px; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.page-header-left h2 i { color: #10B981; margin-right: 8px; }
.page-subtitle {
    font-size: 13px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 3px 12px; border-radius: 12px;
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 8px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 12px;
    font-weight: 500; font-size: 13px;
    animation: slideDown 0.4s ease forwards;
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
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* NO BRANCH WARNING */
.no-branch-warning {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px;
    background: #FEF3C7; border: 2px solid #FDE68A;
    border-radius: 10px; margin-bottom: 16px;
    color: #92400E;
}
.no-branch-warning i { font-size: 22px; flex-shrink: 0; margin-top: 2px; }
.no-branch-warning strong { font-weight: 700; font-size: 14px; display: block; margin-bottom: 4px; }
.no-branch-warning p { font-size: 13px; margin: 0; }
html.dark-mode .no-branch-warning { background: #5F3A1E; border-color: #92400E; color: #FBBF24; }

/* BRANCH SELECTION */
.branch-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
}
.branch-select-card {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    background: var(--bg-card);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    text-decoration: none;
    transition: all 0.3s ease;
}
.branch-select-card:hover {
    border-color: #10B981;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.2);
}
.branch-select-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.branch-select-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.branch-select-name { font-size: 15px; font-weight: 700; color: var(--text-primary); }
.branch-select-code {
    font-size: 11px; font-weight: 700; color: #059669;
    background: #D1FAE5; padding: 2px 8px; border-radius: 8px;
    align-self: flex-start; font-family: 'Courier New', monospace;
}
.branch-select-arrow { color: var(--text-light); font-size: 14px; transition: all 0.3s ease; }
.branch-select-card:hover .branch-select-arrow { color: #10B981; transform: translateX(4px); }

/* CAPITAL SUMMARY WRAPPER - GREEN */
.capital-summary-wrapper {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(5, 150, 105, 0.35);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}
.capital-summary-wrapper::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 400px; height: 400px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.capital-summary-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.csh-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
.csh-icon {
    width: 50px; height: 50px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D; flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
}
.csh-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.csh-title { font-size: 18px; font-weight: 800; color: #FFFFFF; }
.csh-subtitle { font-size: 12px; font-weight: 500; color: rgba(255, 255, 255, 0.75); }
.csh-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
}

.capital-cards-inside {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    position: relative;
    z-index: 1;
}

.cc-inner {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.cc-inner::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}
.cc-inner:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}
.card-inner-capital::before { background: #60A5FA; }
.card-inner-available::before { background: #FCD34D; }
.card-inner-added::before { background: #86EFAC; }
.card-inner-after::before { background: #E879F9; }

/* Pulse kama available profit imebadilika */
.card-inner-available.live-updated {
    animation: availablePulse 0.6s ease;
}
@keyframes availablePulse {
    0% { background: rgba(255, 255, 255, 0.1); }
    50% { background: rgba(252, 211, 77, 0.35); box-shadow: 0 0 30px rgba(252, 211, 77, 0.6); }
    100% { background: rgba(255, 255, 255, 0.1); }
}

.cci-header { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.cci-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.cci-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.cci-icon-yellow { background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFFFFF; }
.cci-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.cci-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }
.cci-label {
    font-size: 10px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.cci-value {
    font-size: clamp(16px, 1.4vw, 22px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
    overflow-wrap: anywhere;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.cci-value-blue { color: #93C5FD !important; }
.cci-value-yellow { color: #FCD34D !important; }
.cci-value-green { color: #86EFAC !important; }
.cci-value-purple { color: #F0ABFC !important; }
.cci-sublabel {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    display: inline-flex; align-items: center; gap: 5px;
    text-transform: uppercase; letter-spacing: 0.4px;
    margin-top: auto; padding-top: 8px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}
.cci-sublabel i { font-size: 9px; }

/* BREAKDOWN */
.breakdown-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px; margin-bottom: 16px;
}
.breakdown-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 14px 18px;
    display: flex; align-items: center; gap: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.breakdown-card:hover { transform: translateY(-3px); }
.bd-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.bd-content { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.bd-label {
    font-size: 10px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.bd-value {
    font-size: 15px; font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word;
}
.bd-commission .bd-icon { background: #D1FAE5; color: #059669; }
.bd-commission .bd-value { color: #059669; }
.bd-other .bd-icon { background: #EDE9FE; color: #7C3AED; }
.bd-other .bd-value { color: #7C3AED; }
.bd-expense .bd-icon { background: #FEE2E2; color: #DC2626; }
.bd-expense .bd-value { color: #DC2626; }
.bd-salary .bd-icon { background: #FEF3C7; color: #D97706; }
.bd-salary .bd-value { color: #D97706; }

/* FORM */
.form-container {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 20px;
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
.section-header h3 i { color: #10B981; margin-right: 8px; }
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
    border-color: #10B981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}
.form-control:disabled { opacity: 0.7; cursor: not-allowed; }

/* ✅ AVAILABLE PROFIT BANNER */
.available-profit-banner-section {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    padding: 20px 24px !important;
}
html.dark-mode .available-profit-banner-section {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 100%);
}
.available-profit-banner {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 50%, #5B21B6 100%);
    border-radius: 14px;
    color: #FFFFFF;
    box-shadow: 0 8px 28px rgba(124, 58, 237, 0.35);
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.15);
    flex-wrap: wrap;
}
.available-profit-banner::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.apb-icon {
    width: 60px; height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px; color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
    backdrop-filter: blur(10px);
}
.apb-content {
    display: flex; flex-direction: column; gap: 4px;
    flex: 1; min-width: 240px;
    position: relative; z-index: 1;
}
.apb-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.apb-value {
    font-size: 30px; font-weight: 900;
    color: #FFFFFF;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    line-height: 1.1;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
}
.apb-sub {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}
.apb-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    position: relative;
    z-index: 1;
    white-space: nowrap;
}

/* ✅ CASH SECTION - PAMOJA NA PROVIDERS */
.cash-section-container {
    background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%) !important;
    border-left: 4px solid #10B981;
}
html.dark-mode .cash-section-container {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 100%) !important;
}
.cash-input-wrapper {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 20px 24px;
    border: 2px solid #10B981;
}
.cash-input-label {
    display: flex; align-items: center; gap: 8px;
    font-size: 14px; font-weight: 700;
    color: var(--text-primary); margin-bottom: 12px;
}
.cash-input-label i { color: #10B981; }
.cash-input-group {
    display: flex; align-items: center;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px; padding: 4px 8px;
}
.cash-input-group:focus-within {
    border-color: #10B981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
}
.cash-currency {
    font-size: 15px; font-weight: 800;
    color: #10B981;
    padding: 0 12px 0 8px;
    border-right: 2px solid var(--border-color);
    margin-right: 8px;
    font-family: 'Courier New', monospace;
}
.cash-input-field {
    flex: 1;
    border: none;
    background: transparent;
    padding: 12px 8px;
    font-size: 20px;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    text-align: right;
    outline: none;
}
.cash-input-hint {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; color: var(--text-muted);
    margin-top: 10px; font-weight: 600;
}
.cash-input-hint i { color: #10B981; }

/* ✅ PROVIDERS CARDS GRID - 3 PER ROW */
.providers-cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.provider-card-item {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%, #A7F3D0 100%);
    border: 2px solid #6EE7B7;
    border-radius: 14px;
    padding: 14px;
    position: relative;
    transition: all 0.3s ease;
    min-width: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
html.dark-mode .provider-card-item {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%);
    border-color: #10B981;
}
.provider-card-item:hover {
    border-color: #059669;
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(5, 150, 105, 0.25);
}
.provider-card-item.checked {
    border-color: #059669;
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.35);
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 50%, #6EE7B7 100%);
}
html.dark-mode .provider-card-item.checked {
    background: linear-gradient(135deg, #047857 0%, #059669 50%, #10B981 100%);
}

.pc-checkbox-wrapper {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 2;
}
.provider-checkbox {
    width: 22px;
    height: 22px;
    cursor: pointer;
    accent-color: #059669;
    border-radius: 6px;
}
.pc-check-label {
    display: none;
}

.pc-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 8px;
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.2);
    padding-right: 28px;
}
.pc-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 15px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.4);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
}
.pc-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.pc-name {
    font-size: 12px;
    font-weight: 800;
    color: #065F46;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
html.dark-mode .pc-name { color: #D1FAE5; }
.pc-code {
    font-size: 9px;
    font-weight: 700;
    color: #047857;
    background: rgba(255, 255, 255, 0.6);
    padding: 2px 7px;
    border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
    border: 1px solid rgba(5, 150, 105, 0.3);
}
html.dark-mode .pc-code { background: rgba(15, 23, 42, 0.4); color: #6EE7B7; }

.pc-float-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    font-size: 11px;
}
.pc-float-label {
    color: #047857;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.3px;
    display: flex; align-items: center; gap: 4px;
}
.pc-float-label i { color: #059669; font-size: 10px; }
.pc-float-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #059669;
    font-size: 13px;
}

.pc-available-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    font-size: 11px;
}
.pc-available-label {
    color: #6D28D9;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.3px;
    display: flex; align-items: center; gap: 4px;
}
.pc-available-label i { color: #7C3AED; font-size: 10px; }
.pc-available-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #6D28D9;
    font-size: 13px;
}
html.dark-mode .pc-available-label { color: #C4B5FD; }
html.dark-mode .pc-available-value { color: #C4B5FD; }

.pc-input-wrapper {
    display: flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.85);
    border-radius: 10px;
    padding: 4px 10px;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
    margin-top: 4px;
    transition: all 0.3s ease;
}
.provider-card-item.checked .pc-input-wrapper {
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
}
html.dark-mode .pc-input-wrapper { background: rgba(15, 23, 42, 0.5); }
.pc-currency {
    font-size: 11px;
    font-weight: 900;
    color: #059669;
    padding-right: 8px;
    border-right: 2px solid #D1FAE5;
    margin-right: 8px;
    font-family: 'Courier New', monospace;
}
.pc-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 8px 4px;
    font-size: 15px;
    font-weight: 900;
    color: #065F46;
    font-family: 'Courier New', monospace;
    text-align: right;
    outline: none;
    min-width: 0;
}
html.dark-mode .pc-input { color: #A7F3D0; }
.pc-input:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* PROVIDERS FOOTER */
.providers-footer {
    display: flex; justify-content: space-between;
    align-items: center; margin-top: 16px;
    padding: 12px 18px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    border-radius: 10px;
    border: 1.5px solid #FCD34D;
    flex-wrap: wrap; gap: 10px;
}
html.dark-mode .providers-footer { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #F59E0B; }
.pf-selected, .pf-total {
    font-size: 13px; font-weight: 700;
    color: #92400E;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pf-selected i, .pf-total i { color: #D97706; }
.pf-selected strong, .pf-total strong {
    font-weight: 900;
    color: #78350F;
    font-size: 15px;
}

.empty-providers {
    text-align: center; padding: 30px 20px;
    color: var(--text-muted);
    background: var(--bg-input);
    border-radius: 10px;
    border: 1px dashed var(--border-color);
}
.empty-providers i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

/* SUMMARY */
.summary-section { background: var(--bg-hover); }
.summary-preview {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
}
.summary-preview-item {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 14px 18px;
    display: flex; flex-direction: column; gap: 4px;
    border: 1px solid var(--border-color);
    min-width: 0;
}
.summary-preview-item.highlight {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    border-color: #FCD34D;
}
.summary-preview-item.highlight-purple {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    border-color: #C4B5FD;
}
html.dark-mode .summary-preview-item.highlight { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #F59E0B; }
html.dark-mode .summary-preview-item.highlight-purple { background: linear-gradient(135deg, #4C1D95, #5B21B6); border-color: #8B5CF6; }
.sp-label {
    font-size: 11px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
}
.sp-value {
    font-size: 15px; font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    color: var(--text-primary);
    word-break: break-word;
}
.sp-amount { color: #10B981; }
.sp-total { color: #F59E0B; }
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
.btn-submit {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.45);
}
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-reset, .btn-cancel {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-reset:hover { background: var(--border-color); }
.btn-cancel:hover { background: #FEE2E2; color: #991B1B; border-color: #FECACA; }

/* ============================================================
   HISTORY TABLE
   ============================================================ */
.table-container-history {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    box-shadow: 0 1px 3px var(--shadow-color);
    overflow: hidden;
    margin-top: 24px;
    max-width: 100%;
    width: 100%;
}

.history-header-row {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.history-header-row::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.hhr-left {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    position: relative;
    z-index: 1;
    min-width: 0;
}
.hhr-center {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}
.hhr-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    position: relative;
    z-index: 1;
    min-width: 0;
}

.history-search-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 12px;
    width: 260px;
    max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.history-search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.history-search-wrapper i {
    color: #059669;
    font-size: 12px;
    flex-shrink: 0;
}
.history-search-wrapper input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 12px;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-family: 'Inter', sans-serif;
}
.history-search-wrapper input::placeholder {
    color: #9CA3AF;
    font-size: 11px;
}
.history-search-wrapper button {
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.history-search-wrapper button:hover {
    background: #DC2626;
    color: white;
    transform: scale(1.1);
}
.history-search-count {
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    flex-shrink: 0;
    white-space: nowrap;
}

.scroll-btn-history {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #059669;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn-history:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.scroll-btn-history:active { transform: translateY(0); }
.scroll-label-history {
    font-size: 10px;
    font-weight: 800;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
    padding: 0 4px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
}
.scroll-label-history i { font-size: 10px; }

.history-count-badge {
    font-size: 11px;
    font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.history-count-badge i {
    font-size: 11px;
    color: #FCD34D;
}

.history-table-wrapper {
    overflow-x: auto;
    overflow-y: hidden;
    width: 100%;
    max-width: 100%;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}
.history-table-wrapper::-webkit-scrollbar { height: 8px; }
.history-table-wrapper::-webkit-scrollbar-track {
    background: var(--bg-hover);
    border-radius: 4px;
}
.history-table-wrapper::-webkit-scrollbar-thumb {
    background: #059669;
    border-radius: 4px;
}
.history-table-wrapper::-webkit-scrollbar-thumb:hover {
    background: #047857;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 1100px;
}
.history-table thead {
    background: #059669;
}
.history-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #047857;
    white-space: nowrap;
}
.history-table thead th.text-right { text-align: right; }
.history-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.history-table tbody tr:hover { background: var(--bg-hover); }
.history-table tbody tr:nth-child(even) { background: var(--bg-input); }
.history-table tbody td {
    padding: 12px 14px;
    color: var(--text-primary);
    vertical-align: middle;
}
.history-table tbody td.text-right { text-align: right; }

.history-row-item.hidden-by-search { display: none !important; }

.row-num-history {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--bg-hover);
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.reference-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #065F46;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #6EE7B7;
    white-space: nowrap;
}
html.dark-mode .reference-badge {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #A7F3D0;
    border-color: #10B981;
}
.date-cell-history {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}
.date-cell-history i { color: #059669; font-size: 10px; }
.branch-badge-history {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1E40AF;
    border-radius: 8px;
    font-size: 11px; font-weight: 700;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}
.branch-badge-history i { font-size: 10px; color: #2563EB; }

.employee-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.employee-avatar-img {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #10B981;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
}
.employee-avatar-letter {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-size: 14px;
    flex-shrink: 0;
    border: 2px solid #6EE7B7;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
}
.employee-name-wrapper {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.employee-name-text {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 160px;
}
.employee-code-text {
    font-size: 10px;
    font-weight: 600;
    color: #059669;
    background: #D1FAE5;
    padding: 1px 8px;
    border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}
html.dark-mode .employee-code-text { background: #065F46; color: #6EE7B7; }

.target-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.target-float { background: #DBEAFE; color: #1E40AF; border: 1px solid #93C5FD; }
.target-cash { background: #DCFCE7; color: #15803D; border: 1px solid #86EFAC; }
.target-default { background: #F3F4F6; color: #374151; border: 1px solid #E5E7EB; }
html.dark-mode .target-float { background: #1E3A5F; color: #93C5FD; border-color: #3B82F6; }
html.dark-mode .target-cash { background: #14532D; color: #4ADE80; border-color: #16A34A; }

.amount-badge-history {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #065F46;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #6EE7B7;
    white-space: nowrap;
}
.amount-badge-history i { font-size: 10px; color: #059669; }
.notes-cell {
    font-size: 11px;
    color: var(--text-muted);
    display: block;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-style: italic;
}

.history-actions {
    display: flex;
    gap: 5px;
    justify-content: center;
    align-items: center;
}
.btn-history {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    font-size: 13px;
}
.btn-history-view {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
    box-shadow: 0 2px 6px rgba(29, 78, 216, 0.15);
}
.btn-history-view:hover {
    background: linear-gradient(135deg, #1D4ED8, #2563EB);
    color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.4);
}
.btn-history-edit {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border: 1.5px solid #FCD34D;
    box-shadow: 0 2px 6px rgba(217, 119, 6, 0.15);
}
.btn-history-edit:hover {
    background: linear-gradient(135deg, #D97706, #F59E0B);
    color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 16px rgba(217, 119, 6, 0.4);
}
.btn-history-delete {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
    box-shadow: 0 2px 6px rgba(153, 27, 27, 0.15);
}
.btn-history-delete:hover {
    background: linear-gradient(135deg, #991B1B, #DC2626);
    color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 6px 16px rgba(153, 27, 27, 0.4);
}
html.dark-mode .btn-history-view { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .btn-history-edit { background: linear-gradient(135deg, #5F3A1E, #78350F); color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .btn-history-delete { background: linear-gradient(135deg, #7F1D1D, #991B1B); color: #FCA5A5; border-color: #DC2626; }

.no-history-results {
    text-align: center;
    padding: 40px 20px;
    background: var(--bg-hover);
    display: none;
}
.no-history-results i {
    font-size: 44px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.no-history-results p {
    font-size: 14px;
    color: var(--text-secondary);
    margin: 0 0 16px 0;
    font-weight: 500;
}
.empty-history {
    text-align: center;
    padding: 50px 20px;
    background: var(--bg-card);
}
.empty-history i {
    font-size: 50px;
    color: #10B981;
    opacity: 0.3;
    display: block;
    margin-bottom: 14px;
}
.empty-history h3 {
    font-size: 18px;
    color: var(--text-primary);
    margin: 0 0 6px 0;
}
.empty-history p {
    color: var(--text-secondary);
    font-size: 13px;
    margin: 0;
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .capital-cards-inside { grid-template-columns: repeat(2, 1fr); }
    .cci-value { font-size: 18px; }
    .providers-cards-grid { grid-template-columns: repeat(2, 1fr); }
    .summary-preview { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1024px) {
    .breakdown-grid { grid-template-columns: repeat(2, 1fr); }
    .history-header-row {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    .hhr-left, .hhr-center, .hhr-right {
        justify-content: center;
        width: 100%;
    }
    .history-search-wrapper { width: 100%; }
}
@media (max-width: 768px) {
    .branch-status-card { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .capital-summary-wrapper { padding: 18px; }
    .capital-summary-header { flex-direction: column; align-items: flex-start; }
    .capital-cards-inside { grid-template-columns: 1fr; }
    .breakdown-grid { grid-template-columns: 1fr; }
    .summary-preview { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .form-row .full-width { grid-column: span 1; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { justify-content: center; width: 100%; }
    .providers-cards-grid { grid-template-columns: 1fr; }
    .available-profit-banner { flex-direction: column; align-items: flex-start; }
    .apb-icon { width: 48px; height: 48px; font-size: 20px; }
    .apb-value { font-size: 22px; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-name { font-size: 16px; }
    .branch-status-icon { width: 44px; height: 44px; font-size: 18px; }
    .page-header-left h2 { font-size: 17px; }
    .cci-value { font-size: 16px; }
    .cci-icon { width: 38px; height: 38px; font-size: 16px; }
    .csh-title { font-size: 15px; }
    .csh-icon { width: 42px; height: 42px; font-size: 18px; }
    .form-section { padding: 16px 14px; }
    .cash-input-field { font-size: 17px; }
    .apb-value { font-size: 20px; }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    var value = input.value.replace(/[^0-9]/g, '');
    if (value === '') {
        input.value = '';
        updatePreview();
        return;
    }
    
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

function onProviderCheck(checkbox) {
    var providerId = checkbox.getAttribute('data-provider-id');
    var amountInput = document.getElementById('amt_' + providerId);
    var card = checkbox.closest('.provider-card-item');
    
    if (checkbox.checked) {
        amountInput.disabled = false;
        card.classList.add('checked');
        amountInput.focus();
    } else {
        amountInput.disabled = true;
        amountInput.value = '';
        card.classList.remove('checked');
    }
    
    updatePreview();
}

// ============================================================
// ✅ UPDATE PREVIEW + LIVE FILTER
// ✅ Cash + Providers ZOTE zinahesabiwa
// ============================================================
function updatePreview() {
    var available = parseFloat(document.getElementById('availableProfitInput').value) || 0;
    var currentCapital = parseFloat(document.getElementById('currentCapitalInput').value) || 0;
    var currentCash = parseFloat(document.getElementById('currentCashInput').value) || 0;
    
    // ✅ Get cash amount
    var cashInput = document.getElementById('cash_amount');
    var cashAmount = 0;
    if (cashInput && cashInput.value) {
        cashAmount = parseFloat(cashInput.value.replace(/,/g, '')) || 0;
    }
    
    // ✅ Get providers total
    var providersAmount = 0;
    var selectedCount = 0;
    
    document.querySelectorAll('.provider-checkbox:checked').forEach(function(chk) {
        var providerId = chk.getAttribute('data-provider-id');
        var amountInput = document.getElementById('amt_' + providerId);
        if (amountInput && amountInput.value) {
            var amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
            if (amount > 0) {
                providersAmount += amount;
                selectedCount++;
            }
        }
    });
    
    // ✅ Total = Cash + Providers
    var totalAmount = cashAmount + providersAmount;
    
    // ✅ Update Selected count
    var selectedCountEl = document.getElementById('selectedCount');
    if (selectedCountEl) selectedCountEl.textContent = selectedCount;
    
    var totalProvEl = document.getElementById('totalProvidersAmount');
    if (totalProvEl) totalProvEl.textContent = 'TSh ' + providersAmount.toLocaleString('en-US');
    
    // ✅ Update preview elements
    var previewCashEl = document.getElementById('previewCash');
    if (previewCashEl) previewCashEl.textContent = 'TSh ' + cashAmount.toLocaleString('en-US');
    
    var previewProvidersEl = document.getElementById('previewProviders');
    if (previewProvidersEl) previewProvidersEl.textContent = 'TSh ' + providersAmount.toLocaleString('en-US');
    
    var previewTotalEl = document.getElementById('previewTotal');
    if (previewTotalEl) previewTotalEl.textContent = 'TSh ' + totalAmount.toLocaleString('en-US');
    
    // ✅ Update Capital After Add
    var capitalAfter = currentCapital + totalAmount;
    var capitalAfterEl = document.getElementById('capitalAfterDisplay');
    if (capitalAfterEl) {
        capitalAfterEl.textContent = 'TSh ' + capitalAfter.toLocaleString('en-US');
        capitalAfterEl.classList.add('updated');
        setTimeout(function() {
            capitalAfterEl.classList.remove('updated');
        }, 600);
    }
    
    var previewAfterEl = document.getElementById('previewAfter');
    if (previewAfterEl) {
        previewAfterEl.textContent = 'TSh ' + capitalAfter.toLocaleString('en-US');
    }
    
    // ✅ Update Available Profit - LIVE FILTER
    var remainingAvailable = Math.max(0, available - totalAmount);
    
    var availableDisplayEl = document.getElementById('availableProfitDisplay');
    var availableSublabelEl = document.getElementById('availableSublabel');
    var maxToAddBadgeEl = document.getElementById('maxToAddBadge');
    var apbRemainingDisplayEl = document.getElementById('apbRemainingDisplay');
    var apbUsedBadgeEl = document.getElementById('apbUsedBadge');
    var availableCard = document.getElementById('availableProfitCard');
    
    if (availableDisplayEl) {
        availableDisplayEl.textContent = 'TSh ' + remainingAvailable.toLocaleString('en-US');
        
        if (remainingAvailable === 0 && totalAmount > 0) {
            availableDisplayEl.style.color = '#FCA5A5';
        } else {
            availableDisplayEl.style.color = '#FCD34D';
        }
    }
    
    if (availableSublabelEl) {
        if (totalAmount > 0) {
            availableSublabelEl.textContent = 'Remaining from TSh ' + available.toLocaleString('en-US');
        } else {
            availableSublabelEl.textContent = 'Max to add';
        }
    }
    
    if (maxToAddBadgeEl) {
        maxToAddBadgeEl.textContent = 'TSh ' + remainingAvailable.toLocaleString('en-US');
    }
    
    if (apbRemainingDisplayEl) {
        apbRemainingDisplayEl.textContent = 'TSh ' + remainingAvailable.toLocaleString('en-US');
        apbRemainingDisplayEl.style.color = (remainingAvailable === 0 && totalAmount > 0) ? '#FCA5A5' : '#FFFFFF';
    }
    
    if (apbUsedBadgeEl) {
        apbUsedBadgeEl.textContent = 'Used: TSh ' + totalAmount.toLocaleString('en-US');
    }
    
    // ✅ Pulse animation
    if (availableCard && totalAmount > 0) {
        availableCard.classList.add('live-updated');
        setTimeout(function() {
            availableCard.classList.remove('live-updated');
        }, 600);
    }
}

function validateForm() {
    var branchId = '<?php echo $selected_branch; ?>';
    if (!branchId || branchId === '0') {
        alert('Please select a branch first.');
        return false;
    }
    
    var available = parseFloat(document.getElementById('availableProfitInput').value) || 0;
    var cashInput = document.getElementById('cash_amount');
    var cashAmount = 0;
    if (cashInput && cashInput.value) {
        cashAmount = parseFloat(cashInput.value.replace(/,/g, '')) || 0;
    }
    
    var providersAmount = 0;
    var hasProvider = false;
    
    document.querySelectorAll('.provider-checkbox:checked').forEach(function(chk) {
        var providerId = chk.getAttribute('data-provider-id');
        var amountInput = document.getElementById('amt_' + providerId);
        if (amountInput && amountInput.value) {
            var amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
            if (amount > 0) {
                hasProvider = true;
                providersAmount += amount;
            }
        }
    });
    
    var totalAmount = cashAmount + providersAmount;
    
    if (totalAmount <= 0) {
        alert('Please enter at least one amount (Cash or Provider).');
        return false;
    }
    
    if (totalAmount > available) {
        alert('The total amount (' + totalAmount.toLocaleString() + ' TSh) exceeds available profit (' + 
            available.toLocaleString() + ' TSh).');
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?');
}

// ============================================================
// HISTORY SEARCH
// ============================================================
function filterHistory(input) {
    var searchTerm = input.value.toLowerCase().trim();
    var rows = document.querySelectorAll('.history-row-item');
    var clearBtn = document.getElementById('historySearchClear');
    var countBadge = document.getElementById('historySearchCount');
    var noResults = document.getElementById('noHistoryResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(function(row) { row.classList.remove('hidden-by-search'); });
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        return;
    }
    
    var matchCount = 0;
    rows.forEach(function(row) {
        var searchData = row.getAttribute('data-search') || '';
        if (searchData.indexOf(searchTerm) !== -1) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    if (noResults) {
        noResults.style.display = matchCount === 0 ? 'block' : 'none';
    }
}

function clearHistorySearch() {
    var input = document.getElementById('historySearchInput');
    if (input) {
        input.value = '';
        filterHistory(input);
        input.focus();
    }
}

function scrollHistoryTable(direction) {
    var wrapper = document.getElementById('historyTableWrapper');
    if (!wrapper) return;
    var scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

function confirmDeleteCapital(reference, amount) {
    var msg = 'Are you sure you want to DELETE this capital addition?\n\n' +
              'Reference: ' + reference + '\n' +
              'Amount: ' + amount + '\n\n' +
              'WARNING: This will REVERSE the capital addition from daily reports.\n' +
              'This action cannot be undone.';
    return confirm(msg);
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
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() { errorAlert.style.display = 'none'; }, 15000);
    }
    
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() { successAlert.style.display = 'none'; }, 8000);
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var historyInput = document.getElementById('historySearchInput');
            if (historyInput && historyInput.value.length > 0 && document.activeElement === historyInput) {
                clearHistorySearch();
            }
        }
    });
});
</script>
</body>
</html>