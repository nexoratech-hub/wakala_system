<?php
// ================================================================
// FILE: modules/daily_report/transactions.php
// WAKALA FINANCIAL SYSTEM - DEPOSITS & WITHDRAWALS
// ✅ FIXED: current_capital = TOTAL FLOAT + CASH
// ✅ FIXED: Float = SUM ya LATEST record per provider (no double-counting)
// ✅ FIXED: UPDATE badala ya INSERT ili kuepuka duplicates
// ✅ FIXED: Auto-fix daily_reports values kila transaction
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
// GET PARAMETERS
// ============================================================
$type = isset($_GET['type']) && in_array($_GET['type'], ['deposit', 'withdrawal']) 
    ? $_GET['type'] 
    : 'deposit';

$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

if ($selected_branch == 0) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch();
    if ($emp && $emp['branch_id'] > 0) {
        $selected_branch = intval($emp['branch_id']);
    }
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$branch_name = 'All Branches';
$branch_code = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
    }
}

// ============================================================
// HELPER: Get latest daily report
// ============================================================
function getLatestDailyReport($db, $branch_id) {
    $stmt = $db->prepare("
        SELECT * FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================================
// ✅ HELPER: Calculate TOTAL FLOAT from LATEST record per provider
// ✅ Inaepuka double-counting ya duplicate records
// ============================================================
function calculateTotalFloat($db, $daily_report_id) {
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
// ✅ HELPER: Sync daily_reports totals from providers
// Inahesabu total_deposits, total_withdrawals, current_float, current_capital
// ============================================================
function syncDailyReportTotals($db, $daily_report_id, $current_cash) {
    // Hesabu totals kutoka LATEST record per provider
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(latest.current_float), 0) as total_float,
            COALESCE(SUM(latest.total_deposits), 0) as total_deposits,
            COALESCE(SUM(latest.total_withdrawals), 0) as total_withdrawals
        FROM (
            SELECT drp1.provider_id, drp1.current_float, 
                   drp1.total_deposits, drp1.total_withdrawals
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
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total_float = floatval($totals['total_float'] ?? 0);
    $total_deposits = floatval($totals['total_deposits'] ?? 0);
    $total_withdrawals = floatval($totals['total_withdrawals'] ?? 0);
    $current_capital = $total_float + $current_cash;
    
    // Update daily_reports
    $stmt = $db->prepare("
        UPDATE daily_reports 
        SET current_float = ?,
            current_capital = ?,
            total_deposits = ?,
            total_withdrawals = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $total_float,
        $current_capital,
        $total_deposits,
        $total_withdrawals,
        $daily_report_id
    ]);
    
    return [
        'total_float' => $total_float,
        'current_capital' => $current_capital,
        'total_deposits' => $total_deposits,
        'total_withdrawals' => $total_withdrawals
    ];
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
    try {
        $db->beginTransaction();
        
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $provider_id = intval($_POST['provider_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $transaction_type = $_POST['transaction_type'] ?? 'deposit';
        $reference_number = trim($_POST['reference_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
        
        // ------------------------------------------------------------
        // VALIDATION
        // ------------------------------------------------------------
        if ($branch_id <= 0) throw new Exception('Please select a branch.');
        if ($provider_id <= 0) throw new Exception('Please select a provider.');
        if ($amount <= 0) throw new Exception('Amount must be greater than 0.');
        if (!in_array($transaction_type, ['deposit', 'withdrawal'])) {
            throw new Exception('Invalid transaction type.');
        }
        
        // ------------------------------------------------------------
        // VALIDATE PROVIDER
        // ------------------------------------------------------------
        $stmt = $db->prepare("SELECT * FROM providers WHERE id = ? AND is_active = 1");
        $stmt->execute([$provider_id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$provider) throw new Exception('Provider not found.');
        
        $stmt = $db->prepare("SELECT * FROM branch_providers WHERE branch_id = ? AND provider_id = ? AND is_active = 1");
        $stmt->execute([$branch_id, $provider_id]);
        $branch_provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch_provider) throw new Exception('Provider is not assigned to this branch.');
        
        // ------------------------------------------------------------
        // GET LATEST DAILY REPORT
        // ------------------------------------------------------------
        $latest_dr = getLatestDailyReport($db, $branch_id);
        if (!$latest_dr) {
            throw new Exception('Hakuna daily report yoyote. Tafadhali tengeneza daily report kwanza.');
        }
        
        $daily_report_id = $latest_dr['id'];
        $current_cash = floatval($latest_dr['current_cash'] ?? 0);
        
        // ------------------------------------------------------------
        // ✅ GET LATEST record ya provider (kuepuka duplicates)
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            SELECT * FROM daily_report_providers 
            WHERE daily_report_id = ? AND provider_id = ?
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$daily_report_id, $provider_id]);
        $dr_provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dr_provider) {
            $current_float = floatval($dr_provider['current_float'] ?? 0);
        } else {
            // Fallback: chukua kutoka morning_report_providers
            $stmt = $db->prepare("
                SELECT mrp.float_balance 
                FROM morning_report_providers mrp
                INNER JOIN morning_reports mr ON mrp.report_id = mr.id
                WHERE mr.branch_id = ? 
                AND mrp.provider_id = ?
                ORDER BY mr.report_date DESC, mr.id DESC
                LIMIT 1
            ");
            $stmt->execute([$branch_id, $provider_id]);
            $mr_provider = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$mr_provider) {
                throw new Exception('Hakuna float ya provider. Tafadhali tengeneza morning report kwanza.');
            }
            
            $current_float = floatval($mr_provider['float_balance'] ?? 0);
        }
        
        // ------------------------------------------------------------
        // CALCULATE NEW FLOAT & CASH
        // Deposit:    Float += amount, Cash -= amount
        // Withdrawal: Float -= amount, Cash += amount
        // ------------------------------------------------------------
        if ($transaction_type === 'withdrawal') {
            $new_float = $current_float - $amount;
            $new_cash  = $current_cash + $amount;
        } else {
            $new_float = $current_float + $amount;
            $new_cash  = $current_cash - $amount;
        }
        
        // ------------------------------------------------------------
        // VALIDATE BALANCES
        // ------------------------------------------------------------
        if ($new_float < 0) {
            throw new Exception('Float haitoshi. Current float: TSh ' . number_format($current_float, 0) . 
                               ' | Unajaribu kutoa: TSh ' . number_format($amount, 0));
        }
        if ($new_cash < 0) {
            throw new Exception('Cash haitoshi. Current cash: TSh ' . number_format($current_cash, 0) . 
                               ' | Unajaribu kutoa: TSh ' . number_format($amount, 0));
        }
        
        // ------------------------------------------------------------
        // GENERATE TRANSACTION NUMBER
        // ------------------------------------------------------------
        $prefix = $transaction_type === 'deposit' ? 'DEP' : 'WTH';
        $transaction_number = $prefix . '-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // ============================================================
        // STEP 1: INSERT TRANSACTION
        // ============================================================
        $stmt = $db->prepare("
            INSERT INTO transactions 
            (transaction_number, transaction_type, employee_id, branch_id, branch,
             provider_id, provider_code, amount, reference_number, 
             transaction_date, transaction_time, description, status, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW())
        ");
        $stmt->execute([
            $transaction_number,
            $transaction_type,
            $user_id,
            $branch_id,
            $branch_name,
            $provider_id,
            $branch_provider['provider_code'],
            $amount,
            $reference_number,
            $transaction_date,
            date('H:i:s'),
            $description,
            "Float: " . number_format($current_float, 0) . " → " . number_format($new_float, 0) . 
            " | Cash: " . number_format($current_cash, 0) . " → " . number_format($new_cash, 0)
        ]);
        
        $transaction_id = $db->lastInsertId();
        
        // ============================================================
        // ✅ STEP 2: UPDATE daily_report_providers (FLOAT ya provider husika)
        // ✅ Tumia UPDATE tu ili kuepuka duplicates
        // ============================================================
        if ($dr_provider) {
            $stmt = $db->prepare("
                UPDATE daily_report_providers 
                SET current_float = ?,
                    total_deposits = total_deposits + ?,
                    total_withdrawals = total_withdrawals + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $new_float,
                $transaction_type === 'deposit' ? $amount : 0,
                $transaction_type === 'withdrawal' ? $amount : 0,
                $dr_provider['id']
            ]);
        } else {
            // INSERT mara moja tu
            $stmt = $db->prepare("
                INSERT INTO daily_report_providers 
                (daily_report_id, provider_id, provider_code, provider_name,
                 morning_float, morning_cash, current_float, current_cash,
                 total_deposits, total_withdrawals, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?, ?, NOW())
            ");
            $stmt->execute([
                $daily_report_id,
                $provider_id,
                $branch_provider['provider_code'],
                $provider['provider_name'],
                $current_float,
                $new_float,
                $transaction_type === 'deposit' ? $amount : 0,
                $transaction_type === 'withdrawal' ? $amount : 0
            ]);
        }
        
        // ============================================================
        // ✅ STEP 3: UPDATE daily_reports.current_cash PEKEE
        // ✅ Total float, capital, deposits, withdrawals zitarekebishwa na syncDailyReportTotals()
        // ============================================================
        $stmt = $db->prepare("
            UPDATE daily_reports 
            SET current_cash = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_cash, $daily_report_id]);
        
        // ============================================================
        // ✅ STEP 4: SYNC daily_reports totals kutoka providers
        // ✅ Hii inahakikisha current_float, current_capital, 
        //    total_deposits, total_withdrawals ni SAHIHI
        // ============================================================
        syncDailyReportTotals($db, $daily_report_id, $new_cash);
        
        // ============================================================
        // STEP 5: LOG KWENYE daily_report_transactions
        // ============================================================
        $stmt = $db->prepare("
            INSERT INTO daily_report_transactions 
            (daily_report_id, provider_id, provider_code, transaction_type,
             amount, reference_number, description, transaction_date,
             transaction_time, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $daily_report_id,
            $provider_id,
            $branch_provider['provider_code'],
            $transaction_type,
            $amount,
            $reference_number,
            $description,
            $transaction_date,
            date('H:i:s'),
            $user_id
        ]);
        
        // ------------------------------------------------------------
        // LOG ACTIVITY
        // ------------------------------------------------------------
        logActivity(
            $user_id, 
            'Add ' . ucfirst($transaction_type), 
            'Transactions', 
            $transaction_id, 
            '', 
            ucfirst($transaction_type) . ' of TSh ' . number_format($amount) . 
            ' from ' . $provider['provider_name']
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = ucfirst($transaction_type) . ' ya TSh ' . number_format($amount) . 
            ' imefanikiwa!';
        
        header('Location: transactions.php?type=' . $type . '&branch_id=' . $branch_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// ============================================================
// GET TRANSACTIONS
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

try {
    $sql = "
        SELECT 
            t.*,
            p.provider_name,
            p.icon_class,
            p.color_code,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code,
            e.full_name as employee_name
        FROM transactions t
        LEFT JOIN providers p ON t.provider_id = p.id
        LEFT JOIN branches b ON t.branch_id = b.id
        LEFT JOIN employees e ON t.employee_id = e.id
        WHERE t.transaction_type = ?
        AND t.transaction_date BETWEEN ? AND ?
    ";
    $params = [$type, $from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql .= " AND t.branch_id = ?";
        $params[] = $selected_branch;
    }
    
    $sql .= " ORDER BY t.transaction_date DESC, t.id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ============================================================
    // GET TOTALS
    // ============================================================
    $deposit_count = 0;
    $deposit_amount = 0;
    $withdrawal_count = 0;
    $withdrawal_amount = 0;
    
    // Deposits
    $sql_dep = "
        SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
        FROM transactions
        WHERE transaction_type = 'deposit'
        AND transaction_date BETWEEN ? AND ?
    ";
    $params_dep = [$from_date, $to_date];
    if ($selected_branch > 0) {
        $sql_dep .= " AND branch_id = ?";
        $params_dep[] = $selected_branch;
    }
    $stmt = $db->prepare($sql_dep);
    $stmt->execute($params_dep);
    $dep_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $deposit_count = intval($dep_data['cnt'] ?? 0);
    $deposit_amount = floatval($dep_data['total'] ?? 0);
    
    // Withdrawals
    $sql_wth = "
        SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
        FROM transactions
        WHERE transaction_type = 'withdrawal'
        AND transaction_date BETWEEN ? AND ?
    ";
    $params_wth = [$from_date, $to_date];
    if ($selected_branch > 0) {
        $sql_wth .= " AND branch_id = ?";
        $params_wth[] = $selected_branch;
    }
    $stmt = $db->prepare($sql_wth);
    $stmt->execute($params_wth);
    $wth_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $withdrawal_count = intval($wth_data['cnt'] ?? 0);
    $withdrawal_amount = floatval($wth_data['total'] ?? 0);
    
    // ============================================================
    // ✅ CURRENT FLOAT, CASH & CAPITAL (Recalculated)
    // ============================================================
    $current_float = 0;
    $current_cash = 0;
    $current_capital = 0;
    
    if ($selected_branch > 0) {
        $latest_dr = getLatestDailyReport($db, $selected_branch);
        
        if ($latest_dr) {
            $latest_dr_id = $latest_dr['id'];
            $current_cash = floatval($latest_dr['current_cash'] ?? 0);
            
            // ✅ Hesabu float kutoka LATEST record per provider
            $current_float = calculateTotalFloat($db, $latest_dr_id);
            
            // ✅ Recalculate capital
            $current_capital = $current_float + $current_cash;
            
            // ✅ Auto-fix daily_reports kama values hazipo sawa
            $db_float = floatval($latest_dr['current_float'] ?? 0);
            $db_capital = floatval($latest_dr['current_capital'] ?? 0);
            
            if (abs($current_float - $db_float) > 0.01 || abs($current_capital - $db_capital) > 0.01) {
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET current_float = ?, current_capital = ?
                    WHERE id = ?
                ");
                $stmt->execute([$current_float, $current_capital, $latest_dr_id]);
            }
        }
    }
    
    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get providers
    $providers_list = [];
    if ($selected_branch > 0) {
        $latest_dr = getLatestDailyReport($db, $selected_branch);
        $latest_dr_id = $latest_dr ? $latest_dr['id'] : 0;
        
        $stmt = $db->prepare("
            SELECT 
                p.*, 
                bp.provider_code,
                COALESCE(
                    (SELECT drp.current_float 
                     FROM daily_report_providers drp
                     WHERE drp.daily_report_id = ?
                     AND drp.provider_id = bp.provider_id
                     ORDER BY drp.id DESC LIMIT 1),
                    (SELECT mrp.float_balance 
                     FROM morning_report_providers mrp
                     INNER JOIN morning_reports mr ON mrp.report_id = mr.id
                     WHERE mr.branch_id = bp.branch_id 
                     AND mrp.provider_id = bp.provider_id
                     ORDER BY mr.report_date DESC, mr.id DESC LIMIT 1),
                    0
                ) as current_float
            FROM providers p
            INNER JOIN branch_providers bp ON p.id = bp.provider_id
            WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
            ORDER BY p.provider_name
        ");
        $stmt->execute([$latest_dr_id, $selected_branch]);
        $providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $transactions = [];
    $deposit_count = 0;
    $deposit_amount = 0;
    $withdrawal_count = 0;
    $withdrawal_amount = 0;
    $branches = [];
    $providers_list = [];
    $current_float = 0;
    $current_cash = 0;
    $current_capital = 0;
}

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

$page_title = $type === 'deposit' ? 'Deposits' : 'Withdrawals';
$page_icon = $type === 'deposit' ? 'fa-arrow-down' : 'fa-arrow-up';
$theme_color = $type === 'deposit' ? '#059669' : '#DC2626';

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
            </div>
            <div class="branch-indicator-right">
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- CAPITAL SUMMARY -->
        <div class="stats-grid-soft">
            <div class="stat-card-soft stat-card-soft-float">
                <div class="stat-icon-soft">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Current Float</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($current_float); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-university"></i>
                        Provider floats
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <div class="stat-card-soft stat-card-soft-cash">
                <div class="stat-icon-soft">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Current Cash</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($current_cash); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-wallet"></i>
                        Branch cash
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <div class="stat-card-soft stat-card-soft-capital">
                <div class="stat-icon-soft">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Capital</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($current_capital); ?></span>
                    <span class="stat-sub-soft">
                        <i class="fas fa-calculator"></i>
                        Float + Cash
                    </span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2>
                    <i class="fas <?php echo $page_icon; ?>" style="color:<?php echo $theme_color; ?>;"></i> 
                    <?php echo $page_title; ?>
                </h2>
                <p class="text-muted">
                    <?php echo $type === 'deposit' ? 'Add money to provider float' : 'Withdraw money from provider float'; ?>
                </p>
            </div>
            <div class="header-right">
                <a href="transactions.php?type=deposit&branch_id=<?php echo $selected_branch; ?>" 
                   class="btn-action-big <?php echo $type === 'deposit' ? 'active' : ''; ?> btn-action-deposit">
                    <i class="fas fa-arrow-down"></i>
                    <span>Deposits</span>
                </a>
                
                <a href="transactions.php?type=withdrawal&branch_id=<?php echo $selected_branch; ?>" 
                   class="btn-action-big <?php echo $type === 'withdrawal' ? 'active' : ''; ?> btn-action-withdrawal">
                    <i class="fas fa-arrow-up"></i>
                    <span>Withdrawals</span>
                </a>
                
                <a href="index.php?branch_id=<?php echo $selected_branch; ?>" class="btn-action-big btn-action-back">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back</span>
                </a>
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

        <!-- SUMMARY CARDS -->
        <div class="summary-cards-soft">
            <div class="summary-card-soft summary-card-soft-deposit">
                <div class="summary-icon-soft">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Deposits</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($deposit_amount); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($deposit_count); ?> transactions
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-withdraw">
                <div class="summary-icon-soft">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Withdrawals</span>
                    <span class="summary-value-soft"><?php echo formatCurrency($withdrawal_amount); ?></span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($withdrawal_count); ?> transactions
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
        </div>

        <!-- ADD TRANSACTION FORM -->
        <div class="form-container">
            <div class="form-header" style="background: linear-gradient(135deg, <?php echo $theme_color; ?> 0%, <?php echo $theme_color; ?>dd 100%);">
                <div class="form-header-left">
                    <div class="form-header-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div>
                        <h3>Add New <?php echo $page_title; ?></h3>
                        <p>Jaza taarifa za <?php echo strtolower($page_title); ?> hapa chini</p>
                    </div>
                </div>
                <button type="button" class="btn-toggle-form" onclick="toggleForm()">
                    <i class="fas fa-chevron-up" id="formToggleIcon"></i>
                </button>
            </div>
            <form method="POST" action="" class="transaction-form" id="transactionForm">
                <input type="hidden" name="action" value="add_transaction">
                <input type="hidden" name="transaction_type" value="<?php echo $type; ?>">
                <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Provider <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <i class="fas fa-university input-icon"></i>
                            <select name="provider_id" id="providerSelect" class="form-control" required onchange="updateProviderBalance()">
                                <option value="">-- Select Provider --</option>
                                <?php foreach ($providers_list as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" 
                                            data-float="<?php echo floatval($p['current_float'] ?? 0); ?>"
                                            data-name="<?php echo htmlspecialchars($p['provider_name']); ?>"
                                            data-code="<?php echo htmlspecialchars($p['provider_code']); ?>">
                                        <?php echo htmlspecialchars($p['provider_name']); ?> 
                                        (<?php echo htmlspecialchars($p['provider_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Amount (TSh) <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <i class="fas fa-coins input-icon"></i>
                            <input type="number" name="amount" id="amountInput" class="form-control money-field" 
                                   placeholder="e.g., 100000" min="1" step="1" required
                                   oninput="updateAmountPreview()">
                        </div>
                    </div>
                </div>
                
                <div class="provider-balance-preview" id="providerPreview" style="display:none;">
                    <div class="preview-header">
                        <i class="fas fa-eye"></i> Provider Balance Preview
                    </div>
                    <div class="preview-grid">
                        <div class="preview-item">
                            <span class="preview-label">
                                <i class="fas fa-university"></i> Provider
                            </span>
                            <span class="preview-value" id="previewProviderName">-</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">
                                <i class="fas fa-coins"></i> Current Float
                            </span>
                            <span class="preview-value preview-float" id="previewCurrentFloat">TSh 0</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">
                                <i class="fas fa-arrow-<?php echo $type === 'deposit' ? 'up' : 'down'; ?>"></i> 
                                After <?php echo ucfirst($type); ?>
                            </span>
                            <span class="preview-value preview-new-float" id="previewNewFloat">TSh 0</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">
                                <i class="fas fa-calculator"></i> Change
                            </span>
                            <span class="preview-value preview-change" id="previewChange">TSh 0</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Transaction Date <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <i class="fas fa-calendar input-icon"></i>
                            <input type="date" name="transaction_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Reference Number</label>
                        <div class="input-with-icon">
                            <i class="fas fa-hashtag input-icon"></i>
                            <input type="text" name="reference_number" class="form-control" 
                                   placeholder="e.g., REF-12345">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <div class="input-with-icon">
                        <i class="fas fa-comment input-icon"></i>
                        <textarea name="description" class="form-control" rows="2" 
                                  placeholder="Maelezo ya transaction..."></textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="reset" class="btn btn-reset" onclick="resetPreview()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-submit-<?php echo $type; ?>">
                        <i class="fas fa-save"></i> 
                        Save <?php echo ucfirst($type); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- FILTERS -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <input type="hidden" name="type" value="<?php echo $type; ?>">
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> From Date</label>
                    <input type="date" name="from_date" value="<?php echo $from_date; ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-day"></i> To Date</label>
                    <input type="date" name="to_date" value="<?php echo $to_date; ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-store-alt"></i> Branch</label>
                    <select name="branch_id" class="form-control" onchange="this.form.submit()">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="transactions.php?type=<?php echo $type; ?>&branch_id=<?php echo $selected_branch; ?>" class="btn btn-reset-filter">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- TRANSACTIONS TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h3>
                    <i class="fas fa-list"></i>
                    <?php echo $page_title; ?> History
                    <span class="count-badge"><?php echo count($transactions); ?></span>
                </h3>
                <div class="table-search">
                    <i class="fas fa-search"></i>
                    <input type="text" 
                           id="txnSearchInput" 
                           placeholder="Search transaction #, provider, reference..."
                           oninput="searchTransactions(this)">
                    <button type="button" id="txnSearchClear" onclick="clearTxnSearch()" style="display:none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <?php if (count($transactions) > 0): ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Transaction #</th>
                                <th>Date</th>
                                <th>Provider</th>
                                <th>Code</th>
                                <th class="text-right">Amount</th>
                                <th>Reference</th>
                                <th>Branch</th>
                                <th>Employee</th>
                            </tr>
                        </thead>
                        <tbody id="transactionsTableBody">
                            <?php $i = 1; foreach ($transactions as $t): 
                                $color = $t['color_code'] ?? '#0B5ED7';
                                $icon = $t['icon_class'] ?? 'fas fa-university';
                                $search_data = strtolower(
                                    ($t['transaction_number'] ?? '') . ' ' .
                                    ($t['provider_name'] ?? '') . ' ' .
                                    ($t['provider_code'] ?? '') . ' ' .
                                    ($t['reference_number'] ?? '') . ' ' .
                                    ($t['employee_name'] ?? '') . ' ' .
                                    $t['amount']
                                );
                            ?>
                                <tr class="txn-row" data-search="<?php echo htmlspecialchars($search_data); ?>">
                                    <td><span class="row-number"><?php echo $i++; ?></span></td>
                                    <td>
                                        <span class="txn-number"><?php echo htmlspecialchars($t['transaction_number']); ?></span>
                                    </td>
                                    <td>
                                        <span class="date-cell">
                                            <i class="far fa-calendar"></i>
                                            <?php echo date('d M Y', strtotime($t['transaction_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="provider-cell">
                                            <div class="provider-icon" style="background:<?php echo $color; ?>;">
                                                <i class="<?php echo $icon; ?>"></i>
                                            </div>
                                            <span><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="code-badge"><?php echo htmlspecialchars($t['provider_code']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-badge <?php echo $type; ?>">
                                            <?php echo $type === 'deposit' ? '+' : '-'; ?> 
                                            <?php echo formatCurrency($t['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ref-number"><?php echo htmlspecialchars($t['reference_number'] ?: '-'); ?></span>
                                    </td>
                                    <td>
                                        <span class="branch-cell">
                                            <i class="fas fa-store-alt"></i>
                                            <?php echo htmlspecialchars($t['branch_display_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-cell">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="no-results" id="noResults" style="display:none;">
                        <i class="fas fa-search-minus"></i>
                        <h3>No results found</h3>
                        <p>No transactions match your search.</p>
                        <button type="button" class="btn btn-reset" onclick="clearTxnSearch()">
                            <i class="fas fa-times"></i> Clear Search
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No <?php echo strtolower($page_title); ?> found</h3>
                    <p>Hakuna <?php echo strtolower($page_title); ?> kwenye kipindi hiki.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content {
    overflow-x: hidden !important; max-width: 100% !important;
    width: 100% !important; padding: 16px 20px !important;
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
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
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}

body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 14px 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap;
    gap: 10px;
    width: 100%;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600;
    opacity: 0.75; text-transform: uppercase;
    letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name { font-weight: 700; font-size: 16px; color: #FFFFFF; }
.branch-indicator-code {
    font-size: 11px; font-weight: 600; color: #FFFFFF;
    padding: 3px 12px; background: rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.date-display {
    font-size: 12px; color: rgba(255,255,255,0.9);
    padding: 6px 14px; background: rgba(255, 255, 255, 0.12);
    border-radius: 16px; display: flex; align-items: center; gap: 6px;
    font-weight: 500;
}

/* STATS GRID - SOFT BACKGROUND */
.stats-grid-soft {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.stat-card-soft {
    position: relative;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.stat-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}

.stat-card-soft-float {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.stat-card-soft-float .stat-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.stat-card-soft-float .stat-value-soft { color: #1D4ED8; }

.stat-card-soft-cash {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.stat-card-soft-cash .stat-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.stat-card-soft-cash .stat-value-soft { color: #047857; }

.stat-card-soft-capital {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.stat-card-soft-capital .stat-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.stat-card-soft-capital .stat-value-soft { color: #6D28D9; }

html.dark-mode .stat-card-soft-float { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .stat-card-soft-cash { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .stat-card-soft-capital { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .stat-card-soft-float .stat-value-soft { color: #60A5FA; }
html.dark-mode .stat-card-soft-cash .stat-value-soft { color: #34D399; }
html.dark-mode .stat-card-soft-capital .stat-value-soft { color: #C4B5FD; }

.stat-icon-soft {
    width: 50px;
    height: 50px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.stat-card-soft:hover .stat-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.stat-info-soft {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}
.stat-label-soft {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-value-soft {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-word;
}
.stat-sub-soft {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}
.stat-sub-soft i { font-size: 9px; color: var(--text-light); }

.stat-decoration-soft {
    position: absolute;
    top: -30px; right: -30px;
    width: 100px; height: 100px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    pointer-events: none;
}

/* PAGE HEADER + BIG BUTTONS */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
}
.page-header .header-left h2 { font-size: 20px; font-weight: 700; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted { font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0; }
.page-header .header-right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

.btn-action-big {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 26px;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
    position: relative;
    overflow: hidden;
}
.btn-action-big i:first-child { font-size: 16px; }
.btn-action-big::before {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    width: 0; height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s ease, height 0.6s ease;
}
.btn-action-big:hover::before { width: 300px; height: 300px; }
.btn-action-big:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2); }
.btn-action-big > * { position: relative; z-index: 1; }

.btn-action-deposit {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: #FFFFFF;
}
.btn-action-deposit:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    color: #FFFFFF;
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.45);
}
.btn-action-deposit.active {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.3), 0 8px 24px rgba(5, 150, 105, 0.45);
    color: #FFFFFF;
}

.btn-action-withdrawal {
    background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%);
    color: #FFFFFF;
}
.btn-action-withdrawal:hover {
    background: linear-gradient(135deg, #B91C1C 0%, #DC2626 100%);
    color: #FFFFFF;
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.45);
}
.btn-action-withdrawal.active {
    background: linear-gradient(135deg, #B91C1C 0%, #DC2626 100%);
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.3), 0 8px 24px rgba(220, 38, 38, 0.45);
    color: #FFFFFF;
}

.btn-action-back {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-action-back:hover {
    background: var(--bg-card);
    color: var(--text-primary);
    border-color: #94A3B8;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

/* ALERTS */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideDown 0.4s ease forwards;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; line-height: 1.6; font-weight: 500; }
.alert-close {
    background: transparent; border: none;
    font-size: 22px; color: inherit; cursor: pointer;
    opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* SUMMARY CARDS */
.summary-cards-soft {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 18px;
}

.summary-card-soft {
    position: relative;
    border-radius: 14px;
    padding: 22px 26px;
    display: flex;
    align-items: center;
    gap: 18px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    min-height: 110px;
}
.summary-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}

.summary-card-soft-deposit {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.summary-card-soft-deposit .summary-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.summary-card-soft-deposit .summary-value-soft { color: #047857; }

.summary-card-soft-withdraw {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.summary-card-soft-withdraw .summary-icon-soft {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.summary-card-soft-withdraw .summary-value-soft { color: #B91C1C; }

html.dark-mode .summary-card-soft-deposit { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-card-soft-withdraw { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .summary-card-soft-deposit .summary-value-soft { color: #34D399; }
html.dark-mode .summary-card-soft-withdraw .summary-value-soft { color: #FCA5A5; }

.summary-icon-soft {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.summary-card-soft:hover .summary-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.summary-info-soft {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
    flex: 1;
}
.summary-label-soft {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--text-muted);
}
.summary-value-soft {
    font-size: 24px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    line-height: 1.15;
    word-break: break-all;
}
.summary-sub-soft {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
}
.summary-sub-soft i { font-size: 10px; color: var(--text-light); }

.summary-decoration-soft {
    position: absolute;
    top: -50px; right: -50px;
    width: 180px; height: 180px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
    pointer-events: none;
}

/* FORM CONTAINER */
.form-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}
.form-header {
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.form-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.form-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}
.form-header-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
}
.form-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: #FFFFFF;
}
.form-header p {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.85);
    margin: 2px 0 0 0;
}
.btn-toggle-form {
    width: 36px; height: 36px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}
.btn-toggle-form:hover { background: rgba(255, 255, 255, 0.25); transform: scale(1.05); }

.transaction-form { padding: 20px 24px; }
.transaction-form.hidden { display: none; }

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.form-group label {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-group label .required { color: #DC2626; }

.input-with-icon {
    position: relative;
    display: flex;
    align-items: center;
}
.input-icon {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}
.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    outline: none;
}
select.form-control {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 16px;
    padding-right: 40px;
}
textarea.form-control {
    padding: 12px 14px 12px 42px;
    resize: vertical;
    min-height: 70px;
    line-height: 1.5;
}
.form-control:focus {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    background: var(--bg-card);
}
.input-with-icon:focus-within .input-icon { color: #2563EB; }

.money-field {
    font-family: 'Inter', 'Courier New', monospace;
    font-weight: 800;
    font-size: 15px;
}

.form-actions {
    display: flex;
    gap: 12px;
    padding-top: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

/* PROVIDER BALANCE PREVIEW */
.provider-balance-preview {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    animation: slideDown 0.3s ease forwards;
    position: relative;
    overflow: hidden;
    width: 100%;
}
html.dark-mode .provider-balance-preview {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
.provider-balance-preview::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 200px; height: 200px;
    background: rgba(59, 130, 246, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.preview-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 800;
    color: #1E40AF;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
}
html.dark-mode .preview-header { color: #BFDBFE; }
.preview-header i { color: #2563EB; }

.preview-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    position: relative;
    z-index: 1;
}
.preview-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 10px;
    border: 1px solid rgba(147, 197, 253, 0.5);
    min-width: 0;
}
html.dark-mode .preview-item {
    background: rgba(15, 23, 42, 0.5);
    border-color: rgba(59, 130, 246, 0.4);
}
.preview-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #1E40AF;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
html.dark-mode .preview-label { color: #93C5FD; }
.preview-value {
    font-size: 14px;
    font-weight: 900;
    color: #1E293B;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    line-height: 1.2;
}
html.dark-mode .preview-value { color: #F1F5F9; }
.preview-float { color: #1D4ED8; }
html.dark-mode .preview-float { color: #60A5FA; }
.preview-new-float { color: #059669; }
html.dark-mode .preview-new-float { color: #34D399; }
.preview-change { color: #7C3AED; }
html.dark-mode .preview-change { color: #A78BFA; }

/* BUTTONS */
.btn {
    padding: 12px 22px;
    border: none;
    border-radius: 10px;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn-submit-deposit { 
    background: linear-gradient(135deg, #059669 0%, #10B981 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-submit-deposit:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.45);
    color: white;
}
.btn-submit-withdrawal { 
    background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}
.btn-submit-withdrawal:hover {
    background: linear-gradient(135deg, #B91C1C 0%, #DC2626 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.45);
    color: white;
}
.btn-reset {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset:hover { 
    background: var(--bg-card); 
    color: var(--text-primary);
    border-color: #94A3B8;
    transform: translateY(-2px);
}
.btn-filter { 
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%); 
    color: white; 
    box-shadow: 0 4px 12px rgba(187, 4, 4, 0.3);
}
.btn-filter:hover { 
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(187, 4, 4, 0.45);
    color: white;
}
.btn-reset-filter {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset-filter:hover {
    background: var(--bg-card);
    color: var(--text-primary);
}

/* FILTERS */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}
.filters-form {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.filter-group label {
    font-size: 11px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.filter-group label i { color: #bb0404; font-size: 11px; }
.filter-group .form-control {
    padding: 10px 14px;
    font-size: 13px;
    min-width: 160px;
}
.filter-group:last-child {
    display: flex;
    flex-direction: row;
    gap: 8px;
    align-items: flex-end;
}

/* TABLE */
.table-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}
.table-header {
    padding: 16px 22px;
    border-bottom: 1.5px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--bg-input);
    flex-wrap: wrap;
    gap: 12px;
}
.table-header h3 {
    font-size: 15px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.table-header h3 i { color: #bb0404; font-size: 16px; }
.count-badge {
    background: #1D4ED8;
    color: #FFFFFF;
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 800;
    margin-left: 4px;
}

.table-search {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 8px 14px;
    min-width: 280px;
    transition: all 0.25s ease;
}
.table-search:focus-within {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}
.table-search > i { color: #2563EB; font-size: 13px; }
.table-search input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    font-family: 'Inter', sans-serif;
    min-width: 0;
}
.table-search input::placeholder { color: var(--text-light); font-size: 12px; }
.table-search button {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.table-search button:hover { background: #DC2626; color: white; }

.table-wrapper { overflow-x: auto; width: 100%; max-width: 100%; }
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 900px;
}
.data-table thead {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
}
.data-table thead th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 800;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: rgba(187, 4, 4, 0.04); }
.data-table tbody tr:nth-child(even) { background: var(--bg-input); }
.data-table tbody td {
    padding: 14px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }

.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--bg-input);
    font-size: 11px;
    font-weight: 800;
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.txn-number {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 800;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 4px 10px;
    border-radius: 8px;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .txn-number { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.date-cell {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.date-cell i { color: #bb0404; font-size: 10px; }

.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon {
    width: 34px; height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

.code-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    background: #DBEAFE;
    color: #1D4ED8;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
}
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.amount-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 14px;
    border-radius: 8px;
    font-weight: 900;
    font-size: 13px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.amount-badge.deposit {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #86EFAC;
}
.amount-badge.withdrawal {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
html.dark-mode .amount-badge.deposit { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .amount-badge.withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.ref-number {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    color: var(--text-secondary);
}

.branch-cell, .employee-cell {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.branch-cell i { color: #bb0404; font-size: 10px; }
.employee-cell i { color: #2563EB; font-size: 10px; }

.txn-row.hidden-by-search { display: none !important; }

.no-results {
    text-align: center;
    padding: 50px 20px;
}
.no-results i {
    font-size: 56px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.no-results h3 {
    font-size: 18px;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.no-results p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0 0 16px 0;
}

.empty-state {
    text-align: center;
    padding: 70px 20px;
}
.empty-state i {
    font-size: 56px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 18px;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .stats-grid-soft { grid-template-columns: repeat(3, 1fr); }
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    
    .stats-grid-soft { grid-template-columns: 1fr; }
    .summary-cards-soft { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .preview-grid { grid-template-columns: 1fr; }
    
    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; min-width: 0; }
    .filter-group:last-child { flex-direction: column; width: 100%; }
    .filter-group:last-child .btn { width: 100%; justify-content: center; }
    
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; flex-direction: column; }
    .page-header .header-right .btn-action-big { width: 100%; justify-content: center; }
    
    .form-actions { flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; justify-content: center; }
    
    .table-search { width: 100%; min-width: 0; }
    .table-header { flex-direction: column; align-items: flex-start; }
}

@media (max-width: 480px) {
    .stat-value-soft { font-size: 16px; }
    .stat-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .summary-value-soft { font-size: 20px; }
    .summary-icon-soft { width: 52px; height: 52px; font-size: 22px; }
    .preview-value { font-size: 13px; }
    .btn-action-big { padding: 12px 20px; font-size: 12px; }
}
</style>

<script>
// ============================================================
// LIVE PROVIDER BALANCE PREVIEW
// ============================================================
function updateProviderBalance() {
    var select = document.getElementById('providerSelect');
    var preview = document.getElementById('providerPreview');
    var selectedOption = select.options[select.selectedIndex];
    
    if (!select.value) {
        preview.style.display = 'none';
        return;
    }
    
    var floatBalance = parseFloat(selectedOption.getAttribute('data-float')) || 0;
    var providerName = selectedOption.getAttribute('data-name') || '';
    var providerCode = selectedOption.getAttribute('data-code') || '';
    
    document.getElementById('previewProviderName').textContent = providerName + ' (' + providerCode + ')';
    document.getElementById('previewCurrentFloat').textContent = 'TSh ' + formatMoney(floatBalance);
    
    preview.style.display = 'block';
    
    updateAmountPreview();
}

function updateAmountPreview() {
    var select = document.getElementById('providerSelect');
    var amountInput = document.getElementById('amountInput');
    
    if (!select.value) return;
    
    var selectedOption = select.options[select.selectedIndex];
    var currentFloat = parseFloat(selectedOption.getAttribute('data-float')) || 0;
    var amount = parseFloat(amountInput.value) || 0;
    var type = '<?php echo $type; ?>';
    
    var newFloat = type === 'deposit' ? currentFloat + amount : currentFloat - amount;
    
    var newFloatEl = document.getElementById('previewNewFloat');
    var changeEl = document.getElementById('previewChange');
    
    newFloatEl.textContent = 'TSh ' + formatMoney(newFloat);
    changeEl.textContent = (type === 'deposit' ? '+' : '-') + ' TSh ' + formatMoney(amount);
    
    if (type === 'withdrawal' && newFloat < 0) {
        newFloatEl.style.color = '#DC2626';
        changeEl.style.color = '#DC2626';
    } else {
        newFloatEl.style.color = '';
        changeEl.style.color = '';
    }
}

function formatMoney(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function resetPreview() {
    document.getElementById('providerPreview').style.display = 'none';
}

// ============================================================
// TOGGLE FORM
// ============================================================
function toggleForm() {
    var form = document.getElementById('transactionForm');
    var icon = document.getElementById('formToggleIcon');
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    } else {
        form.classList.add('hidden');
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    }
}

// ============================================================
// SEARCH TRANSACTIONS
// ============================================================
function searchTransactions(input) {
    var searchTerm = input.value.toLowerCase().trim();
    var rows = document.querySelectorAll('.txn-row');
    var clearBtn = document.getElementById('txnSearchClear');
    var noResults = document.getElementById('noResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(function(row) { row.classList.remove('hidden-by-search'); });
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
    
    if (noResults) noResults.style.display = matchCount === 0 ? 'block' : 'none';
}

function clearTxnSearch() {
    var input = document.getElementById('txnSearchInput');
    if (input) {
        input.value = '';
        searchTransactions(input);
        input.focus();
    }
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
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
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    var form = document.getElementById('transactionForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var provider = document.querySelector('select[name="provider_id"]');
            var amount = document.querySelector('input[name="amount"]');
            
            if (!provider.value || !amount.value || parseFloat(amount.value) <= 0) {
                e.preventDefault();
                alert('Tafadhali chagua provider na weka amount sahihi.');
                return false;
            }
            
            var type = '<?php echo $type; ?>';
            var msg = type === 'deposit' 
                ? 'Confirm DEPOSIT ya TSh ' + parseFloat(amount.value).toLocaleString() + '?'
                : 'Confirm WITHDRAWAL ya TSh ' + parseFloat(amount.value).toLocaleString() + '?';
            
            if (!confirm(msg)) {
                e.preventDefault();
                return false;
            }
            
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                btn.disabled = true;
            }
        });
    }
});
</script>

</body>
</html>