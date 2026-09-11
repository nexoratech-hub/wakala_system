<?php
// ================================================================
// FILE: modules/daily_report/index.php
// WAKALA FINANCIAL SYSTEM - DAILY REPORTS
// ✅ FIXED: Only 3 buttons per provider: View, Edit, Delete
// ✅ View → view_provider_transactions.php (page)
// ✅ Edit → edit_provider.php (page)
// ✅ Delete → delete_provider.php
// ✅ Add Deposit/Withdrawal buttons in Page Header (modal)
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// HANDLE AJAX REQUESTS
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
            
            $stmt = $db->prepare("
                SELECT * FROM daily_reports 
                WHERE branch_id = ? 
                ORDER BY report_date DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute([$branch_id]);
            $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $current_float = 0;
            $current_cash = 0;
            
            if ($latest_dr) {
                $stmt = $db->prepare("
                    SELECT current_float FROM daily_report_providers 
                    WHERE daily_report_id = ? AND provider_id = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$latest_dr['id'], $provider_id]);
                $drp = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($drp) {
                    $current_float = floatval($drp['current_float'] ?? 0);
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
                    $mrp = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($mrp) {
                        $current_float = floatval($mrp['float_balance'] ?? 0);
                    }
                }
                
                $current_cash = floatval($latest_dr['current_cash'] ?? 0);
            }
            
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
            
            if ($branch_id <= 0) throw new Exception('Please select a branch.');
            if ($provider_id <= 0) throw new Exception('Please select a provider.');
            if ($amount <= 0) throw new Exception('Amount must be greater than 0.');
            if (!in_array($transaction_type, ['deposit', 'withdrawal'])) {
                throw new Exception('Invalid transaction type.');
            }
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT * FROM providers WHERE id = ? AND is_active = 1");
            $stmt->execute([$provider_id]);
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$provider) throw new Exception('Provider not found.');
            
            $stmt = $db->prepare("SELECT * FROM branch_providers WHERE branch_id = ? AND provider_id = ? AND is_active = 1");
            $stmt->execute([$branch_id, $provider_id]);
            $branch_provider = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$branch_provider) throw new Exception('Provider is not assigned to this branch.');
            
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
            
            $stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmt->execute([$user_id]);
            $emp = $stmt->fetch(PDO::FETCH_ASSOC);
            $employee_name = $emp['full_name'] ?? 'N/A';
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => ucfirst($transaction_type) . ' ya TSh ' . number_format($amount) . ' imefanikiwa!',
                'transaction' => [
                    'id' => $transaction_id,
                    'transaction_number' => $transaction_number,
                    'provider_id' => $provider_id,
                    'provider_code' => $branch_provider['provider_code'],
                    'provider_name' => $provider['provider_name'],
                    'amount' => $amount,
                    'type' => $transaction_type,
                    'new_float' => $new_float,
                    'new_cash' => $new_cash,
                    'new_capital' => $new_capital,
                    'employee_name' => $employee_name,
                    'time' => date('H:i:s'),
                    'formatted_amount' => formatCurrency($amount),
                    'formatted_float' => formatCurrency($new_float),
                    'formatted_cash' => formatCurrency($new_cash),
                    'formatted_capital' => formatCurrency($new_capital)
                ]
            ]);
            exit();
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// ============================================================
// BRANCH FILTER
// ============================================================
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

$branch_name = 'All Branches';
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
            WHERE dr.report_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND dr.branch_id = ?";
        $params[] = $selected_branch;
    }

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
                'total_withdrawals' => $row['provider_withdrawals'],
                'employee_name' => $row['employee_name']
            ];
        }
    }
    $reports = array_values($reports);

    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql_summary = "SELECT 
            COUNT(*) as total_reports,
            SUM(total_deposits) as total_deposits,
            SUM(total_withdrawals) as total_withdrawals,
            SUM(net_profit) as total_profit,
            SUM(current_capital) as total_capital
            FROM daily_reports
            WHERE report_date BETWEEN ? AND ?";
    $params_summary = [$from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql_summary .= " AND branch_id = ?";
        $params_summary[] = $selected_branch;
    }
    
    $stmt = $db->prepare($sql_summary);
    $stmt->execute($params_summary);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $sql_float = "SELECT 
                    COALESCE(SUM(drp.current_float), 0) as total_float
                  FROM daily_report_providers drp
                  INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
                  WHERE dr.report_date BETWEEN ? AND ?";
    $params_float = [$from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql_float .= " AND dr.branch_id = ?";
        $params_float[] = $selected_branch;
    }
    
    $stmt = $db->prepare($sql_float);
    $stmt->execute($params_float);
    $float_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_float = floatval($float_result['total_float'] ?? 0);

    $sql_cash = "SELECT 
                    COALESCE(SUM(current_cash), 0) as total_cash
                  FROM daily_reports
                  WHERE report_date BETWEEN ? AND ?";
    $params_cash = [$from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql_cash .= " AND branch_id = ?";
        $params_cash[] = $selected_branch;
    }
    
    $stmt = $db->prepare($sql_cash);
    $stmt->execute($params_cash);
    $cash_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_cash = floatval($cash_result['total_cash'] ?? 0);
    $total_capital = $total_float + $total_cash;

    $providers_for_modal = [];
    if ($selected_branch > 0) {
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.provider_name,
                p.provider_code as main_code,
                p.icon_class,
                p.color_code,
                bp.provider_code as branch_provider_code
            FROM providers p
            INNER JOIN branch_providers bp ON p.id = bp.provider_id
            WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
            ORDER BY p.display_order, p.provider_name
        ");
        $stmt->execute([$selected_branch]);
        $providers_for_modal = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $reports = [];
    $branches = [];
    $providers_for_modal = [];
    $summary = ['total_reports'=>0,'total_deposits'=>0,'total_withdrawals'=>0,'total_profit'=>0,'total_capital'=>0];
    $total_float = 0;
    $total_cash = 0;
    $total_capital = 0;
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
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
                    <span class="branch-indicator-label">
                        <?php echo $selected_branch > 0 ? 'Current Branch' : 'Showing'; ?>
                    </span>
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
                        <i class="fas fa-coins"></i> Total Float
                    </span>
                    <span class="capital-compact-value capital-float-value" id="totalFloatDisplay">
                        <?php echo formatCurrency($total_float); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-money-bill-wave"></i> Cash Balance
                    </span>
                    <span class="capital-compact-value capital-cash-value" id="totalCashDisplay">
                        <?php echo formatCurrency($total_cash); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-building"></i> Total Capital
                    </span>
                    <span class="capital-compact-value capital-capital-value" id="totalCapitalDisplay">
                        <?php echo formatCurrency($total_capital); ?>
                    </span>
                </div>
            </div>
            <div class="capital-compact-badge">
                <i class="fas fa-clock"></i> From Reports
            </div>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Daily Reports</h2>
                <p class="text-muted">Manage and track daily business reports</p>
            </div>
            <div class="header-right">
                <a href="generate.php?date=<?php echo date('Y-m-d'); ?>&branch_id=<?php echo $selected_branch; ?>" class="btn btn-generate">
                    <i class="fas fa-magic"></i> Generate
                </a>
                
                <button type="button" class="btn btn-deposit" onclick="openTransactionModal('deposit')">
                    <i class="fas fa-arrow-down"></i> Add Deposit
                </button>
                
                <button type="button" class="btn btn-withdrawal" onclick="openTransactionModal('withdrawal')">
                    <i class="fas fa-arrow-up"></i> Add Withdrawal
                </button>
                
                <div class="dropdown export-dropdown">
                    <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a href="#" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> CSV</a>
                        <a href="#" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="#" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</a>
                        <a href="#" onclick="window.print()"><i class="fas fa-print"></i> Print</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card summary-card-reports">
                <div class="summary-icon-wrapper summary-icon-blue">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Reports</span>
                    <span class="summary-value"><?php echo number_format($summary['total_reports'] ?? 0); ?></span>
                </div>
                <div class="summary-card-decoration"></div>
            </div>
            
            <div class="summary-card summary-card-deposits">
                <div class="summary-icon-wrapper summary-icon-green">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Deposits</span>
                    <span class="summary-value" id="totalDepositsDisplay"><?php echo formatCurrency($summary['total_deposits'] ?? 0); ?></span>
                </div>
                <div class="summary-card-decoration"></div>
            </div>
            
            <div class="summary-card summary-card-withdrawals">
                <div class="summary-icon-wrapper summary-icon-red">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Withdrawals</span>
                    <span class="summary-value" id="totalWithdrawalsDisplay"><?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?></span>
                </div>
                <div class="summary-card-decoration"></div>
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
                    <label>Branch</label>
                    <select name="branch_id" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- Global Search -->
        <div class="search-bar-wrapper">
            <div class="search-input-group">
                <i class="fas fa-search"></i>
                <input type="text" 
                       id="searchInput" 
                       placeholder="Search report no, provider, employee..."
                       oninput="onGlobalSearch(this)">
                <button type="button" id="searchClear" onclick="clearSearch()" style="display:none;">
                    <i class="fas fa-times"></i>
                </button>
                <span class="search-count" id="searchCount" style="display:none;">0</span>
            </div>
            <span class="record-count" id="recordCount"><?php echo count($reports); ?> reports</span>
        </div>

        <!-- Reports List -->
        <?php if (count($reports) > 0): ?>
            <div id="reportsContainer">
                <?php foreach ($reports as $report): 
                    $provider_names = array_map(function($p) { return $p['provider_name']; }, $report['providers']);
                    $search_data = strtolower(
                        ($report['report_number'] ?? '') . ' ' .
                        ($report['employee_name'] ?? '') . ' ' .
                        ($report['branch_name'] ?? '') . ' ' .
                        implode(' ', $provider_names) . ' ' .
                        date('d M Y', strtotime($report['report_date'] ?? 'now'))
                    );
                ?>
                    <div class="report-group" 
                         data-report-id="<?php echo $report['id']; ?>"
                         data-report-date="<?php echo $report['report_date']; ?>"
                         data-search="<?php echo htmlspecialchars($search_data); ?>">
                        
                        <!-- REPORT HEADER -->
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
                                        <span class="meta-item">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="report-header-right">
                                <div class="report-stat">
                                    <span class="stat-label">Providers</span>
                                    <span class="stat-value"><?php echo count($report['providers']); ?></span>
                                </div>
                                <div class="report-stat">
                                    <span class="stat-label">Profit</span>
                                    <span class="stat-value <?php echo $report['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatCurrency($report['net_profit']); ?>
                                    </span>
                                </div>
                                <div class="report-header-actions">
                                    <a href="view.php?id=<?php echo $report['id']; ?>" class="btn-view-report">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="edit.php?id=<?php echo $report['id']; ?>" class="btn-edit-report">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button onclick="deleteReport(<?php echo $report['id']; ?>)" class="btn-delete-report">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($report['providers'])): ?>
                        <div class="providers-table-wrapper" id="table-wrapper-<?php echo $report['id']; ?>">
                            <table class="providers-table" data-report-id="<?php echo $report['id']; ?>">
                                <thead>
                                    <tr class="table-search-row">
                                        <th colspan="10" class="table-search-cell">
                                            <div class="table-search-row-content">
                                                <div class="table-search-wrapper">
                                                    <i class="fas fa-search table-search-icon"></i>
                                                    <input type="text" 
                                                           class="table-search-input" 
                                                           data-report-id="<?php echo $report['id']; ?>"
                                                           placeholder="Search provider..."
                                                           oninput="onTableSearch(this, <?php echo $report['id']; ?>)">
                                                    <button type="button" class="table-search-clear" 
                                                            data-report-id="<?php echo $report['id']; ?>"
                                                            onclick="clearTableSearch(<?php echo $report['id']; ?>)" 
                                                            style="display:none;">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <span class="table-search-count" 
                                                          data-report-id="<?php echo $report['id']; ?>" 
                                                          style="display:none;">0</span>
                                                </div>
                                                
                                                <div class="table-scroll-controls">
                                                    <button type="button" class="scroll-btn scroll-left" 
                                                            onclick="scrollTable('left', <?php echo $report['id']; ?>)" 
                                                            title="Scroll Left">
                                                        <i class="fas fa-chevron-left"></i>
                                                    </button>
                                                    <span class="scroll-label">
                                                        <i class="fas fa-arrows-alt-h"></i> SCROLL
                                                    </span>
                                                    <button type="button" class="scroll-btn scroll-right" 
                                                            onclick="scrollTable('right', <?php echo $report['id']; ?>)" 
                                                            title="Scroll Right">
                                                        <i class="fas fa-chevron-right"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>
                                    </tr>
                                    <!-- COLUMN HEADERS -->
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th>Date</th>
                                        <th>Provider</th>
                                        <th>Code</th>
                                        <th>Employee</th>
                                        <th class="text-right">Morning Float</th>
                                        <th class="text-right">Current Float</th>
                                        <th class="text-right">Deposits</th>
                                        <th class="text-right">Withdrawals</th>
                                        <th style="width: 130px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody data-report-tbody="<?php echo $report['id']; ?>">
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
                                        
                                        $provider_search = strtolower($p['provider_name'] . ' ' . $p['provider_code'] . ' ' . ($p['employee_name'] ?? ''));
                                    ?>
                                        <tr class="provider-row" 
                                            data-provider-id="<?php echo $p['provider_id']; ?>"
                                            data-provider-row-id="<?php echo $p['id']; ?>"
                                            data-report-id="<?php echo $report['id']; ?>"
                                            data-report-date="<?php echo $report['report_date']; ?>"
                                            data-search="<?php echo htmlspecialchars($provider_search); ?>">
                                            <td class="row-number"><?php echo $i++; ?></td>
                                            <td>
                                                <span class="date-cell">
                                                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="provider-cell">
                                                    <div class="provider-icon-circle">
                                                        <i class="fas fa-university"></i>
                                                    </div>
                                                    <span class="provider-name-text">
                                                        <?php echo htmlspecialchars($p['provider_name']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                            </td>
                                            <td>
                                                <span class="employee-name-cell">
                                                    <i class="fas fa-user"></i>
                                                    <span class="employee-name-text">
                                                        <?php echo htmlspecialchars($p['employee_name'] ?? $report['employee_name'] ?? 'N/A'); ?>
                                                    </span>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-float morning-float-cell">
                                                    <?php echo formatCurrency($p['morning_float']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-float-bold current-float-cell" data-provider-id="<?php echo $p['provider_id']; ?>">
                                                    <?php echo formatCurrency($p['current_float']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-deposit deposits-cell" data-provider-id="<?php echo $p['provider_id']; ?>">
                                                    + <?php echo formatCurrency($p['total_deposits']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-withdrawal withdrawals-cell" data-provider-id="<?php echo $p['provider_id']; ?>">
                                                    - <?php echo formatCurrency($p['total_withdrawals']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="provider-actions">
                                                    <!-- ✅ VIEW - Goes to page -->
                                                    <a href="view_provider_transactions.php?provider_id=<?php echo $p['provider_id']; ?>&branch_id=<?php echo $selected_branch; ?>&report_id=<?php echo $report['id']; ?>&report_date=<?php echo $report['report_date']; ?>" 
                                                       class="btn-provider btn-provider-view" 
                                                       title="View Transactions">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <!-- ✅ EDIT - Goes to page -->
                                                    <a href="edit_provider.php?id=<?php echo $p['id']; ?>&branch_id=<?php echo $selected_branch; ?>" 
                                                       class="btn-provider btn-provider-edit" 
                                                       title="Edit Provider">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
                                                    <!-- DELETE - Stays as link -->
                                                    <a href="delete_provider.php?id=<?php echo $p['id']; ?>&branch_id=<?php echo $selected_branch; ?>" 
                                                       class="btn-provider btn-provider-delete" 
                                                       onclick="return confirm('Are you sure you want to delete provider \'<?php echo addslashes($p['provider_name']); ?>\'?')"
                                                       title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="totals-row">
                                        <td colspan="5"><strong>TOTAL (<?php echo count($report['providers']); ?> providers)</strong></td>
                                        <td class="text-right"><strong class="amount-float"><?php echo formatCurrency($sum_float); ?></strong></td>
                                        <td class="text-right"><strong class="amount-float-bold"><?php echo formatCurrency($sum_current_float); ?></strong></td>
                                        <td class="text-right"><strong class="amount-deposit">+ <?php echo formatCurrency($sum_deposits); ?></strong></td>
                                        <td class="text-right"><strong class="amount-withdrawal">- <?php echo formatCurrency($sum_withdrawals); ?></strong></td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                            
                            <div class="no-provider-results" 
                                 data-report-id="<?php echo $report['id']; ?>" 
                                 style="display:none;">
                                <i class="fas fa-search-minus"></i>
                                <p>No providers match your search</p>
                                <button type="button" class="btn btn-sm btn-secondary" 
                                        onclick="clearTableSearch(<?php echo $report['id']; ?>)">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                            <div class="no-providers-message">
                                <i class="fas fa-info-circle"></i>
                                <span>No providers attached to this report.</span>
                            </div>
                        <?php endif; ?>
                        
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="no-search-results" id="noSearchResults" style="display:none;">
                <i class="fas fa-search-minus"></i>
                <h3>No results found</h3>
                <p>No reports match your search.</p>
                <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                    <i class="fas fa-times"></i> Clear Search
                </button>
            </div>
            
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No daily reports found</h3>
                <p>Generate your first report to see providers breakdown.</p>
                <a href="generate.php?branch_id=<?php echo $selected_branch; ?>" class="btn btn-generate">
                    <i class="fas fa-magic"></i> Generate Report
                </a>
            </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
   TRANSACTION MODAL (Deposit/Withdrawal) - ONLY FROM HEADER BUTTONS
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
                <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                
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
                            <i class="fas fa-arrow-right"></i> After Transaction
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
                    <small class="txn-form-hint">
                        <i class="fas fa-info-circle"></i> 
                        Weka amount na comma separator (mfano: 1,000,000)
                    </small>
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
                           placeholder="e.g., REF-12345 (optional)">
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
                        <i class="fas fa-save"></i> Save Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* ============================================================
   SAME STYLES AS BEFORE (with small tweaks)
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
.main-wrapper { background: var(--bg-body) !important; }
.main-content { background: var(--bg-body) !important; }

/* Branch Card */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 10px; padding: 12px 20px; margin-bottom: 12px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 3px 12px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 10px; max-width: 100%;
}
.branch-indicator-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper {
    width: 38px; height: 38px; background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 16px; color: #FFFFFF; flex-shrink: 0;
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 500; opacity: 0.7;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 700; font-size: 14px; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px;
}
.branch-indicator-code {
    font-size: 10px; font-weight: 600; color: #FFFFFF;
    padding: 2px 10px; background: rgba(255, 255, 255, 0.15); border-radius: 12px;
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 11px;
    color: rgba(255,255,255,0.85); padding: 3px 10px;
    background: rgba(255, 255, 255, 0.08); border-radius: 12px; white-space: nowrap;
}
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.branch-indicator-right .date-display {
    font-size: 12px; color: rgba(255,255,255,0.85);
    padding: 5px 12px; background: rgba(255, 255, 255, 0.1);
    border-radius: 16px; display: flex; align-items: center; gap: 5px;
    white-space: nowrap;
}

/* Capital Card */
.capital-card-compact {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 14px; padding: 20px 26px; margin-bottom: 18px;
    display: flex; align-items: center; gap: 22px;
    box-shadow: 0 6px 24px rgba(30, 64, 175, 0.35);
    flex-wrap: wrap; max-width: 100%; position: relative; overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.capital-card-compact::before {
    content: ''; position: absolute; top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
    border-radius: 50%; pointer-events: none;
}
.capital-compact-icon {
    width: 60px; height: 60px; background: rgba(255,255,255,0.18);
    border-radius: 14px; display: flex; align-items: center;
    justify-content: center; font-size: 26px; color: #FFFFFF;
    flex-shrink: 0; position: relative; z-index: 1;
    backdrop-filter: blur(8px); border: 1.5px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}
.capital-compact-content {
    flex: 1; min-width: 0;
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px;
    position: relative; z-index: 1;
}
.capital-compact-item {
    display: flex; flex-direction: column; gap: 6px;
    padding: 8px 0; border-left: 3px solid rgba(255, 255, 255, 0.15);
    padding-left: 16px; transition: all 0.3s ease;
}
.capital-compact-item:hover { border-left-color: #FCD34D; transform: translateX(4px); }
.capital-compact-label {
    font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
    display: flex; align-items: center; gap: 6px;
}
.capital-compact-label i { font-size: 12px; color: #FCD34D; }
.capital-compact-value {
    font-size: 26px; font-weight: 900; letter-spacing: 0.5px;
    line-height: 1.15; word-break: break-word;
    font-family: 'Inter', 'Courier New', monospace;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.capital-float-value { color: #FCD34D !important; }
.capital-cash-value { color: #86EFAC !important; }
.capital-capital-value { color: #C4B5FD !important; }
.capital-compact-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(252, 211, 77, 0.22);
    color: #FCD34D; border-radius: 20px; font-size: 11px;
    font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
    flex-shrink: 0; white-space: nowrap;
    position: relative; z-index: 1;
    box-shadow: 0 2px 10px rgba(252, 211, 77, 0.2);
}

/* Summary Cards */
.summary-cards {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; margin-bottom: 18px; max-width: 100%;
}
.summary-card {
    position: relative; background: var(--bg-card);
    border-radius: 14px; padding: 18px 22px;
    border: 1.5px solid var(--border-color);
    display: flex; align-items: center; gap: 16px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0; overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.summary-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px var(--shadow-hover); }
.summary-card-reports { border-left: 4px solid #1D4ED8; }
.summary-card-deposits { border-left: 4px solid #059669; }
.summary-card-withdrawals { border-left: 4px solid #DC2626; }
.summary-icon-wrapper {
    width: 52px; height: 52px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0; transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}
.summary-card:hover .summary-icon-wrapper { transform: scale(1.08) rotate(-4deg); }
.summary-icon-blue { background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%); color: #1D4ED8; border: 1.5px solid #93C5FD; }
.summary-icon-green { background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%); color: #059669; border: 1.5px solid #6EE7B7; }
.summary-icon-red { background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%); color: #DC2626; border: 1.5px solid #FCA5A5; }
.summary-info { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 2px; }
.summary-label {
    font-size: 11px; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.summary-value {
    font-size: 22px; font-weight: 900; color: var(--text-primary);
    word-break: break-word; font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.2;
}
.summary-card-decoration {
    position: absolute; top: -30px; right: -30px;
    width: 100px; height: 100px; border-radius: 50%;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.05) 0%, rgba(0, 0, 0, 0.02) 100%);
    pointer-events: none;
}

/* Page Header */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px; max-width: 100%;
}
.page-header .header-left h2 { font-size: 20px; font-weight: 700; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted { font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0; }
.header-right { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; max-width: 100%; }

/* Buttons */
.btn {
    padding: 8px 16px; border: none; border-radius: 8px;
    font-weight: 600; font-size: 12px; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center;
    gap: 6px; transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-generate { background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%); color: white; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3); }
.btn-generate:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(124, 58, 237, 0.4); color: white; }
.btn-deposit { background: #059669; color: white; }
.btn-deposit:hover { background: #047857; transform: translateY(-1px); color: white; }
.btn-withdrawal { background: #DC2626; color: white; }
.btn-withdrawal:hover { background: #B91C1C; transform: translateY(-1px); color: white; }
.btn-export { background: #10B981; color: white; }
.btn-export:hover { background: #059669; transform: translateY(-1px); color: white; }
.btn-filter { background: #bb0404; color: white; }
.btn-filter:hover { background: #8a0303; color: white; }
.btn-reset { background: var(--bg-table-even); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-reset:hover { background: var(--bg-table-hover); color: var(--text-primary); }
.btn-secondary { background: var(--bg-table-even); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-sm { padding: 5px 12px; font-size: 11px; }

/* Filters */
.filters-bar {
    background: var(--bg-card); padding: 14px 18px;
    border-radius: 10px; border: 1px solid var(--border-color);
    margin-bottom: 16px; max-width: 100%;
}
.filters-form { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label {
    font-size: 11px; font-weight: 600; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-control {
    padding: 8px 12px; border: 1px solid var(--border-color);
    border-radius: 6px; font-size: 12px; color: var(--text-primary);
    background: var(--bg-input); transition: all 0.3s ease;
    min-width: 140px; font-family: 'Inter', sans-serif;
}
.form-control:focus {
    outline: none; border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

/* Dropdown */
.dropdown { position: relative; display: inline-block; }
.dropdown-menu {
    display: none; position: absolute; right: 0; top: 100%;
    margin-top: 4px; background: var(--bg-card); min-width: 180px;
    border-radius: 8px; box-shadow: 0 4px 20px var(--shadow-hover);
    border: 1px solid var(--border-color); z-index: 1000;
    overflow: hidden; padding: 4px 0;
}
.dropdown-menu.show { display: block; }
.dropdown-menu a {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 14px; text-decoration: none; color: var(--text-primary);
    font-size: 12px; transition: background 0.2s ease;
}
.dropdown-menu a:hover { background: var(--bg-table-hover); }

/* Global Search */
.search-bar-wrapper {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 16px; flex-wrap: wrap; max-width: 100%;
}
.search-input-group {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: 8px; padding: 6px 12px; width: 280px;
    transition: all 0.3s ease; max-width: 100%;
}
.search-input-group:focus-within { border-color: #bb0404; box-shadow: 0 0 0 3px rgba(187,4,4,0.1); }
.search-input-group > i { color: #bb0404; font-size: 12px; }
.search-input-group input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 12px; color: var(--text-primary);
    outline: none; font-family: 'Inter', sans-serif; min-width: 0;
}
.search-input-group input::placeholder { color: var(--text-light); font-size: 11px; }
.search-input-group button {
    background: #FEE2E2; color: #DC2626; border: none;
    width: 20px; height: 20px; border-radius: 50%; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; transition: all 0.2s ease; flex-shrink: 0;
}
.search-input-group button:hover { background: #DC2626; color: white; }
.search-count {
    font-size: 10px; font-weight: 700; padding: 2px 8px;
    background: #F59E0B; color: #FFFFFF; border-radius: 8px; flex-shrink: 0;
}
.record-count {
    font-size: 12px; font-weight: 600; color: var(--text-muted);
    padding: 6px 14px; background: var(--bg-card);
    border-radius: 8px; border: 1px solid var(--border-color); white-space: nowrap;
}

/* Report Group */
.report-group {
    background: var(--bg-card); border-radius: 12px;
    border: 1px solid var(--border-color); margin-bottom: 16px;
    overflow: hidden; box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease; max-width: 100%;
}
.report-group:hover { box-shadow: 0 4px 16px var(--shadow-hover); }
.report-group.hidden-by-search { display: none !important; }

.report-group-header {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    padding: 14px 20px; display: flex; justify-content: space-between;
    align-items: center; gap: 16px; flex-wrap: wrap; color: #FFFFFF; max-width: 100%;
}
.report-header-left { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
.report-icon {
    width: 42px; height: 42px; background: rgba(255,255,255,0.15);
    border-radius: 10px; display: flex; align-items: center;
    justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0;
}
.report-header-info { flex: 1; min-width: 0; }
.report-header-title { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.report-label { font-size: 11px; font-weight: 600; opacity: 0.7; text-transform: uppercase; letter-spacing: 1px; }
.report-number {
    font-size: 16px; font-weight: 800; color: #FFFFFF;
    font-family: 'Courier New', monospace; letter-spacing: 0.5px; word-break: break-all;
}
.report-header-meta { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.report-header-meta .meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; color: rgba(255,255,255,0.85); font-weight: 500; white-space: nowrap;
}
.report-header-right { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; flex-shrink: 0; }
.report-stat {
    display: flex; flex-direction: column; align-items: center;
    padding: 6px 14px; background: rgba(255,255,255,0.12);
    border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); white-space: nowrap;
}
.report-stat .stat-label {
    font-size: 9px; font-weight: 600; opacity: 0.7;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.report-stat .stat-value { font-size: 14px; font-weight: 800; color: #FFFFFF; margin-top: 2px; }
.report-stat .stat-value.text-success { color: #86EFAC; }
.report-stat .stat-value.text-danger { color: #FCA5A5; }
.report-header-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-view-report, .btn-edit-report, .btn-delete-report {
    padding: 6px 14px; border: none; border-radius: 6px;
    font-size: 11px; font-weight: 700; cursor: pointer;
    text-decoration: none; display: inline-flex; align-items: center;
    gap: 5px; transition: all 0.2s ease; white-space: nowrap;
}
.btn-view-report { background: rgba(255,255,255,0.2); color: #FFFFFF; border: 1px solid rgba(255,255,255,0.2); }
.btn-view-report:hover { background: #FFFFFF; color: #bb0404; }
.btn-edit-report { background: rgba(252, 211, 77, 0.25); color: #FCD34D; border: 1px solid rgba(252, 211, 77, 0.3); }
.btn-edit-report:hover { background: #FCD34D; color: #78350F; }
.btn-delete-report { background: rgba(248, 113, 113, 0.25); color: #FECACA; border: 1px solid rgba(248, 113, 113, 0.3); padding: 6px 10px; }
.btn-delete-report:hover { background: #FCA5A5; color: #7F1D1D; }

/* Providers Table */
.providers-table-wrapper {
    overflow-x: auto; overflow-y: hidden;
    max-width: 100%; -webkit-overflow-scrolling: touch; scroll-behavior: smooth;
}
.providers-table-wrapper::-webkit-scrollbar { height: 8px; }
.providers-table-wrapper::-webkit-scrollbar-track { background: var(--bg-table-even); border-radius: 4px; }
.providers-table-wrapper::-webkit-scrollbar-thumb { background: #bb0404; border-radius: 4px; }
.providers-table-wrapper::-webkit-scrollbar-thumb:hover { background: #8a0303; }

.providers-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 1200px; }

.table-search-row { background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%) !important; }
.table-search-cell {
    padding: 10px 14px !important;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%) !important;
    border-bottom: none !important; text-align: center !important;
}
.table-search-row-content {
    display: flex; align-items: center; justify-content: center;
    gap: 16px; flex-wrap: wrap; width: 100%; padding: 2px 0;
}
.table-search-wrapper {
    position: relative; display: inline-flex;
    align-items: center; gap: 6px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 8px; padding: 5px 12px;
    width: 280px; max-width: 100%; flex-shrink: 0; order: 1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
html.dark-mode .table-search-wrapper { background: rgba(30, 41, 59, 0.95); }
.table-search-icon { color: #bb0404; font-size: 12px; flex-shrink: 0; }
.table-search-input {
    flex: 1; border: none; background: transparent;
    padding: 4px 2px; font-size: 12px;
    font-family: 'Inter', sans-serif; color: #1F2937; outline: none; min-width: 0;
}
html.dark-mode .table-search-input { color: #F9FAFB; }
.table-search-input::placeholder { color: #9CA3AF; font-size: 11px; }
.table-search-clear {
    width: 18px; height: 18px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626; border: none;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; font-size: 8px;
    transition: all 0.2s ease; flex-shrink: 0;
}
.table-search-clear:hover { background: #DC2626; color: #FFFFFF; }
.table-search-count {
    font-size: 10px; font-weight: 700; padding: 2px 8px;
    background: #F59E0B; color: #FFFFFF; border-radius: 8px;
    white-space: nowrap; flex-shrink: 0;
}
.table-scroll-controls {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; padding: 0; background: transparent;
    border: none; flex-shrink: 0; order: 2; margin: 0 auto;
}
.scroll-label {
    font-size: 11px; font-weight: 800; color: #FCD34D;
    text-transform: uppercase; letter-spacing: 1px;
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap; text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.scroll-label i { font-size: 11px; color: #FCD34D; }
.scroll-btn {
    width: 36px; height: 36px; border-radius: 8px;
    border: 2px solid #FFFFFF; background: #FFFFFF;
    color: #bb0404; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D; transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.scroll-btn:active { transform: translateY(0); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3); }

.providers-table thead tr:not(.table-search-row) { background: var(--bg-table-even); }
.providers-table thead th:not(.table-search-cell) {
    padding: 11px 14px; text-align: left; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase;
    font-size: 10px; letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color); white-space: nowrap;
}
.providers-table thead th.text-right { text-align: right; }

.providers-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.providers-table tbody tr:hover { background: var(--bg-table-hover); }
.providers-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.providers-table tbody td {
    padding: 11px 14px; color: var(--text-primary); vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }
.provider-row.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--bg-table-hover); font-size: 11px;
    font-weight: 700; color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.date-cell { font-size: 11px; font-weight: 600; color: var(--text-secondary); white-space: nowrap; }
.provider-cell { display: flex; align-items: center; gap: 10px; min-width: 0; }
.provider-icon-circle {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #3B82F6, #2563EB);
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 13px; flex-shrink: 0;
}
.provider-name-text { font-weight: 600; color: var(--text-primary); font-size: 13px; white-space: nowrap; }
.code-badge {
    display: inline-block; padding: 3px 10px;
    background: #DBEAFE; color: #1D4ED8; border-radius: 8px;
    font-size: 10px; font-weight: 700;
    font-family: 'Courier New', monospace; letter-spacing: 0.5px; white-space: nowrap;
}
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; }

.employee-name-cell {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 3px 10px; background: #F3F4F6; color: #374151;
    border-radius: 8px; font-size: 11px; font-weight: 600;
    white-space: nowrap; border: 1px solid var(--border-color);
}
.employee-name-cell i { font-size: 10px; color: #bb0404; }
html.dark-mode .employee-name-cell { background: #334155; color: #CBD5E1; }

.amount-float {
    display: inline-block; padding: 3px 10px;
    background: #DBEAFE; color: #1D4ED8;
    border-radius: 6px; font-weight: 700; font-size: 12px;
    font-family: 'Courier New', monospace; border: 1px solid #BFDBFE; white-space: nowrap;
}
html.dark-mode .amount-float { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
.amount-float-bold {
    display: inline-block; padding: 3px 10px;
    background: #BFDBFE; color: #1E40AF;
    border-radius: 6px; font-weight: 800; font-size: 12px;
    font-family: 'Courier New', monospace; border: 1px solid #93C5FD; white-space: nowrap;
}
html.dark-mode .amount-float-bold { background: #1E40AF; color: #BFDBFE; border-color: #60A5FA; }
.amount-deposit {
    display: inline-block; padding: 3px 10px;
    background: #DCFCE7; color: #15803D;
    border-radius: 6px; font-weight: 700; font-size: 12px;
    font-family: 'Courier New', monospace; border: 1px solid #BBF7D0; white-space: nowrap;
}
html.dark-mode .amount-deposit { background: #14532D; color: #4ADE80; border-color: #16A34A; }
.amount-withdrawal {
    display: inline-block; padding: 3px 10px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 6px; font-weight: 700; font-size: 12px;
    font-family: 'Courier New', monospace; border: 1px solid #FECACA; white-space: nowrap;
}
html.dark-mode .amount-withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.provider-actions { display: flex; gap: 4px; justify-content: center; }
.btn-provider {
    width: 32px; height: 32px; border-radius: 6px; border: none;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.2s ease;
    text-decoration: none; font-size: 13px;
}
.btn-provider-view { background: #DBEAFE; color: #1D4ED8; border: 1px solid #BFDBFE; }
.btn-provider-view:hover { background: #1D4ED8; color: #FFFFFF; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3); }
.btn-provider-edit { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
.btn-provider-edit:hover { background: #D97706; color: #FFFFFF; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3); }
.btn-provider-delete { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
.btn-provider-delete:hover { background: #991B1B; color: #FFFFFF; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(153, 27, 27, 0.3); }
html.dark-mode .btn-provider-view { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .btn-provider-edit { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .btn-provider-delete { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.totals-row {
    background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%) !important;
    border-top: 2px solid #bb0404;
}
html.dark-mode .totals-row { background: linear-gradient(135deg, #2d3a4f 0%, #334155 100%) !important; }
.totals-row td { padding: 12px 14px; font-weight: 700; color: var(--text-primary); border-bottom: none; }

.no-providers-message {
    padding: 24px; text-align: center; color: var(--text-muted);
    font-size: 13px; display: flex; align-items: center;
    justify-content: center; gap: 8px; background: var(--bg-table-even);
}
.no-provider-results {
    text-align: center; padding: 30px 20px; background: var(--bg-table-even);
}
.no-provider-results i { font-size: 36px; color: var(--text-light); opacity: 0.4; display: block; margin-bottom: 10px; }
.no-provider-results p { font-size: 13px; color: var(--text-muted); margin: 0 0 12px 0; }

.empty-state, .no-search-results {
    text-align: center; padding: 60px 20px;
    background: var(--bg-card); border-radius: 12px;
    border: 1px solid var(--border-color); max-width: 100%;
}
.empty-state i, .no-search-results i {
    font-size: 56px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 16px;
}
.empty-state h3, .no-search-results h3 { font-size: 18px; color: var(--text-primary); margin: 0 0 8px 0; }
.empty-state p, .no-search-results p { color: var(--text-muted); font-size: 14px; margin: 0 0 20px 0; }

/* ============================================================
   TRANSACTION MODAL (Only from Header buttons)
   ============================================================ */
.txn-modal-overlay {
    display: none; position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    justify-content: center;
    align-items: center;
    padding: 20px;
    overflow-y: auto;
}
.txn-modal-overlay.show { display: flex; animation: fadeIn 0.2s ease forwards; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp {
    from { opacity: 0; transform: translateY(30px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.txn-modal {
    background: var(--bg-card);
    border-radius: 16px;
    width: 100%;
    max-width: 560px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: slideUp 0.3s ease forwards;
    border: 1px solid var(--border-color);
}

.txn-modal-header {
    padding: 20px 24px;
    border-radius: 16px 16px 0 0;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    overflow: hidden;
    color: white;
}
.txn-modal-header.txn-header-deposit { background: linear-gradient(135deg, #059669 0%, #10B981 100%); }
.txn-modal-header.txn-header-withdrawal { background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%); }
.txn-modal-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -20%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.txn-modal-header-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.25);
    backdrop-filter: blur(8px);
    position: relative;
    z-index: 1;
}
.txn-modal-header-content { flex: 1; min-width: 0; position: relative; z-index: 1; }
.txn-modal-header-content h3 {
    font-size: 18px; font-weight: 800;
    margin: 0 0 2px 0;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.txn-modal-header-content p {
    font-size: 12px; margin: 0;
    color: rgba(255, 255, 255, 0.85);
    font-weight: 500;
}
.txn-modal-close {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}
.txn-modal-close:hover { background: rgba(255, 255, 255, 0.3); transform: rotate(90deg); }

.txn-modal-body { padding: 24px; }

.txn-modal-message {
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 16px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.txn-modal-message.success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.txn-modal-message.error { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .txn-modal-message.success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .txn-modal-message.error { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

.txn-provider-info {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    animation: slideDown 0.3s ease forwards;
}
html.dark-mode .txn-provider-info { background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%); border-color: #3B82F6; }
.txn-provider-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: #0B5ED7;
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(11, 94, 215, 0.35);
}
.txn-provider-details { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.txn-provider-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: #1E40AF;
}
html.dark-mode .txn-provider-label { color: #93C5FD; }
.txn-provider-name {
    font-size: 15px; font-weight: 800;
    color: #1E293B;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
html.dark-mode .txn-provider-name { color: #F1F5F9; }
.txn-provider-code {
    font-size: 11px; font-weight: 700;
    color: #1D4ED8;
    background: #FFFFFF;
    padding: 2px 10px;
    border-radius: 8px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}
html.dark-mode .txn-provider-code { background: #0F172A; color: #60A5FA; }

.txn-form-group { margin-bottom: 16px; }
.txn-form-group label {
    display: block;
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.txn-form-group label .required { color: #DC2626; }
.txn-form-control {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
}
.txn-form-control:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    background: var(--bg-card);
}
.txn-amount-input {
    font-size: 20px !important;
    font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 1px;
    text-align: right;
    padding-right: 18px;
}
.txn-form-hint {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px; color: var(--text-muted); margin-top: 4px;
}
.txn-form-hint i { color: #2563EB; font-size: 11px; }

.txn-balance-preview {
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    animation: slideDown 0.3s ease forwards;
}
html.dark-mode .txn-balance-preview { background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%); border-color: #F59E0B; }
.txn-balance-item { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.txn-balance-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: #92400E;
    display: flex; align-items: center; gap: 5px;
}
html.dark-mode .txn-balance-label { color: #FCD34D; }
.txn-balance-label i { font-size: 11px; }
.txn-balance-value {
    font-size: 15px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    overflow-wrap: anywhere;
    line-height: 1.15;
}
.txn-float-value { color: #1D4ED8; }
html.dark-mode .txn-float-value { color: #60A5FA; }
.txn-cash-value { color: #059669; }
html.dark-mode .txn-cash-value { color: #34D399; }
.txn-after-value { color: #7C3AED; }
html.dark-mode .txn-after-value { color: #A78BFA; }

.txn-form-actions {
    display: flex; gap: 12px; padding-top: 8px; flex-wrap: wrap;
}
.txn-btn {
    padding: 12px 24px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    flex: 1; justify-content: center; min-width: 140px;
}
.txn-btn-cancel {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.txn-btn-cancel:hover { background: var(--bg-table-hover); color: var(--text-primary); }
.txn-btn-submit {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.35);
}
.txn-btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(30, 64, 175, 0.5); }
.txn-btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes highlight {
    0% { background: #FCD34D; transform: scale(1.05); }
    100% { background: ''; transform: scale(1); }
}

/* Responsive */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(3, 1fr); }
    .capital-compact-content { grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
    .capital-compact-value { font-size: 22px; }
}

@media (max-width: 768px) {
    .summary-cards { grid-template-columns: 1fr; }
    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; flex-wrap: wrap; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    
    .capital-card-compact { flex-direction: column; align-items: stretch; padding: 16px 18px; gap: 14px; }
    .capital-compact-icon { width: 50px; height: 50px; font-size: 22px; align-self: flex-start; }
    .capital-compact-content { grid-template-columns: 1fr; gap: 12px; }
    .capital-compact-value { font-size: 20px; }
    
    .search-input-group { width: 100%; }
    .report-group-header { flex-direction: column; align-items: flex-start; }
    .report-header-right { width: 100%; justify-content: space-between; }
    .report-header-actions { width: 100%; }
    .report-header-actions .btn-view-report,
    .report-header-actions .btn-edit-report { flex: 1; justify-content: center; }
    
    .table-search-row-content { flex-direction: column; align-items: center; gap: 10px; }
    .table-search-wrapper { width: 100%; order: 1; }
    .table-scroll-controls { justify-content: center; margin: 0 auto; order: 2; width: 100%; }
    
    .txn-modal { max-width: 95vw; max-height: 95vh; }
    .txn-modal-body { padding: 18px; }
    .txn-balance-preview { grid-template-columns: 1fr; gap: 10px; }
    .txn-form-actions { flex-direction: column; }
    .txn-btn { width: 100%; }
}

@media (max-width: 480px) {
    .summary-card { padding: 14px 16px; gap: 12px; }
    .summary-icon-wrapper { width: 44px; height: 44px; font-size: 18px; }
    .summary-value { font-size: 18px; }
    .summary-label { font-size: 10px; }
    .capital-compact-value { font-size: 17px; }
    .capital-compact-icon { width: 44px; height: 44px; font-size: 18px; }
    .scroll-btn { width: 32px; height: 32px; font-size: 13px; }
    .scroll-label { font-size: 9px; }
    .txn-modal-header-content h3 { font-size: 16px; }
    .txn-amount-input { font-size: 18px !important; }
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
    
    if (value === '') {
        input.value = '';
        return;
    }
    
    value = value.replace(/^0+/, '') || '0';
    
    if (value.length > 15) {
        value = value.substring(0, 15);
    }
    
    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) {
            formatted = ',' + formatted;
        }
        formatted = value[i] + formatted;
        count++;
    }
    
    input.value = formatted;
    
    const newLength = formatted.length;
    const newCursorPos = cursorPos + (newLength - oldLength);
    try {
        input.setSelectionRange(newCursorPos, newCursorPos);
    } catch (e) { }
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

function openTransactionModal(type, providerId = null, providerName = null) {
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
        if (providerId) {
            document.getElementById('txnAmount').focus();
        } else {
            document.getElementById('txnProviderSelect').focus();
        }
    }, 300);
}

function closeTransactionModal(event) {
    if (event && event.target !== event.currentTarget) return;
    
    const overlay = document.getElementById('txnModalOverlay');
    overlay.classList.remove('show');
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
        formData.append('branch_id', '<?php echo $selected_branch; ?>');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
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
    } catch (err) {
        console.error('Error:', err);
    }
}

function updateBalancePreview() {
    const amount = parseMoney(document.getElementById('txnAmount').value);
    const currentFloat = currentProviderData.float;
    
    let afterFloat = currentFloat;
    if (currentTxnType === 'deposit') {
        afterFloat = currentFloat + amount;
    } else {
        afterFloat = currentFloat - amount;
    }
    
    const afterEl = document.getElementById('txnAfterFloat');
    afterEl.textContent = formatMoney(afterFloat);
    
    if (currentTxnType === 'withdrawal' && afterFloat < 0) {
        afterEl.style.color = '#DC2626';
    } else {
        afterEl.style.color = '';
    }
}

async function submitTransaction(event) {
    event.preventDefault();
    
    const form = document.getElementById('txnForm');
    const formData = new FormData(form);
    const submitBtn = document.getElementById('txnSubmitBtn');
    
    const providerId = formData.get('provider_id');
    const amount = parseMoney(formData.get('amount'));
    
    if (!providerId) {
        showModalMessage('Tafadhali chagua provider.', 'error');
        return;
    }
    if (amount <= 0) {
        showModalMessage('Tafadhali weka amount sahihi.', 'error');
        return;
    }
    
    formData.set('amount', amount);
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Inatuma...';
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showModalMessage(data.message, 'success');
            
            updateTableRow(data.transaction);
            updateSummaryCards(data.transaction);
            
            setTimeout(() => {
                closeTransactionModal();
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                // Refresh page to get updated values
                window.location.reload();
            }, 1500);
        } else {
            showModalMessage(data.message || 'Kuna tatizo. Jaribu tena.', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
        }
    } catch (err) {
        console.error('Error:', err);
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

function updateTableRow(txn) {
    const rows = document.querySelectorAll(`.provider-row[data-provider-id="${txn.provider_id}"]`);
    
    rows.forEach(row => {
        const floatCell = row.querySelector('.current-float-cell');
        if (floatCell) {
            floatCell.textContent = txn.formatted_float;
            floatCell.style.animation = 'highlight 1s ease';
        }
        
        if (txn.type === 'deposit') {
            const depositCell = row.querySelector('.deposits-cell');
            if (depositCell) {
                const currentNum = parseMoney(depositCell.textContent);
                const newTotal = currentNum + parseFloat(txn.amount);
                depositCell.textContent = '+ ' + formatMoney(newTotal);
                depositCell.style.animation = 'highlight 1s ease';
            }
        }
        
        if (txn.type === 'withdrawal') {
            const withdrawalCell = row.querySelector('.withdrawals-cell');
            if (withdrawalCell) {
                const currentNum = parseMoney(withdrawalCell.textContent);
                const newTotal = currentNum + parseFloat(txn.amount);
                withdrawalCell.textContent = '- ' + formatMoney(newTotal);
                withdrawalCell.style.animation = 'highlight 1s ease';
            }
        }
    });
}

function updateSummaryCards(txn) {
    const depositEl = document.getElementById('totalDepositsDisplay');
    const withdrawalEl = document.getElementById('totalWithdrawalsDisplay');
    
    if (txn.type === 'deposit' && depositEl) {
        const currentNum = parseMoney(depositEl.textContent);
        const newTotal = currentNum + parseFloat(txn.amount);
        depositEl.textContent = formatMoney(newTotal);
        depositEl.style.animation = 'highlight 1s ease';
    }
    
    if (txn.type === 'withdrawal' && withdrawalEl) {
        const currentNum = parseMoney(withdrawalEl.textContent);
        const newTotal = currentNum + parseFloat(txn.amount);
        withdrawalEl.textContent = formatMoney(newTotal);
        withdrawalEl.style.animation = 'highlight 1s ease';
    }
}

// ============================================================
// SEARCH FUNCTIONS
// ============================================================
function onGlobalSearch(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const groups = document.querySelectorAll('.report-group');
    const clearBtn = document.getElementById('searchClear');
    const countBadge = document.getElementById('searchCount');
    const recordCount = document.getElementById('recordCount');
    const noResults = document.getElementById('noSearchResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        groups.forEach(g => g.classList.remove('hidden-by-search'));
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        if (recordCount) recordCount.textContent = groups.length + ' reports';
        return;
    }
    
    let matchCount = 0;
    groups.forEach(group => {
        const searchData = group.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            group.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            group.classList.add('hidden-by-search');
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    if (recordCount) recordCount.textContent = matchCount + ' of ' + groups.length + ' reports';
    if (noResults) noResults.style.display = matchCount === 0 ? 'block' : 'none';
}

function clearSearch() {
    const input = document.getElementById('searchInput');
    if (input) {
        input.value = '';
        onGlobalSearch(input);
        input.focus();
    }
}

function onTableSearch(input, reportId) {
    const searchTerm = input.value.toLowerCase().trim();
    const tbody = document.querySelector(`[data-report-tbody="${reportId}"]`);
    const clearBtn = document.querySelector(`.table-search-clear[data-report-id="${reportId}"]`);
    const countBadge = document.querySelector(`.table-search-count[data-report-id="${reportId}"]`);
    const noResults = document.querySelector(`.no-provider-results[data-report-id="${reportId}"]`);
    
    if (!tbody) return;
    const rows = tbody.querySelectorAll('.provider-row');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(row => row.classList.remove('hidden-by-search'));
        rows.forEach((row, idx) => {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = idx + 1;
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
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = visibleIdx++;
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    if (noResults) noResults.style.display = matchCount === 0 ? 'block' : 'none';
}

function clearTableSearch(reportId) {
    const input = document.querySelector(`.table-search-input[data-report-id="${reportId}"]`);
    if (input) {
        input.value = '';
        onTableSearch(input, reportId);
        input.focus();
    }
}

function scrollTable(direction, reportId) {
    const wrapper = document.getElementById('table-wrapper-' + reportId);
    if (!wrapper) return;
    const scrollAmount = 300;
    wrapper.scrollBy({ 
        left: direction === 'left' ? -scrollAmount : scrollAmount, 
        behavior: 'smooth' 
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const txnOverlay = document.getElementById('txnModalOverlay');
        if (txnOverlay && txnOverlay.classList.contains('show')) {
            closeTransactionModal();
            return;
        }
        
        const focused = document.activeElement;
        if (focused && focused.classList.contains('table-search-input')) {
            const reportId = focused.getAttribute('data-report-id');
            clearTableSearch(reportId);
        } else {
            const input = document.getElementById('searchInput');
            if (input && input.value.length > 0) clearSearch();
        }
    }
});

function toggleDropdown() {
    document.getElementById('exportMenu').classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-dropdown')) {
        var menu = document.getElementById('exportMenu');
        if (menu) menu.classList.remove('show');
    }
});

function exportData(format) {
    document.getElementById('exportMenu').classList.remove('show');
    var params = new URLSearchParams();
    params.set('format', format);
    var selectedBranch = '<?php echo $selected_branch; ?>';
    if (selectedBranch && selectedBranch !== '0') params.set('branch_id', selectedBranch);
    var fromDate = '<?php echo $from_date; ?>';
    var toDate = '<?php echo $to_date; ?>';
    if (fromDate) params.set('from_date', fromDate);
    if (toDate) params.set('to_date', toDate);
    window.location.href = 'export.php?' + params.toString();
}

function deleteReport(id) {
    if (confirm('Are you sure you want to delete this daily report?')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

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