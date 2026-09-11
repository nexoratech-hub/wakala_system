<?php
// ================================================================
// FILE: modules/daily_report/index_employee.php
// WAKALA FINANCIAL SYSTEM - EMPLOYEE DASHBOARD
// ✅ FLOAT: kutoka providers zake TU (user_providers)
// ✅ CASH: kutoka employees.cash_allocation (yake)
// ✅ NEW: Transactions table ya providers zake
// ✅ NEW: Action button = VIEW tu
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
$employee_cash_allocation = floatval($employee['cash_allocation'] ?? 0);

// ============================================================
// HANDLE AJAX REQUESTS (Deposit/Withdrawal)
// ============================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        // GET PROVIDER FLOAT
        if ($_POST['ajax_action'] === 'get_provider_float') {
            $provider_id = intval($_POST['provider_id'] ?? 0);
            $branch_id = intval($_POST['branch_id'] ?? 0);
            
            if ($provider_id <= 0 || $branch_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid provider or branch']);
                exit();
            }
            
            // Security check
            $stmt = $db->prepare("
                SELECT 1 FROM user_providers 
                WHERE user_id = ? AND provider_id = ? AND branch_id = ? AND is_active = 1
            ");
            $stmt->execute([$user_id, $provider_id, $branch_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Hauna ruhusa kwa provider huyu.']);
                exit();
            }
            
            // Float
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
            
            // Cash
            $stmt = $db->prepare("SELECT COALESCE(cash_allocation, 0) as cash FROM employees WHERE id = ?");
            $stmt->execute([$user_id]);
            $emp_cash = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_cash = floatval($emp_cash['cash'] ?? 0);
            
            echo json_encode([
                'success' => true,
                'float' => $current_float,
                'cash' => $current_cash,
                'formatted_float' => formatCurrency($current_float),
                'formatted_cash' => formatCurrency($current_cash)
            ]);
            exit();
        }
        
        // ADD TRANSACTION
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
            
            // Security
            $stmt = $db->prepare("
                SELECT 1 FROM user_providers 
                WHERE user_id = ? AND provider_id = ? AND branch_id = ? AND is_active = 1
            ");
            $stmt->execute([$user_id, $provider_id, $branch_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Hauna ruhusa kwa provider huyu.');
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
                throw new Exception('Hakuna daily report. Tafadhali tengeneza daily report kwanza.');
            }
            
            $daily_report_id = $latest_dr['id'];
            
            // Current cash
            $stmt = $db->prepare("SELECT COALESCE(cash_allocation, 0) as cash FROM employees WHERE id = ?");
            $stmt->execute([$user_id]);
            $emp_cash = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_cash = floatval($emp_cash['cash'] ?? 0);
            
            // Current float
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
            
            // MANTIKI
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
            
            // Insert transaction
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
            
            // Update float
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
            
            // Update cash
            $stmt = $db->prepare("
                UPDATE employees 
                SET cash_allocation = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$new_cash, $user_id]);
            
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
    // GET REPORTS ZANGU TU
    // ============================================================
    $sql = "SELECT 
                dr.id as report_id,
                dr.report_number,
                dr.report_date,
                dr.created_at,
                dr.net_profit,
                dr.current_capital,
                e.full_name as employee_name,
                b.branch_name as branch_name,
                b.branch_code as branch_code,
                drp.id as provider_row_id,
                drp.provider_id,
                drp.provider_code,
                drp.provider_name,
                drp.morning_float,
                drp.morning_cash,
                drp.current_float,
                drp.current_cash,
                drp.total_deposits as provider_deposits,
                drp.total_withdrawals as provider_withdrawals
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN daily_report_providers drp ON dr.id = drp.daily_report_id
            WHERE dr.employee_id = ?
            AND dr.report_date BETWEEN ? AND ?";
    $params = [$user_id, $from_date, $to_date];

    $sql .= " ORDER BY dr.report_date DESC, dr.id DESC, drp.provider_name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $report_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reports = [];
    foreach ($report_rows as $row) {
        $rid = $row['report_id'];
        if (!isset($reports[$rid])) {
            $reports[$rid] = [
                'id' => $row['report_id'],
                'report_number' => $row['report_number'],
                'report_date' => $row['report_date'],
                'branch_name' => $row['branch_name'],
                'branch_code' => $row['branch_code'],
                'employee_name' => $row['employee_name'],
                'created_at' => $row['created_at'],
                'net_profit' => $row['net_profit'],
                'current_capital' => $row['current_capital'],
                'providers' => []
            ];
        }
        if (!empty($row['provider_id'])) {
            $reports[$rid]['providers'][] = [
                'id' => $row['provider_row_id'],
                'provider_id' => $row['provider_id'],
                'provider_code' => $row['provider_code'],
                'provider_name' => $row['provider_name'],
                'morning_float' => $row['morning_float'],
                'morning_cash' => $row['morning_cash'],
                'current_float' => $row['current_float'],
                'current_cash' => $row['current_cash'],
                'total_deposits' => $row['provider_deposits'],
                'total_withdrawals' => $row['provider_withdrawals']
            ];
        }
    }
    $reports = array_values($reports);

    // ============================================================
    // ✅ GET ALL MY TRANSACTIONS (Kutoka providers zangu)
    // ============================================================
    $sql_transactions = "
        SELECT 
            t.*,
            p.provider_name,
            p.icon_class,
            p.color_code
        FROM transactions t
        INNER JOIN user_providers up 
            ON t.provider_id = up.provider_id 
            AND up.user_id = ? 
            AND up.branch_id = ?
            AND up.is_active = 1
        LEFT JOIN providers p ON t.provider_id = p.id
        WHERE t.branch_id = ?
        AND DATE(t.transaction_date) BETWEEN ? AND ?
        ORDER BY t.created_at DESC
        LIMIT 100
    ";
    $stmt = $db->prepare($sql_transactions);
    $stmt->execute([$user_id, $employee_branch_id, $employee_branch_id, $from_date, $to_date]);
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

    // ============================================================
    // SUMMARY - Reports zangu
    // ============================================================
    $sql_summary = "SELECT 
            COUNT(*) as total_reports,
            COALESCE(SUM(total_deposits), 0) as total_deposits,
            COALESCE(SUM(total_withdrawals), 0) as total_withdrawals,
            COALESCE(SUM(net_profit), 0) as total_profit
            FROM daily_reports
            WHERE employee_id = ?
            AND report_date BETWEEN ? AND ?";
    
    $stmt = $db->prepare($sql_summary);
    $stmt->execute([$user_id, $from_date, $to_date]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // ============================================================
    // ✅ TOTAL FLOAT — kutoka providers zake TU
    // ============================================================
    $sql_float = "SELECT 
                    COALESCE(SUM(drp.current_float), 0) as total_float
                  FROM daily_report_providers drp
                  INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
                  INNER JOIN user_providers up 
                      ON drp.provider_id = up.provider_id 
                      AND up.user_id = ? 
                      AND up.branch_id = ?
                      AND up.is_active = 1
                  WHERE dr.branch_id = ?
                  AND dr.report_date BETWEEN ? AND ?";
    $stmt = $db->prepare($sql_float);
    $stmt->execute([$user_id, $employee_branch_id, $employee_branch_id, $from_date, $to_date]);
    $float_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_float = floatval($float_result['total_float'] ?? 0);

    // ============================================================
    // ✅ TOTAL CASH — kutoka employees.cash_allocation (realtime)
    // ============================================================
    $stmt = $db->prepare("SELECT COALESCE(cash_allocation, 0) as cash FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp_cash_current = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_cash = floatval($emp_cash_current['cash'] ?? 0);

    // ============================================================
    // ✅ TOTAL CAPITAL = Float + Cash
    // ============================================================
    $total_capital = $total_float + $total_cash;

    // ============================================================
    // PROVIDERS ZANGU TU — kwa Modal
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
            INNER JOIN user_providers up 
                ON p.id = up.provider_id 
                AND up.user_id = ? 
                AND up.branch_id = ?
                AND up.is_active = 1
            WHERE p.is_active = 1
            ORDER BY p.display_order, p.provider_name
        ");
        $stmt->execute([$employee_branch_id, $user_id, $employee_branch_id]);
        $providers_for_modal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $reports = [];
    $providers_for_modal = [];
    $my_transactions = [];
    $summary = ['total_reports'=>0,'total_deposits'=>0,'total_withdrawals'=>0,'total_profit'=>0];
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
                        <i class="fas fa-coins"></i> My Float
                    </span>
                    <span class="capital-compact-value capital-float-value" id="totalFloatDisplay">
                        <?php echo formatCurrency($total_float); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-money-bill-wave"></i> My Cash
                    </span>
                    <span class="capital-compact-value capital-cash-value" id="totalCashDisplay">
                        <?php echo formatCurrency($total_cash); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-building"></i> My Total Capital
                    </span>
                    <span class="capital-compact-value capital-capital-value" id="totalCapitalDisplay">
                        <?php echo formatCurrency($total_capital); ?>
                    </span>
                </div>
            </div>
            <div class="capital-compact-badge">
                <i class="fas fa-user"></i> My Allocation
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> My Daily Reports</h2>
                <p class="text-muted">Reports zangu za kila siku</p>
            </div>
            <div class="header-right">
                <a href="add_employee.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-generate">
                    <i class="fas fa-plus-circle"></i> Generate Report
                </a>
                
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
            <div class="summary-card summary-card-reports">
                <div class="summary-icon-wrapper summary-icon-blue">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">My Reports</span>
                    <span class="summary-value"><?php echo number_format($summary['total_reports'] ?? 0); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-card-deposits">
                <div class="summary-icon-wrapper summary-icon-green">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">My Deposits</span>
                    <span class="summary-value" id="totalDepositsDisplay"><?php echo formatCurrency($total_deposits_actual); ?></span>
                </div>
            </div>
            
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
        ✅ SECTION 1: MY TRANSACTIONS (NEW)
        ============================================================ -->
        <div class="section-container">
            <div class="section-header">
                <h3>
                    <i class="fas fa-exchange-alt" style="color:#2563EB;"></i>
                    My Transactions
                    <span class="section-count"><?php echo count($my_transactions); ?></span>
                </h3>
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
                    <tbody>
                        <?php $i = 1; foreach ($my_transactions as $txn): 
                            $is_deposit = $txn['transaction_type'] === 'deposit';
                            $color = $txn['color_code'] ?? '#0B5ED7';
                            $icon = $txn['icon_class'] ?? 'fas fa-university';
                            $txn_datetime = $txn['created_at'] ?? $txn['transaction_date'];
                        ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
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
            </div>
            <?php else: ?>
                <div class="empty-txn">
                    <i class="fas fa-inbox"></i>
                    <p>Hakuna transactions bado. Anza kwa ku-add deposit au withdrawal.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================
        ✅ SECTION 2: REPORTS (Providers zangu + View button)
        ============================================================ -->
        <div class="section-container">
            <div class="section-header">
                <h3>
                    <i class="fas fa-file-alt" style="color:#bb0404;"></i>
                    My Reports
                    <span class="section-count"><?php echo count($reports); ?></span>
                </h3>
                
                <div class="search-input-group-small">
                    <i class="fas fa-search"></i>
                    <input type="text" 
                           id="searchInput" 
                           placeholder="Search report..."
                           oninput="onGlobalSearch(this)">
                </div>
            </div>

            <?php if (count($reports) > 0): ?>
                <div id="reportsContainer">
                    <?php foreach ($reports as $report): 
                        $provider_names = array_map(function($p) { return $p['provider_name']; }, $report['providers']);
                        $search_data = strtolower(
                            ($report['report_number'] ?? '') . ' ' .
                            implode(' ', $provider_names) . ' ' .
                            date('d M Y', strtotime($report['report_date'] ?? 'now'))
                        );
                    ?>
                        <div class="report-group" 
                             data-report-id="<?php echo $report['id']; ?>"
                             data-search="<?php echo htmlspecialchars($search_data); ?>">
                            
                            <div class="report-group-header">
                                <div class="report-header-left">
                                    <div class="report-icon">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div class="report-header-info">
                                        <div class="report-header-title">
                                            <span class="report-label">Report:</span>
                                            <span class="report-number"><?php echo htmlspecialchars($report['report_number']); ?></span>
                                        </div>
                                        <div class="report-header-meta">
                                            <span class="meta-item">
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                                            </span>
                                            <span class="meta-item">
                                                <i class="fas fa-store-alt"></i>
                                                <?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="report-header-right">
                                    <div class="report-stat">
                                        <span class="stat-label">Providers</span>
                                        <span class="stat-value"><?php echo count($report['providers']); ?></span>
                                    </div>
                                    <div class="report-header-actions">
                                        <!-- ✅ VIEW BUTTON TU -->
                                        <a href="view_employee.php?id=<?php echo $report['id']; ?>" class="btn-view-report">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($report['providers'])): ?>
                            <div class="providers-table-wrapper">
                                <table class="providers-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Provider</th>
                                            <th>Code</th>
                                            <th class="text-right">Morning Float</th>
                                            <th class="text-right">Current Float</th>
                                            <th class="text-right">Deposits</th>
                                            <th class="text-right">Withdrawals</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $i = 1; 
                                        $sum_float = 0;
                                        $sum_current_float = 0;
                                        $sum_deposits = 0;
                                        $sum_withdrawals = 0;
                                        foreach ($report['providers'] as $p): 
                                            $sum_float += floatval($p['morning_float']);
                                            $sum_current_float += floatval($p['current_float']);
                                            $sum_deposits += floatval($p['total_deposits']);
                                            $sum_withdrawals += floatval($p['total_withdrawals']);
                                        ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo htmlspecialchars($p['provider_name']); ?></td>
                                                <td><span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span></td>
                                                <td class="text-right">
                                                    <span class="amount-float"><?php echo formatCurrency($p['morning_float']); ?></span>
                                                </td>
                                                <td class="text-right">
                                                    <span class="amount-float-bold"><?php echo formatCurrency($p['current_float']); ?></span>
                                                </td>
                                                <td class="text-right">
                                                    <span class="amount-deposit">+ <?php echo formatCurrency($p['total_deposits']); ?></span>
                                                </td>
                                                <td class="text-right">
                                                    <span class="amount-withdrawal">- <?php echo formatCurrency($p['total_withdrawals']); ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="totals-row">
                                            <td colspan="3"><strong>TOTAL</strong></td>
                                            <td class="text-right"><strong><?php echo formatCurrency($sum_float); ?></strong></td>
                                            <td class="text-right"><strong><?php echo formatCurrency($sum_current_float); ?></strong></td>
                                            <td class="text-right"><strong class="amount-deposit">+ <?php echo formatCurrency($sum_deposits); ?></strong></td>
                                            <td class="text-right"><strong class="amount-withdrawal">- <?php echo formatCurrency($sum_withdrawals); ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="no-search-results" id="noSearchResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No results found</h3>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Hakuna reports bado</h3>
                    <a href="add_employee.php" class="btn btn-generate">
                        <i class="fas fa-plus-circle"></i> Generate Report
                    </a>
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
.btn-generate { background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%); color: white; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3); }
.btn-generate:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4); color: white; }
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
.summary-card-reports { border-left: 4px solid #1D4ED8; }
.summary-card-deposits { border-left: 4px solid #059669; }
.summary-card-withdrawals { border-left: 4px solid #DC2626; }
.summary-icon-wrapper { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.summary-icon-blue { background: linear-gradient(135deg, #DBEAFE, #BFDBFE); color: #1D4ED8; }
.summary-icon-green { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669; }
.summary-icon-red { background: linear-gradient(135deg, #FEE2E2, #FECACA); color: #DC2626; }
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
   SECTION CONTAINER (NEW)
   ============================================================ */
.section-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    padding: 20px 22px;
    margin-bottom: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.section-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-count {
    font-size: 12px;
    font-weight: 800;
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 4px 12px;
    border-radius: 12px;
    margin-left: 6px;
}
html.dark-mode .section-count { background: #1E3A5F; color: #60A5FA; }

/* ============================================================
   TRANSACTIONS TABLE (NEW)
   ============================================================ */
.txn-table-wrapper {
    overflow-x: auto;
    width: 100%;
    -webkit-overflow-scrolling: touch;
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
.txn-table tbody tr { border-bottom: 1px solid var(--border-color); }
.txn-table tbody tr:hover { background: var(--bg-table-hover); }
.txn-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.txn-table tbody td { padding: 11px 14px; color: var(--text-primary); vertical-align: middle; }
.txn-table tbody td.text-right { text-align: right; }

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

/* ============================================================
   SEARCH SMALL
   ============================================================ */
.search-input-group-small {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    padding: 6px 12px;
    width: 280px;
    max-width: 100%;
}
.search-input-group-small i { color: #bb0404; font-size: 12px; }
.search-input-group-small input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 12px;
    color: var(--text-primary);
    outline: none;
    min-width: 0;
}

/* REPORT GROUP */
.report-group {
    background: var(--bg-card); border-radius: 12px;
    border: 1px solid var(--border-color); margin-bottom: 16px;
    overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color); width: 100%;
}
.report-group.hidden-by-search { display: none !important; }
.report-group-header {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    padding: 14px 20px; display: flex; justify-content: space-between;
    align-items: center; gap: 16px; flex-wrap: wrap; color: #FFFFFF;
}
.report-header-left { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
.report-icon {
    width: 42px; height: 42px; background: rgba(255,255,255,0.15);
    border-radius: 10px; display: flex; align-items: center;
    justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0;
}
.report-header-title { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.report-label { font-size: 11px; font-weight: 600; opacity: 0.7; text-transform: uppercase; }
.report-number { font-size: 16px; font-weight: 800; color: #FFFFFF; font-family: 'Courier New', monospace; word-break: break-word; }
.report-header-meta { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.meta-item { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: rgba(255,255,255,0.85); }
.report-header-right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.report-stat { display: flex; flex-direction: column; align-items: center; padding: 6px 14px; background: rgba(255,255,255,0.12); border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); }
.report-stat .stat-label { font-size: 9px; font-weight: 600; opacity: 0.7; text-transform: uppercase; }
.report-stat .stat-value { font-size: 14px; font-weight: 800; color: #FFFFFF; }
.btn-view-report { padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,0.2); color: #FFFFFF; border: 1px solid rgba(255,255,255,0.2); transition: all 0.2s ease; }
.btn-view-report:hover { background: #FFFFFF; color: #bb0404; }

/* PROVIDERS TABLE */
.providers-table-wrapper { overflow-x: auto; width: 100%; -webkit-overflow-scrolling: touch; }
.providers-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 800px; }
.providers-table thead tr { background: var(--bg-table-even); }
.providers-table thead th { padding: 11px 14px; text-align: left; font-weight: 700; color: var(--text-muted); text-transform: uppercase; font-size: 10px; border-bottom: 2px solid var(--border-color); white-space: nowrap; }
.providers-table thead th.text-right { text-align: right; }
.providers-table tbody tr { border-bottom: 1px solid var(--border-color); }
.providers-table tbody tr:hover { background: var(--bg-table-hover); }
.providers-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.providers-table tbody td { padding: 11px 14px; color: var(--text-primary); }
.providers-table tbody td.text-right { text-align: right; }
.code-badge { display: inline-block; padding: 3px 10px; background: #DBEAFE; color: #1D4ED8; border-radius: 8px; font-size: 10px; font-weight: 700; font-family: 'Courier New', monospace; }
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; }
.amount-float { padding: 3px 10px; background: #DBEAFE; color: #1D4ED8; border-radius: 6px; font-weight: 700; font-size: 12px; font-family: 'Courier New', monospace; }
.amount-float-bold { padding: 3px 10px; background: #BFDBFE; color: #1E40AF; border-radius: 6px; font-weight: 800; font-size: 12px; font-family: 'Courier New', monospace; }
.amount-deposit { padding: 3px 10px; background: #DCFCE7; color: #15803D; border-radius: 6px; font-weight: 700; font-size: 12px; font-family: 'Courier New', monospace; }
.amount-withdrawal { padding: 3px 10px; background: #FEE2E2; color: #991B1B; border-radius: 6px; font-weight: 700; font-size: 12px; font-family: 'Courier New', monospace; }
.totals-row { background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%) !important; border-top: 2px solid #bb0404; }
.totals-row td { padding: 12px 14px; font-weight: 700; }

/* EMPTY STATE */
.empty-state, .no-search-results {
    text-align: center; padding: 60px 20px;
    background: var(--bg-card); border-radius: 12px;
    border: 1px solid var(--border-color); width: 100%;
}
.empty-state i, .no-search-results i { font-size: 56px; color: var(--text-light); opacity: 0.4; display: block; margin-bottom: 16px; }
.empty-state h3, .no-search-results h3 { font-size: 18px; color: var(--text-primary); margin: 0 0 8px 0; }
.empty-state p, .no-search-results p { color: var(--text-muted); font-size: 14px; margin: 0 0 20px 0; }

/* TRANSACTION MODAL */
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
    .search-input-group-small { width: 100%; }
    .report-group-header { flex-direction: column; align-items: flex-start; }
    .report-header-right { width: 100%; justify-content: space-between; }
    .section-header { flex-direction: column; align-items: flex-start; }
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
// SEARCH
// ============================================================
function onGlobalSearch(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const groups = document.querySelectorAll('.report-group');
    
    if (searchTerm.length === 0) {
        groups.forEach(g => g.classList.remove('hidden-by-search'));
        return;
    }
    
    groups.forEach(group => {
        const searchData = group.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            group.classList.remove('hidden-by-search');
        } else {
            group.classList.add('hidden-by-search');
        }
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const overlay = document.getElementById('txnModalOverlay');
        if (overlay && overlay.classList.contains('show')) {
            closeTransactionModal();
        }
    }
});

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