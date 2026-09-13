<?php
// ================================================================
// FILE: modules/daily_report/index_employee.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEE DASHBOARD
// ✅ FLOAT: kutoka providers ZOTE za branch (sio zake tu)
// ✅ CASH: kutoka daily_reports.current_cash (branch cash)
// ✅ Transactions: ZAKE TU (employee_id = current user)
// ✅ Providers: ZOTE za branch yake
// ✅ REMOVED: "My Reports" section
// ✅ REMOVED: "Generate Report" button (auto-generated from morning report)
// ✅ NEW: Search filter in My Transactions header (RED background)
// ✅ NEW: Summary card "My Transactions" - inaonyesha idadi ya transactions
// ✅ Uses shared employee_sidebar.php & employee_header.php
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

if ($role !== 'employee') {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: ../../login.php');
    exit();
}

$employee_branch_id = $employee['branch_id'] ?? 0;

// ============================================================
// HANDLE AJAX REQUESTS (Deposit/Withdrawal)
// ============================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        // ============================================================
        // GET PROVIDER FLOAT
        // ============================================================
        if ($_POST['ajax_action'] === 'get_provider_float') {
            $provider_id = intval($_POST['provider_id'] ?? 0);
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            if ($provider_id <= 0 || $branch_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid provider or branch']);
                exit();
            }
            
            $stmt = $db->prepare("
                SELECT 1 FROM branch_providers 
                WHERE branch_id = ? AND provider_id = ? AND is_active = 1
            ");
            $stmt->execute([$branch_id, $provider_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Provider haipo kwenye branch yako.']);
                exit();
            }
            
            $stmt = $db->prepare("
                SELECT drp.current_float
                FROM daily_report_providers drp
                INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
                WHERE dr.branch_id = ? 
                AND drp.provider_id = ?
                ORDER BY dr.report_date DESC, dr.id DESC 
                LIMIT 1
            ");
            $stmt->execute([$branch_id, $provider_id]);
            $drp = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_float = floatval($drp['current_float'] ?? 0);
            
            $stmt = $db->prepare("
                SELECT current_cash FROM daily_reports 
                WHERE branch_id = ? 
                ORDER BY report_date DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute([$branch_id]);
            $dr_cash = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_cash = floatval($dr_cash['current_cash'] ?? 0);
            
            echo json_encode([
                'success' => true,
                'float' => $current_float,
                'cash' => $current_cash,
                'formatted_float' => formatCurrency($current_float),
                'formatted_cash' => formatCurrency($current_cash)
            ]);
            exit();
        }
        
        // ============================================================
        // ADD TRANSACTION
        // ============================================================
        if ($_POST['ajax_action'] === 'add_transaction') {
            $branch_id = intval($_POST['branch_id'] ?? 0);
            $provider_id = intval($_POST['provider_id'] ?? 0);
            $amount_raw = $_POST['amount'] ?? '0';
            $amount = floatval(str_replace(',', '', $amount_raw));
            
            $transaction_type = $_POST['transaction_type'] ?? 'deposit';
            $reference_number = trim($_POST['reference_number'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
            
            if ($branch_id !== $employee_branch_id) {
                throw new Exception('Hauna ruhusa kufanya transaction kwa branch nyingine.');
            }
            
            $stmt = $db->prepare("
                SELECT 1 FROM branch_providers 
                WHERE branch_id = ? AND provider_id = ? AND is_active = 1
            ");
            $stmt->execute([$branch_id, $provider_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Provider haipo kwenye branch yako.');
            }
            
            if ($amount <= 0) throw new Exception('Amount must be greater than 0.');
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT * FROM providers WHERE id = ? AND is_active = 1");
            $stmt->execute([$provider_id]);
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$provider) throw new Exception('Provider not found.');
            
            $stmt = $db->prepare("SELECT * FROM branch_providers WHERE branch_id = ? AND provider_id = ? AND is_active = 1");
            $stmt->execute([$branch_id, $provider_id]);
            $branch_provider = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$branch_provider) throw new Exception('Provider haipo kwenye branch yako.');
            
            $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $branch = $stmt->fetch(PDO::FETCH_ASSOC);
            $branch_name = $branch['branch_name'] ?? 'Main';
            
            $stmt = $db->prepare("
                SELECT * FROM daily_reports 
                WHERE branch_id = ? 
                ORDER BY report_date DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute([$branch_id]);
            $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$latest_dr) {
                throw new Exception('Hakuna daily report. Tafadhali tengeneza morning report kwanza.');
            }
            
            $daily_report_id = $latest_dr['id'];
            $current_cash = floatval($latest_dr['current_cash'] ?? 0);
            
            $stmt = $db->prepare("
                SELECT * FROM daily_report_providers 
                WHERE daily_report_id = ? AND provider_id = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$daily_report_id, $provider_id]);
            $dr_provider = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dr_provider) {
                $current_float = floatval($dr_provider['current_float'] ?? 0);
            } else {
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
            
            if ($transaction_type === 'withdrawal') {
                $new_float = $current_float - $amount;
                $new_cash = $current_cash + $amount;
            } else {
                $new_float = $current_float + $amount;
                $new_cash = $current_cash - $amount;
            }
            
            if ($new_float < 0) {
                throw new Exception('Float haitoshi. Current float: TSh ' . number_format($current_float, 0));
            }
            if ($new_cash < 0) {
                throw new Exception('Cash haitoshi. Current cash: TSh ' . number_format($current_cash, 0));
            }
            
            $new_capital = $new_float + $new_cash;
            
            $prefix = $transaction_type === 'deposit' ? 'DEP' : 'WTH';
            $transaction_number = $prefix . '-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
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
                "Float: " . number_format($current_float, 0) . " → " . number_format($new_float, 0)
            ]);
            
            $transaction_id = $db->lastInsertId();
            
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
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = ?,
                    current_capital = ?,
                    total_deposits = total_deposits + ?,
                    total_withdrawals = total_withdrawals + ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $new_cash,
                $new_capital,
                $transaction_type === 'deposit' ? $amount : 0,
                $transaction_type === 'withdrawal' ? $amount : 0,
                $daily_report_id
            ]);
            
            logActivity(
                $user_id,
                'Add ' . ucfirst($transaction_type),
                'Transactions',
                $transaction_id,
                '',
                ucfirst($transaction_type) . ' of TSh ' . number_format($amount) . ' from ' . $provider['provider_name']
            );
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => ucfirst($transaction_type) . ' ya TSh ' . number_format($amount) . ' imefanikiwa!',
                'transaction' => [
                    'id' => $transaction_id,
                    'new_float' => $new_float,
                    'new_cash' => $new_cash,
                    'formatted_amount' => formatCurrency($amount),
                    'formatted_float' => formatCurrency($new_float),
                    'formatted_cash' => formatCurrency($new_cash)
                ]
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
// BRANCH INFO
// ============================================================
$selected_branch = $employee_branch_id;

$branch_name = 'My Branch';
$branch_code = '';
$branch_location = '';
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

try {
    // ============================================================
    // ✅ GET MY TRANSACTIONS TU (employee_id = current user)
    // ============================================================
    $sql_transactions = "
        SELECT 
            t.*,
            p.provider_name,
            p.icon_class,
            p.color_code
        FROM transactions t
        LEFT JOIN providers p ON t.provider_id = p.id
        WHERE t.branch_id = ?
        AND t.employee_id = ?
        AND DATE(t.transaction_date) BETWEEN ? AND ?
        ORDER BY t.created_at DESC
        LIMIT 100
    ";
    $stmt = $db->prepare($sql_transactions);
    $stmt->execute([$employee_branch_id, $user_id, $from_date, $to_date]);
    $my_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary ya transactions
    $total_deposits_actual = 0;
    $total_withdrawals_actual = 0;
    foreach ($my_transactions as $txn) {
        if ($txn['transaction_type'] === 'deposit') {
            $total_deposits_actual += floatval($txn['amount']);
        } else {
            $total_withdrawals_actual += floatval($txn['amount']);
        }
    }

    // ✅ Idadi ya transactions zangu
    $my_transactions_count = count($my_transactions);

    // ============================================================
    // ✅ TOTAL FLOAT — kutoka providers ZOTE za branch
    // ============================================================
    $sql_float = "SELECT 
                    COALESCE(SUM(drp.current_float), 0) as total_float
                  FROM daily_report_providers drp
                  INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
                  WHERE dr.branch_id = ?
                  AND dr.report_date BETWEEN ? AND ?";
    $stmt = $db->prepare($sql_float);
    $stmt->execute([$employee_branch_id, $from_date, $to_date]);
    $float_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_float = floatval($float_result['total_float'] ?? 0);

    // ============================================================
    // ✅ TOTAL CASH — kutoka daily_reports.current_cash (branch cash)
    // ============================================================
    $stmt = $db->prepare("
        SELECT current_cash FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$employee_branch_id]);
    $dr_cash = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_cash = floatval($dr_cash['current_cash'] ?? 0);

    // ============================================================
    // ✅ TOTAL CAPITAL = Float + Cash
    // ============================================================
    $total_capital = $total_float + $total_cash;

    // ============================================================
    // PROVIDERS ZOTE ZA BRANCH — kwa Modal
    // ============================================================
    $providers_for_modal = [];
    if ($employee_branch_id > 0) {
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.provider_name,
                p.provider_code as main_code,
                p.icon_class,
                p.color_code,
                bp.provider_code as branch_provider_code
            FROM providers p
            INNER JOIN branch_providers bp 
                ON p.id = bp.provider_id 
                AND bp.branch_id = ?
                AND bp.is_active = 1
            WHERE p.is_active = 1
            ORDER BY p.display_order, p.provider_name
        ");
        $stmt->execute([$employee_branch_id]);
        $providers_for_modal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $providers_for_modal = [];
    $my_transactions = [];
    $my_transactions_count = 0;
    $total_float = 0;
    $total_cash = 0;
    $total_capital = 0;
    $total_deposits_actual = 0;
    $total_withdrawals_actual = 0;
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- Branch Card -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">My Branch</span>
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
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- Capital Card -->
        <div class="capital-card-compact">
            <div class="capital-compact-icon">
                <i class="fas fa-university"></i>
            </div>
            <div class="capital-compact-content">
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-coins"></i> Branch Float
                    </span>
                    <span class="capital-compact-value capital-float-value" id="totalFloatDisplay">
                        <?php echo formatCurrency($total_float); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-money-bill-wave"></i> Branch Cash
                    </span>
                    <span class="capital-compact-value capital-cash-value" id="totalCashDisplay">
                        <?php echo formatCurrency($total_cash); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-building"></i> Branch Total Capital
                    </span>
                    <span class="capital-compact-value capital-capital-value" id="totalCapitalDisplay">
                        <?php echo formatCurrency($total_capital); ?>
                    </span>
                </div>
            </div>
            <div class="capital-compact-badge">
                <i class="fas fa-store"></i> Branch Capital
            </div>
        </div>

        <!-- Page Header (Generate button REMOVED) -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-exchange-alt" style="color:#bb0404;"></i> My Transactions</h2>
                <p class="text-muted">
                    <i class="fas fa-info-circle"></i> 
                    Daily report inaundwa automatically baada ya ku-submit morning report
                </p>
            </div>
            <div class="header-right">
                <button type="button" class="btn btn-deposit" onclick="openTransactionModal('deposit')">
                    <i class="fas fa-arrow-down"></i> Add Deposit
                </button>
                
                <button type="button" class="btn btn-withdrawal" onclick="openTransactionModal('withdrawal')">
                    <i class="fas fa-arrow-up"></i> Add Withdrawal
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <!-- ✅ CARD 1: MY TRANSACTIONS (count) - BADALA YA "My Reports" -->
            <div class="summary-card summary-card-transactions">
                <div class="summary-icon-wrapper summary-icon-blue">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">My Transactions</span>
                    <span class="summary-value"><?php echo number_format($my_transactions_count); ?></span>
                </div>
            </div>
            
            <!-- ✅ CARD 2: MY DEPOSITS (amount) -->
            <div class="summary-card summary-card-deposits">
                <div class="summary-icon-wrapper summary-icon-green">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">My Deposits</span>
                    <span class="summary-value" id="totalDepositsDisplay"><?php echo formatCurrency($total_deposits_actual); ?></span>
                </div>
            </div>
            
            <!-- ✅ CARD 3: MY WITHDRAWALS (amount) -->
            <div class="summary-card summary-card-withdrawals">
                <div class="summary-icon-wrapper summary-icon-red">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">My Withdrawals</span>
                    <span class="summary-value" id="totalWithdrawalsDisplay"><?php echo formatCurrency($total_withdrawals_actual); ?></span>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index_employee.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        MY TRANSACTIONS (ZAKE TU) - WITH RED HEADER + SEARCH
        ============================================================ -->
        <div class="section-container">
            <!-- RED HEADER WITH SEARCH -->
            <div class="section-header-red">
                <h3>
                    <i class="fas fa-exchange-alt"></i>
                    My Transactions
                    <span class="section-count-red"><?php echo count($my_transactions); ?></span>
                </h3>
                
                <!-- SEARCH FILTER -->
                <div class="search-input-group-red">
                    <i class="fas fa-search"></i>
                    <input type="text" 
                           id="txnSearchInput" 
                           placeholder="Search provider, reference, amount..."
                           oninput="onTxnSearch(this)">
                    <button type="button" id="txnSearchClear" onclick="clearTxnSearch()" style="display:none;">
                        <i class="fas fa-times"></i>
                    </button>
                    <span class="search-count-red" id="txnSearchCount" style="display:none;">0</span>
                </div>
            </div>
            
            <?php if (count($my_transactions) > 0): ?>
            <div class="txn-table-wrapper">
                <table class="txn-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Provider</th>
                            <th>Type</th>
                            <th class="text-right">Amount</th>
                            <th>Reference</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody id="txnTableBody">
                        <?php $i = 1; foreach ($my_transactions as $txn): 
                            $is_deposit = $txn['transaction_type'] === 'deposit';
                            $color = $txn['color_code'] ?? '#0B5ED7';
                            $icon = $txn['icon_class'] ?? 'fas fa-university';
                            $txn_datetime = $txn['created_at'] ?? $txn['transaction_date'];
                            
                            // Search data
                            $txn_search = strtolower(
                                ($txn['provider_name'] ?? '') . ' ' .
                                ($txn['reference_number'] ?? '') . ' ' .
                                ($txn['description'] ?? '') . ' ' .
                                ($txn['transaction_number'] ?? '') . ' ' .
                                $txn['amount'] . ' ' .
                                $txn['transaction_type']
                            );
                        ?>
                            <tr class="txn-row" data-search="<?php echo htmlspecialchars($txn_search); ?>">
                                <td class="row-num"><?php echo $i++; ?></td>
                                <td>
                                    <span class="txn-date">
                                        <i class="far fa-calendar"></i>
                                        <?php echo date('d M Y', strtotime($txn_datetime)); ?>
                                        <br>
                                        <small><?php echo date('h:i A', strtotime($txn_datetime)); ?></small>
                                    </span>
                                </td>
                                <td>
                                    <div class="txn-provider-cell">
                                        <div class="txn-provider-icon" style="background:<?php echo $color; ?>;">
                                            <i class="<?php echo $icon; ?>"></i>
                                        </div>
                                        <span><?php echo htmlspecialchars($txn['provider_name'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="txn-type-badge <?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                                        <i class="fas fa-arrow-<?php echo $is_deposit ? 'down' : 'up'; ?>"></i>
                                        <?php echo $is_deposit ? 'Deposit' : 'Withdrawal'; ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <span class="txn-amount <?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                                        <?php echo $is_deposit ? '+' : '-'; ?> 
                                        <?php echo formatCurrency($txn['amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="txn-ref"><?php echo htmlspecialchars($txn['reference_number'] ?: '-'); ?></span>
                                </td>
                                <td>
                                    <span class="txn-desc"><?php echo htmlspecialchars($txn['description'] ?: '-'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- No Results Message -->
                <div class="no-txn-results" id="noTxnResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No transactions match your search</p>
                    <button type="button" class="btn btn-reset" onclick="clearTxnSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>
            <?php else: ?>
                <div class="empty-txn">
                    <i class="fas fa-inbox"></i>
                    <p>Hakuna transactions bado. Anza kwa ku-add deposit au withdrawal.</p>
                    <p style="margin-top: 8px; font-size: 12px; color: var(--text-light);">
                        <i class="fas fa-info-circle"></i> 
                        Daily report inaundwa automatically baada ya ku-submit morning report
                    </p>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<!-- ============================================================
   TRANSACTION MODAL
   ============================================================ -->
<div class="txn-modal-overlay" id="txnModalOverlay" onclick="closeTransactionModal(event)">
    <div class="txn-modal" onclick="event.stopPropagation()">
        
        <div class="txn-modal-header" id="txnModalHeader">
            <div class="txn-modal-header-icon" id="txnModalIcon">
                <i class="fas fa-arrow-down"></i>
            </div>
            <div class="txn-modal-header-content">
                <h3 id="txnModalTitle">Add Deposit</h3>
                <p id="txnModalSubtitle">Select provider and enter amount</p>
            </div>
            <button type="button" class="txn-modal-close" onclick="closeTransactionModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="txn-modal-body">
            
            <div id="txnModalMessage" class="txn-modal-message" style="display:none;"></div>
            
            <div class="txn-provider-info" id="txnProviderInfo" style="display:none;">
                <div class="txn-provider-icon" id="txnProviderIcon">
                    <i class="fas fa-university"></i>
                </div>
                <div class="txn-provider-details">
                    <span class="txn-provider-label">Selected Provider</span>
                    <span class="txn-provider-name" id="txnProviderName">-</span>
                    <span class="txn-provider-code" id="txnProviderCode">-</span>
                </div>
            </div>
            
            <form id="txnForm" onsubmit="submitTransaction(event)">
                <input type="hidden" name="ajax_action" value="add_transaction">
                <input type="hidden" name="transaction_type" id="txnType" value="deposit">
                <input type="hidden" name="branch_id" value="<?php echo $employee_branch_id; ?>">
                
                <div class="txn-form-group">
                    <label>Provider <span class="required">*</span></label>
                    <select name="provider_id" id="txnProviderSelect" class="txn-form-control" required onchange="onProviderChange()">
                        <option value="">-- Select Provider --</option>
                        <?php foreach ($providers_for_modal as $p): ?>
                            <option value="<?php echo $p['id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($p['provider_name']); ?>"
                                    data-code="<?php echo htmlspecialchars($p['branch_provider_code'] ?? $p['main_code']); ?>"
                                    data-icon="<?php echo htmlspecialchars($p['icon_class'] ?? 'fas fa-university'); ?>"
                                    data-color="<?php echo htmlspecialchars($p['color_code'] ?? '#0B5ED7'); ?>">
                                <?php echo htmlspecialchars($p['provider_name']); ?> 
                                (<?php echo htmlspecialchars($p['branch_provider_code'] ?? $p['main_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="txn-balance-preview" id="txnBalancePreview" style="display:none;">
                    <div class="txn-balance-item">
                        <span class="txn-balance-label">
                            <i class="fas fa-coins"></i> Current Float
                        </span>
                        <span class="txn-balance-value txn-float-value" id="txnCurrentFloat">TSh 0</span>
                    </div>
                    <div class="txn-balance-item">
                        <span class="txn-balance-label">
                            <i class="fas fa-money-bill-wave"></i> Current Cash
                        </span>
                        <span class="txn-balance-value txn-cash-value" id="txnCurrentCash">TSh 0</span>
                    </div>
                    <div class="txn-balance-item">
                        <span class="txn-balance-label">
                            <i class="fas fa-arrow-right"></i> After
                        </span>
                        <span class="txn-balance-value txn-after-value" id="txnAfterFloat">TSh 0</span>
                    </div>
                </div>
                
                <div class="txn-form-group">
                    <label>Amount (TSh) <span class="required">*</span></label>
                    <input type="text" 
                           name="amount" 
                           id="txnAmount" 
                           class="txn-form-control txn-amount-input" 
                           placeholder="1,000,000" 
                           inputmode="numeric"
                           autocomplete="off"
                           required
                           oninput="formatMoneyInput(this); updateBalancePreview();">
                </div>
                
                <div class="txn-form-group">
                    <label>Transaction Date <span class="required">*</span></label>
                    <input type="date" 
                           name="transaction_date" 
                           class="txn-form-control" 
                           value="<?php echo date('Y-m-d'); ?>" 
                           required>
                </div>
                
                <div class="txn-form-group">
                    <label>Reference Number</label>
                    <input type="text" 
                           name="reference_number" 
                           class="txn-form-control" 
                           placeholder="Optional">
                </div>
                
                <div class="txn-form-group">
                    <label>Description</label>
                    <textarea name="description" 
                              class="txn-form-control" 
                              rows="2" 
                              placeholder="Optional notes..."></textarea>
                </div>
                
                <div class="txn-form-actions">
                    <button type="button" class="txn-btn txn-btn-cancel" onclick="closeTransactionModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="txn-btn txn-btn-submit" id="txnSubmitBtn">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html { width: 100%; overflow-x: hidden; }
body { width: 100%; overflow-x: hidden; margin: 0; padding: 0; }

.main-wrapper {
    margin-left: 240px;
    width: calc(100% - 240px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--bg-body);
    transition: margin-left 0.3s ease, width 0.3s ease;
    overflow-x: hidden;
    position: relative;
}
.main-content {
    padding: 20px 24px;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

@media (max-width: 1024px) {
    .main-wrapper { margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; }
    .main-content { padding: 16px 18px; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 50px; }
    .main-content { padding: 16px 14px; width: 100%; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 44px; width: 100%; }
    .main-content { padding: 12px 10px; width: 100%; }
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
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
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* BRANCH CARD */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px; padding: 12px 20px; margin-bottom: 12px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 3px 12px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 10px; width: 100%;
}
.branch-indicator-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper {
    width: 38px; height: 38px; background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 16px; color: #FFFFFF; flex-shrink: 0;
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.branch-indicator-label { font-size: 10px; font-weight: 500; opacity: 0.7; text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF; }
.branch-indicator-name { font-weight: 700; font-size: 14px; color: #FFFFFF; }
.branch-indicator-code { font-size: 10px; font-weight: 600; color: #FFFFFF; padding: 2px 10px; background: rgba(255, 255, 255, 0.15); border-radius: 12px; }
.branch-location { display: flex; align-items: center; gap: 5px; font-size: 11px; color: rgba(255,255,255,0.85); padding: 3px 10px; background: rgba(255, 255, 255, 0.08); border-radius: 12px; }
.date-display { font-size: 12px; color: rgba(255,255,255,0.85); padding: 5px 12px; background: rgba(255, 255, 255, 0.1); border-radius: 16px; display: flex; align-items: center; gap: 5px; }

/* CAPITAL CARD */
.capital-card-compact {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 14px; padding: 20px 26px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 22px;
    box-shadow: 0 6px 24px rgba(30, 64, 175, 0.35);
    flex-wrap: wrap; position: relative; overflow: hidden; width: 100%;
}
.capital-compact-icon {
    width: 60px; height: 60px; background: rgba(255,255,255,0.18);
    border-radius: 14px; display: flex; align-items: center;
    justify-content: center; font-size: 26px; color: #FFFFFF;
    flex-shrink: 0; border: 1.5px solid rgba(255, 255, 255, 0.2);
}
.capital-compact-content { flex: 1; min-width: 0; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; }
.capital-compact-item { display: flex; flex-direction: column; gap: 6px; border-left: 3px solid rgba(255, 255, 255, 0.15); padding-left: 16px; transition: all 0.3s ease; }
.capital-compact-item:hover { border-left-color: #FCD34D; transform: translateX(4px); }
.capital-compact-label { font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.85); text-transform: uppercase; letter-spacing: 1.2px; display: flex; align-items: center; gap: 6px; }
.capital-compact-label i { color: #FCD34D; font-size: 12px; }
.capital-compact-value { font-size: 26px; font-weight: 900; letter-spacing: 0.5px; font-family: 'Inter', 'Courier New', monospace; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25); word-break: break-word; }
.capital-float-value { color: #FCD34D !important; }
.capital-cash-value { color: #86EFAC !important; }
.capital-capital-value { color: #C4B5FD !important; }
.capital-compact-badge { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: rgba(252, 211, 77, 0.22); color: #FCD34D; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; border: 1.5px solid rgba(252, 211, 77, 0.35); }

/* PAGE HEADER */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; width: 100%; }
.page-header .header-left h2 { font-size: 20px; font-weight: 700; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted { font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0; }
.header-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

/* BUTTONS */
.btn { padding: 8px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s ease; font-family: 'Inter', sans-serif; white-space: nowrap; }
.btn-deposit { background: #059669; color: white; }
.btn-deposit:hover { background: #047857; transform: translateY(-1px); color: white; }
.btn-withdrawal { background: #DC2626; color: white; }
.btn-withdrawal:hover { background: #B91C1C; transform: translateY(-1px); color: white; }
.btn-filter { background: #bb0404; color: white; }
.btn-filter:hover { background: #8a0303; color: white; }
.btn-reset { background: var(--bg-table-even); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-reset:hover { background: var(--bg-table-hover); color: var(--text-primary); }

/* SUMMARY CARDS */
.summary-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 18px; width: 100%; }
.summary-card { background: var(--bg-card); border-radius: 14px; padding: 18px 22px; border: 1.5px solid var(--border-color); display: flex; align-items: center; gap: 16px; transition: all 0.3s ease; box-shadow: 0 2px 8px var(--shadow-color); min-width: 0; }
.summary-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px var(--shadow-hover); }

/* ✅ NEW: Transactions Card - Purple/Blue */
.summary-card-transactions { border-left: 4px solid #7C3AED; }
.summary-card-deposits { border-left: 4px solid #059669; }
.summary-card-withdrawals { border-left: 4px solid #DC2626; }

.summary-icon-wrapper { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.summary-icon-blue { background: linear-gradient(135deg, #EDE9FE, #DDD6FE); color: #7C3AED; }
.summary-icon-green { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669; }
.summary-icon-red { background: linear-gradient(135deg, #FEE2E2, #FECACA); color: #DC2626; }

html.dark-mode .summary-icon-blue { background: linear-gradient(135deg, #4C1D95, #5B21B6); color: #C4B5FD; }
html.dark-mode .summary-icon-green { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; }
html.dark-mode .summary-icon-red { background: linear-gradient(135deg, #7F1D1D, #991B1B); color: #FCA5A5; }

.summary-info { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.summary-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; }
.summary-value { font-size: 22px; font-weight: 900; color: var(--text-primary); font-family: 'Inter', 'Courier New', monospace; word-break: break-word; }

/* FILTERS */
.filters-bar { background: var(--bg-card); padding: 14px 18px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 16px; width: 100%; }
.filters-form { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
.form-control { padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 12px; color: var(--text-primary); background: var(--bg-input); }

/* ============================================================
   SECTION CONTAINER
   ============================================================ */
.section-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
    overflow: hidden;
}

/* ============================================================
   RED SECTION HEADER WITH SEARCH
   ============================================================ */
.section-header-red {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    padding: 16px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(187, 4, 4, 0.25);
    position: relative;
    overflow: hidden;
}

.section-header-red::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.section-header-red h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #FFFFFF;
    position: relative;
    z-index: 1;
}

.section-header-red h3 i {
    color: #FCD34D;
    font-size: 18px;
}

.section-count-red {
    font-size: 12px;
    font-weight: 800;
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
    padding: 4px 12px;
    border-radius: 12px;
    margin-left: 6px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(4px);
}

/* Search Input kwenye Red Header */
.search-input-group-red {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    padding: 8px 14px;
    width: 320px;
    max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    position: relative;
    z-index: 1;
}

.search-input-group-red:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
}

.search-input-group-red i {
    color: #bb0404;
    font-size: 13px;
    flex-shrink: 0;
}

.search-input-group-red input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 13px;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-family: 'Inter', sans-serif;
}

.search-input-group-red input::placeholder {
    color: #9CA3AF;
    font-size: 12px;
}

.search-input-group-red button {
    width: 22px;
    height: 22px;
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

.search-input-group-red button:hover {
    background: #DC2626;
    color: #FFFFFF;
    transform: scale(1.1);
}

.search-count-red {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    flex-shrink: 0;
    white-space: nowrap;
}

/* Dark mode for red header */
html.dark-mode .search-input-group-red {
    background: rgba(30, 41, 59, 0.95);
    border-color: rgba(255, 255, 255, 0.2);
}

html.dark-mode .search-input-group-red input {
    color: #F1F5F9;
}

html.dark-mode .search-input-group-red input::placeholder {
    color: #64748B;
}

/* ============================================================
   TRANSACTIONS TABLE
   ============================================================ */
.txn-table-wrapper {
    overflow-x: auto;
    width: 100%;
    -webkit-overflow-scrolling: touch;
    padding: 0;
}
.txn-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 900px;
}
.txn-table thead tr { background: var(--bg-table-even); }
.txn-table thead th {
    padding: 11px 14px;
    text-align: left;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 10px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.txn-table thead th.text-right { text-align: right; }
.txn-table tbody tr { border-bottom: 1px solid var(--border-color); transition: background 0.2s ease; }
.txn-table tbody tr:hover { background: var(--bg-table-hover); }
.txn-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.txn-table tbody td { padding: 11px 14px; color: var(--text-primary); vertical-align: middle; }
.txn-table tbody td.text-right { text-align: right; }
.txn-table tbody tr:last-child { border-bottom: none; }

.txn-row.hidden-by-search {
    display: none !important;
}

.row-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--bg-table-hover);
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.txn-date { font-size: 11px; font-weight: 600; color: var(--text-secondary); display: inline-block; line-height: 1.3; }
.txn-date small { font-size: 10px; color: var(--text-muted); }

.txn-provider-cell { display: flex; align-items: center; gap: 10px; }
.txn-provider-icon {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 12px; flex-shrink: 0;
}

.txn-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.txn-type-badge.deposit { background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0; }
.txn-type-badge.withdrawal { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .txn-type-badge.deposit { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .txn-type-badge.withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.txn-amount {
    font-family: 'Courier New', monospace;
    font-weight: 800;
    font-size: 13px;
    white-space: nowrap;
}
.txn-amount.deposit { color: #15803D; }
.txn-amount.withdrawal { color: #991B1B; }
html.dark-mode .txn-amount.deposit { color: #4ADE80; }
html.dark-mode .txn-amount.withdrawal { color: #FCA5A5; }

.txn-ref {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    color: var(--text-secondary);
}

.txn-desc {
    font-size: 11px;
    color: var(--text-muted);
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: block;
}

/* Empty State */
.empty-txn {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}
.empty-txn i {
    font-size: 48px;
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.empty-txn p { font-size: 13px; margin: 0; }

/* No Results */
.no-txn-results {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.no-txn-results i {
    font-size: 48px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}

.no-txn-results p {
    font-size: 14px;
    margin: 0 0 16px 0;
    color: var(--text-muted);
}

/* ============================================================
   TRANSACTION MODAL
   ============================================================ */
.txn-modal-overlay {
    display: none; position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    justify-content: center; align-items: center;
    padding: 20px; overflow-y: auto;
}
.txn-modal-overlay.show { display: flex; animation: fadeIn 0.2s ease forwards; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.txn-modal { background: var(--bg-card); border-radius: 16px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4); animation: slideUp 0.3s ease forwards; border: 1px solid var(--border-color); }
.txn-modal-header { padding: 20px 24px; border-radius: 16px 16px 0 0; display: flex; align-items: center; gap: 16px; position: relative; overflow: hidden; color: white; }
.txn-modal-header.txn-header-deposit { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.txn-modal-header.txn-header-withdrawal { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }
.txn-modal-header-icon { width: 52px; height: 52px; background: rgba(255, 255, 255, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; border: 2px solid rgba(255, 255, 255, 0.25); }
.txn-modal-header-content { flex: 1; min-width: 0; }
.txn-modal-header-content h3 { font-size: 18px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.txn-modal-header-content p { font-size: 12px; margin: 0; color: rgba(255, 255, 255, 0.85); }
.txn-modal-close { width: 36px; height: 36px; border-radius: 50%; background: rgba(255, 255, 255, 0.15); color: #FFFFFF; border: 1px solid rgba(255, 255, 255, 0.2); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; transition: all 0.2s ease; flex-shrink: 0; }
.txn-modal-close:hover { background: rgba(255, 255, 255, 0.3); transform: rotate(90deg); }
.txn-modal-body { padding: 24px; }
.txn-modal-message { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
.txn-modal-message.success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.txn-modal-message.error { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
.txn-provider-info { background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%); border: 2px solid #93C5FD; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; display: flex; align-items: center; gap: 14px; }
html.dark-mode .txn-provider-info { background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%); border-color: #3B82F6; }
.txn-provider-icon { width: 44px; height: 44px; border-radius: 50%; background: #0B5ED7; color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.txn-provider-details { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.txn-provider-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #1E40AF; }
html.dark-mode .txn-provider-label { color: #93C5FD; }
.txn-provider-name { font-size: 15px; font-weight: 800; color: #1E293B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
html.dark-mode .txn-provider-name { color: #F1F5F9; }
.txn-provider-code { font-size: 11px; font-weight: 700; color: #1D4ED8; background: #FFFFFF; padding: 2px 10px; border-radius: 8px; align-self: flex-start; font-family: 'Courier New', monospace; }
html.dark-mode .txn-provider-code { background: #0F172A; color: #60A5FA; }
.txn-form-group { margin-bottom: 16px; }
.txn-form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.txn-form-group label .required { color: #DC2626; }
.txn-form-control { width: 100%; padding: 11px 14px; border: 1.5px solid var(--border-color); border-radius: 10px; font-size: 13px; color: var(--text-primary); background: var(--bg-input); font-family: 'Inter', sans-serif; transition: all 0.3s ease; }
.txn-form-control:focus { outline: none; border-color: #2563EB; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); background: var(--bg-card); }
.txn-amount-input { font-size: 20px !important; font-weight: 800; font-family: 'Inter', 'Courier New', monospace; letter-spacing: 1px; text-align: right; }
.txn-balance-preview { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border: 2px solid #FCD34D; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
html.dark-mode .txn-balance-preview { background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%); border-color: #F59E0B; }
.txn-balance-item { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.txn-balance-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #92400E; display: flex; align-items: center; gap: 5px; }
html.dark-mode .txn-balance-label { color: #FCD34D; }
.txn-balance-value { font-size: 15px; font-weight: 900; font-family: 'Inter', 'Courier New', monospace; word-break: break-all; }
.txn-float-value { color: #1D4ED8; }
.txn-cash-value { color: #059669; }
.txn-after-value { color: #7C3AED; }
.txn-form-actions { display: flex; gap: 12px; padding-top: 8px; flex-wrap: wrap; }
.txn-btn { padding: 12px 24px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; font-family: 'Inter', sans-serif; flex: 1; justify-content: center; min-width: 140px; }
.txn-btn-cancel { background: var(--bg-table-even); color: var(--text-secondary); border: 1.5px solid var(--border-color); }
.txn-btn-submit { background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%); color: white; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.35); }
.txn-btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5); }

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(3, 1fr); }
    .capital-compact-content { grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    .capital-compact-value { font-size: 22px; }
}
@media (max-width: 768px) {
    .summary-cards { grid-template-columns: 1fr; }
    .filters-form { flex-direction: column; width: 100%; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; flex-wrap: wrap; }
    .header-right .btn { flex: 1; justify-content: center; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .capital-card-compact { flex-direction: column; align-items: stretch; padding: 16px 18px; gap: 14px; }
    .capital-compact-content { grid-template-columns: 1fr; gap: 12px; }
    .capital-compact-value { font-size: 20px; }
    
    .section-header-red {
        flex-direction: column;
        align-items: stretch;
    }
    .section-header-red h3 {
        justify-content: center;
    }
    .search-input-group-red {
        width: 100%;
    }
    
    .txn-modal { max-width: 95vw; max-height: 95vh; }
    .txn-balance-preview { grid-template-columns: 1fr; }
    .txn-form-actions { flex-direction: column; }
    .txn-btn { width: 100%; }
}
@media (max-width: 480px) {
    .summary-card { padding: 14px 16px; gap: 12px; }
    .summary-icon-wrapper { width: 44px; height: 44px; font-size: 18px; }
    .summary-value { font-size: 18px; }
    .capital-compact-value { font-size: 17px; }
    .capital-compact-icon { width: 44px; height: 44px; font-size: 18px; }
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

function formatMoney(num) {
    return 'TSh ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

// ============================================================
// TRANSACTION SEARCH
// ============================================================
function onTxnSearch(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.txn-row');
    const clearBtn = document.getElementById('txnSearchClear');
    const countBadge = document.getElementById('txnSearchCount');
    const noResults = document.getElementById('noTxnResults');
    
    if (clearBtn) {
        clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    }
    
    if (searchTerm.length === 0) {
        rows.forEach(row => row.classList.remove('hidden-by-search'));
        
        let idx = 1;
        rows.forEach(row => {
            const numCell = row.querySelector('.row-num');
            if (numCell) numCell.textContent = idx++;
        });
        
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        return;
    }
    
    let matchCount = 0;
    rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    let visibleIdx = 1;
    rows.forEach(row => {
        if (!row.classList.contains('hidden-by-search')) {
            const numCell = row.querySelector('.row-num');
            if (numCell) numCell.textContent = visibleIdx++;
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

function clearTxnSearch() {
    const input = document.getElementById('txnSearchInput');
    if (input) {
        input.value = '';
        onTxnSearch(input);
        input.focus();
    }
}

// ============================================================
// TRANSACTION MODAL
// ============================================================
let currentProviderData = { float: 0, cash: 0 };
let currentTxnType = 'deposit';

function openTransactionModal(type, providerId = null) {
    currentTxnType = type;
    
    const overlay = document.getElementById('txnModalOverlay');
    const header = document.getElementById('txnModalHeader');
    const icon = document.getElementById('txnModalIcon');
    const title = document.getElementById('txnModalTitle');
    const subtitle = document.getElementById('txnModalSubtitle');
    const submitBtn = document.getElementById('txnSubmitBtn');
    const messageDiv = document.getElementById('txnModalMessage');
    const providerInfo = document.getElementById('txnProviderInfo');
    
    messageDiv.style.display = 'none';
    providerInfo.style.display = 'none';
    
    if (type === 'deposit') {
        header.className = 'txn-modal-header txn-header-deposit';
        icon.innerHTML = '<i class="fas fa-arrow-down"></i>';
        title.textContent = 'Add Deposit';
        subtitle.textContent = 'Add money to provider float';
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Deposit';
    } else {
        header.className = 'txn-modal-header txn-header-withdrawal';
        icon.innerHTML = '<i class="fas fa-arrow-up"></i>';
        title.textContent = 'Add Withdrawal';
        subtitle.textContent = 'Withdraw money from provider float';
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Withdrawal';
    }
    
    document.getElementById('txnForm').reset();
    document.getElementById('txnType').value = type;
    document.getElementById('txnBalancePreview').style.display = 'none';
    document.getElementById('txnAmount').value = '';
    
    if (providerId) {
        const providerSelect = document.getElementById('txnProviderSelect');
        providerSelect.value = providerId;
        onProviderChange();
    }
    
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    setTimeout(() => {
        if (providerId) document.getElementById('txnAmount').focus();
        else document.getElementById('txnProviderSelect').focus();
    }, 300);
}

function closeTransactionModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('txnModalOverlay').classList.remove('show');
    document.body.style.overflow = '';
}

async function onProviderChange() {
    const select = document.getElementById('txnProviderSelect');
    const providerId = select.value;
    const providerInfo = document.getElementById('txnProviderInfo');
    const balancePreview = document.getElementById('txnBalancePreview');
    
    if (!providerId) {
        providerInfo.style.display = 'none';
        balancePreview.style.display = 'none';
        currentProviderData = { float: 0, cash: 0 };
        return;
    }
    
    const option = select.options[select.selectedIndex];
    const providerName = option.getAttribute('data-name');
    const providerCode = option.getAttribute('data-code');
    const iconClass = option.getAttribute('data-icon');
    const colorCode = option.getAttribute('data-color');
    
    document.getElementById('txnProviderName').textContent = providerName;
    document.getElementById('txnProviderCode').textContent = providerCode;
    const iconEl = document.getElementById('txnProviderIcon');
    iconEl.innerHTML = `<i class="${iconClass}"></i>`;
    iconEl.style.background = colorCode;
    providerInfo.style.display = 'flex';
    
    try {
        const formData = new FormData();
        formData.append('ajax_action', 'get_provider_float');
        formData.append('provider_id', providerId);
        formData.append('branch_id', '<?php echo $employee_branch_id; ?>');
        
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            currentProviderData = {
                float: parseFloat(data.float) || 0,
                cash: parseFloat(data.cash) || 0
            };
            
            document.getElementById('txnCurrentFloat').textContent = data.formatted_float;
            document.getElementById('txnCurrentCash').textContent = data.formatted_cash;
            document.getElementById('txnAfterFloat').textContent = data.formatted_float;
            
            balancePreview.style.display = 'grid';
            updateBalancePreview();
        }
    } catch (err) { console.error('Error:', err); }
}

function updateBalancePreview() {
    const amount = parseMoney(document.getElementById('txnAmount').value);
    const currentFloat = currentProviderData.float;
    
    let afterFloat = currentTxnType === 'deposit' ? currentFloat + amount : currentFloat - amount;
    
    const afterEl = document.getElementById('txnAfterFloat');
    afterEl.textContent = formatMoney(afterFloat);
    afterEl.style.color = (currentTxnType === 'withdrawal' && afterFloat < 0) ? '#DC2626' : '';
}

async function submitTransaction(event) {
    event.preventDefault();
    
    const form = document.getElementById('txnForm');
    const formData = new FormData(form);
    const submitBtn = document.getElementById('txnSubmitBtn');
    
    const providerId = formData.get('provider_id');
    const amount = parseMoney(formData.get('amount'));
    
    if (!providerId) { showModalMessage('Tafadhali chagua provider.', 'error'); return; }
    if (amount <= 0) { showModalMessage('Tafadhali weka amount sahihi.', 'error'); return; }
    
    formData.set('amount', amount);
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Inatuma...';
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            showModalMessage(data.message, 'success');
            
            setTimeout(() => {
                closeTransactionModal();
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                window.location.reload();
            }, 1500);
        } else {
            showModalMessage(data.message || 'Kuna tatizo. Jaribu tena.', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
        }
    } catch (err) {
        showModalMessage('Kuna tatizo la mtandao. Jaribu tena.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
    }
}

function showModalMessage(message, type) {
    const messageDiv = document.getElementById('txnModalMessage');
    messageDiv.className = 'txn-modal-message ' + type;
    messageDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> <span>${message}</span>`;
    messageDiv.style.display = 'flex';
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const overlay = document.getElementById('txnModalOverlay');
        if (overlay && overlay.classList.contains('show')) {
            closeTransactionModal();
            return;
        }
        
        const searchInput = document.getElementById('txnSearchInput');
        if (searchInput && searchInput.value.length > 0 && document.activeElement === searchInput) {
            clearTxnSearch();
        }
    }
    
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const input = document.getElementById('txnSearchInput');
        if (input) {
            input.focus();
            input.select();
        }
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
});
</script>

</body>
</html>