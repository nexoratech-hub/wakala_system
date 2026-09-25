<?php
// ================================================================
// FILE: modules/daily_report/index.php
// WAKALA FINANCIAL SYSTEM - DAILY REPORTS (ADMIN)
// ✅ Export dropdown: PDF, CSV, Word
// ✅ All text in English
// ✅ Summary cards with soft/transparent background colors
// ✅ Bigger Add Deposit & Add Withdrawal buttons
// ✅ Time filters (All, Today, 1D, 1W, 1M, 3M, 6M, 1Y, Custom)
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
                throw new Exception('No daily report found. Please create a morning report first.');
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
                throw new Exception('No provider float found. Please create a morning report first.');
            }
            
            if ($transaction_type === 'withdrawal') {
                $new_float = $current_float - $amount;
                $new_cash = $current_cash + $amount;
            } else {
                $new_float = $current_float + $amount;
                $new_cash = $current_cash - $amount;
            }
            
            if ($new_float < 0) {
                throw new Exception('Insufficient float. Current float: TSh ' . number_format($current_float, 0));
            }
            if ($new_cash < 0) {
                throw new Exception('Insufficient cash. Current cash: TSh ' . number_format($current_cash, 0));
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
            
            logActivity($user_id, 'Add ' . ucfirst($transaction_type), 'Transactions', $transaction_id, '', 
                ucfirst($transaction_type) . ' of TSh ' . number_format($amount) . ' from ' . $provider['provider_name']);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => ucfirst($transaction_type) . ' of TSh ' . number_format($amount) . ' completed successfully!',
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
// TIME FILTER LOGIC
// ============================================================
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$custom_from = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$custom_to = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$today = date('Y-m-d');

switch ($filter) {
    case 'all':
        $from_date = '2000-01-01';
        $to_date = date('Y-m-d');
        break;
    case 'today':
        $from_date = $today;
        $to_date = $today;
        break;
    case '1d':
        $from_date = date('Y-m-d', strtotime('-1 day'));
        $to_date = $today;
        break;
    case '1w':
        $from_date = date('Y-m-d', strtotime('-7 days'));
        $to_date = $today;
        break;
    case '1m':
        $from_date = date('Y-m-d', strtotime('-1 month'));
        $to_date = $today;
        break;
    case '3m':
        $from_date = date('Y-m-d', strtotime('-3 months'));
        $to_date = $today;
        break;
    case '6m':
        $from_date = date('Y-m-d', strtotime('-6 months'));
        $to_date = $today;
        break;
    case '1y':
        $from_date = date('Y-m-d', strtotime('-1 year'));
        $to_date = $today;
        break;
    case 'custom':
        $from_date = !empty($custom_from) ? $custom_from : date('Y-m-01');
        $to_date = !empty($custom_to) ? $custom_to : $today;
        break;
    default:
        $from_date = date('Y-m-01');
        $to_date = $today;
}

// Branch filter
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
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

try {
    // Get reports with providers
    $sql = "SELECT 
                dr.id as report_id,
                dr.report_number,
                dr.report_date,
                dr.created_at,
                dr.net_profit,
                dr.current_capital,
                dr.current_cash,
                e.full_name as employee_name,
                b.branch_name as branch_name,
                b.branch_code as branch_code,
                drp.id as provider_row_id,
                drp.provider_id,
                drp.provider_code,
                drp.provider_name,
                drp.morning_float,
                drp.current_float,
                drp.total_deposits as provider_deposits,
                drp.total_withdrawals as provider_withdrawals,
                p.icon_class,
                p.color_code,
                p.provider_type
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN daily_report_providers drp ON dr.id = drp.daily_report_id
            LEFT JOIN providers p ON drp.provider_id = p.id
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
                'current_cash' => $row['current_cash'],
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
                'current_float' => $row['current_float'],
                'total_deposits' => $row['provider_deposits'],
                'total_withdrawals' => $row['provider_withdrawals'],
                'icon_class' => $row['icon_class'] ?? 'fas fa-university',
                'color_code' => $row['color_code'] ?? '#3B82F6',
                'provider_type' => $row['provider_type'] ?? 'bank'
            ];
        }
    }
    $reports = array_values($reports);

    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary
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

    // Transaction counts
    $sql_txn_count = "SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN transaction_type = 'deposit' THEN 1 ELSE 0 END) as deposit_count,
            SUM(CASE WHEN transaction_type = 'withdrawal' THEN 1 ELSE 0 END) as withdrawal_count
        FROM transactions
        WHERE DATE(transaction_date) BETWEEN ? AND ?";
    $params_txn_count = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql_txn_count .= " AND branch_id = ?";
        $params_txn_count[] = $selected_branch;
    }

    $stmt = $db->prepare($sql_txn_count);
    $stmt->execute($params_txn_count);
    $txn_count_result = $stmt->fetch(PDO::FETCH_ASSOC);

    $total_transactions_count = intval($txn_count_result['total_count'] ?? 0);
    $deposit_count = intval($txn_count_result['deposit_count'] ?? 0);
    $withdrawal_count = intval($txn_count_result['withdrawal_count'] ?? 0);

    // Total Float
    $sql_float = "SELECT COALESCE(SUM(drp.current_float), 0) as total_float
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

    // Total Cash
    $stmt = $db->prepare("
        SELECT current_cash FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_branch]);
    $dr_cash = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_cash = floatval($dr_cash['current_cash'] ?? 0);
    $total_capital = $total_float + $total_cash;

    // Providers for modal
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
    $total_transactions_count = 0;
    $deposit_count = 0;
    $withdrawal_count = 0;
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
                    <span class="branch-indicator-label">Current Branch</span>
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

        <!-- CAPITAL CARD -->
        <div class="capital-card-compact">
            <div class="capital-compact-icon">
                <i class="fas fa-university"></i>
            </div>
            <div class="capital-compact-content">
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-coins"></i> Float
                    </span>
                    <span class="capital-compact-value capital-float-value" id="totalFloatDisplay">
                        <?php echo formatCurrency($total_float); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-money-bill-wave"></i> Cash
                    </span>
                    <span class="capital-compact-value capital-cash-value" id="totalCashDisplay">
                        <?php echo formatCurrency($total_cash); ?>
                    </span>
                </div>
                <div class="capital-compact-item">
                    <span class="capital-compact-label">
                        <i class="fas fa-building"></i> Capital
                    </span>
                    <span class="capital-compact-value capital-capital-value" id="totalCapitalDisplay">
                        <?php echo formatCurrency($total_capital); ?>
                    </span>
                </div>
            </div>
            <div class="capital-compact-badge">
                <i class="fas fa-clock"></i> Live
            </div>
        </div>

        <!-- TIME FILTER BAR -->
        <div class="time-filter-bar">
            <div class="time-filter-left">
                <i class="fas fa-calendar-alt"></i>
                <span class="time-filter-label">Period:</span>
            </div>
            <div class="time-filter-buttons">
                <a href="?filter=all&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?filter=today&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Today</a>
                <a href="?filter=1d&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1d' ? 'active' : ''; ?>">1D</a>
                <a href="?filter=1w&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1w' ? 'active' : ''; ?>">1W</a>
                <a href="?filter=1m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1m' ? 'active' : ''; ?>">1M</a>
                <a href="?filter=3m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '3m' ? 'active' : ''; ?>">3M</a>
                <a href="?filter=6m&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '6m' ? 'active' : ''; ?>">6M</a>
                <a href="?filter=1y&branch_id=<?php echo $selected_branch; ?>" 
                   class="time-btn <?php echo $filter === '1y' ? 'active' : ''; ?>">1Y</a>
                <a href="?filter=custom&branch_id=<?php echo $selected_branch; ?>&from_date=<?php echo date('Y-m-01'); ?>&to_date=<?php echo date('Y-m-d'); ?>" 
                   class="time-btn time-btn-custom <?php echo $filter === 'custom' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i> Custom
                </a>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar-main">
            <form method="GET" action="" class="filter-form-main">
                <input type="hidden" name="filter" value="custom">
                
                <div class="filter-item">
                    <label><i class="fas fa-calendar-day"></i> From</label>
                    <input type="date" name="from_date" class="filter-input" 
                           value="<?php echo htmlspecialchars($from_date); ?>">
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-calendar-day"></i> To</label>
                    <input type="date" name="to_date" class="filter-input" 
                           value="<?php echo htmlspecialchars($to_date); ?>">
                </div>
                
                <div class="filter-item">
                    <label><i class="fas fa-store-alt"></i> Branch</label>
                    <select name="branch_id" class="filter-input filter-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter-main">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index.php" class="btn-reset-main">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards-soft">
            <div class="summary-card-soft summary-card-soft-purple">
                <div class="summary-icon-soft">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Transactions</span>
                    <span class="summary-value-soft" id="totalTransactionsDisplay">
                        <?php echo number_format($total_transactions_count); ?>
                    </span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-info-circle"></i> All deposits & withdrawals
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-green">
                <div class="summary-icon-soft">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Deposits</span>
                    <span class="summary-value-soft" id="totalDepositsDisplay">
                        <?php echo formatCurrency($summary['total_deposits'] ?? 0); ?>
                    </span>
                    <span class="summary-sub-soft" id="depositCountDisplay">
                        <i class="fas fa-list"></i> <?php echo number_format($deposit_count); ?> transactions
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-red">
                <div class="summary-icon-soft">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Withdrawals</span>
                    <span class="summary-value-soft" id="totalWithdrawalsDisplay">
                        <?php echo formatCurrency($summary['total_withdrawals'] ?? 0); ?>
                    </span>
                    <span class="summary-sub-soft" id="withdrawalCountDisplay">
                        <i class="fas fa-list"></i> <?php echo number_format($withdrawal_count); ?> transactions
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
            
            <div class="summary-card-soft summary-card-soft-blue">
                <div class="summary-icon-soft">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="summary-info-soft">
                    <span class="summary-label-soft">Total Reports</span>
                    <span class="summary-value-soft">
                        <?php echo number_format($summary['total_reports'] ?? 0); ?>
                    </span>
                    <span class="summary-sub-soft">
                        <i class="fas fa-check-circle"></i> Completed reports
                    </span>
                </div>
                <div class="summary-decoration-soft"></div>
            </div>
        </div>

        <!-- PAGE HEADER WITH BIGGER BUTTONS + EXPORT -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Daily Reports</h2>
                <p class="text-muted">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                </p>
            </div>
            <div class="header-right">
                <button type="button" class="btn-action-big btn-action-deposit" onclick="openTransactionModal('deposit')">
                    <i class="fas fa-arrow-down"></i>
                    <span>Add Deposit</span>
                </button>
                
                <button type="button" class="btn-action-big btn-action-withdrawal" onclick="openTransactionModal('withdrawal')">
                    <i class="fas fa-arrow-up"></i>
                    <span>Add Withdrawal</span>
                </button>
                
                <!-- EXPORT DROPDOWN - PDF, CSV, Word -->
                <div class="dropdown export-dropdown">
                    <button type="button" class="btn-action-big btn-action-export dropdown-toggle" onclick="toggleExportDropdown(event)">
                        <i class="fas fa-file-export"></i>
                        <span>Export</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </button>
                    <div class="dropdown-menu" id="exportDropdownMenu">
                        <a href="#" onclick="exportData('pdf'); return false;">
                            <i class="fas fa-file-pdf" style="color:#DC2626;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as PDF</span>
                                <span class="dropdown-item-desc">Print-ready document</span>
                            </div>
                        </a>
                        <a href="#" onclick="exportData('csv'); return false;">
                            <i class="fas fa-file-csv" style="color:#059669;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as CSV</span>
                                <span class="dropdown-item-desc">Excel compatible</span>
                            </div>
                        </a>
                        <a href="#" onclick="exportData('word'); return false;">
                            <i class="fas fa-file-word" style="color:#2563EB;"></i>
                            <div>
                                <span class="dropdown-item-title">Export as Word</span>
                                <span class="dropdown-item-desc">Microsoft Word document</span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
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
                                        <th colspan="9" class="table-search-cell">
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
                                    <tr>
                                        <th style="width: 50px;">#</th>
                                        <th>Date</th>
                                        <th>Provider</th>
                                        <th>Code</th>
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
                                        
                                        $provider_search = strtolower($p['provider_name'] . ' ' . $p['provider_code']);
                                        $provider_color = $p['color_code'] ?? '#3B82F6';
                                        $provider_icon = $p['icon_class'] ?? 'fas fa-university';
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
                                                    <i class="far fa-calendar"></i>
                                                    <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="provider-cell">
                                                    <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                                        <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                                    </div>
                                                    <span class="provider-name-text">
                                                        <?php echo htmlspecialchars($p['provider_name']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-float morning-float-cell">
                                                    <i class="fas fa-sun"></i>
                                                    <?php echo formatCurrency($p['morning_float']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-float-bold current-float-cell" data-provider-id="<?php echo $p['provider_id']; ?>">
                                                    <i class="fas fa-coins"></i>
                                                    <?php echo formatCurrency($p['current_float']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-deposit deposits-cell" data-provider-id="<?php echo $p['provider_id']; ?>">
                                                    <i class="fas fa-arrow-down"></i>
                                                    + <?php echo formatCurrency($p['total_deposits']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span class="amount-withdrawal withdrawals-cell" data-provider-id="<?php echo $p['provider_id']; ?>">
                                                    <i class="fas fa-arrow-up"></i>
                                                    - <?php echo formatCurrency($p['total_withdrawals']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="provider-actions">
                                                    <a href="view_provider_transactions.php?provider_id=<?php echo $p['provider_id']; ?>&branch_id=<?php echo $selected_branch; ?>&report_id=<?php echo $report['id']; ?>&report_date=<?php echo $report['report_date']; ?>" 
                                                       class="btn-provider btn-provider-view" 
                                                       title="View Transactions">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <a href="edit_provider.php?id=<?php echo $p['id']; ?>&branch_id=<?php echo $selected_branch; ?>" 
                                                       class="btn-provider btn-provider-edit" 
                                                       title="Edit Provider">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    
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
                                        <td colspan="4"><strong>TOTAL (<?php echo count($report['providers']); ?> providers)</strong></td>
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
                <p>No daily reports found in this period.</p>
                <p style="margin-top: 12px; font-size: 13px; color: var(--text-muted);">
                    <i class="fas fa-info-circle"></i>
                    Change the filter or create a morning report first.
                </p>
            </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- TRANSACTION MODAL -->
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

/* ============================================================
   BRANCH CARD
   ============================================================ */
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
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.branch-indicator-label {
    font-size: 10px; font-weight: 500; opacity: 0.7;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name { font-weight: 700; font-size: 14px; color: #FFFFFF; }
.branch-indicator-code {
    font-size: 10px; font-weight: 600; color: #FFFFFF;
    padding: 2px 10px; background: rgba(255, 255, 255, 0.15); border-radius: 12px;
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 11px;
    color: rgba(255,255,255,0.85); padding: 3px 10px;
    background: rgba(255, 255, 255, 0.08); border-radius: 12px; white-space: nowrap;
}
.date-display {
    font-size: 12px; color: rgba(255,255,255,0.85);
    padding: 5px 12px; background: rgba(255, 255, 255, 0.1);
    border-radius: 16px; display: flex; align-items: center; gap: 5px;
    white-space: nowrap;
}

/* ============================================================
   CAPITAL CARD
   ============================================================ */
.capital-card-compact {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 14px; padding: 18px 24px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 20px;
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
    width: 55px; height: 55px; background: rgba(255,255,255,0.18);
    border-radius: 14px; display: flex; align-items: center;
    justify-content: center; font-size: 24px; color: #FFFFFF;
    flex-shrink: 0; position: relative; z-index: 1;
    backdrop-filter: blur(8px); border: 1.5px solid rgba(255, 255, 255, 0.2);
}
.capital-compact-content {
    flex: 1; min-width: 0;
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;
    position: relative; z-index: 1;
}
.capital-compact-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 6px 0; border-left: 3px solid rgba(255, 255, 255, 0.15);
    padding-left: 14px; transition: all 0.3s ease;
}
.capital-compact-item:hover { border-left-color: #FCD34D; transform: translateX(4px); }
.capital-compact-label {
    font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
    display: flex; align-items: center; gap: 5px;
}
.capital-compact-label i { font-size: 11px; color: #FCD34D; }
.capital-compact-value {
    font-size: 22px; font-weight: 900; letter-spacing: 0.5px;
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
}

/* ============================================================
   TIME FILTER BAR
   ============================================================ */
.time-filter-bar {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.time-filter-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    flex-shrink: 0;
}

.time-filter-left i {
    color: #bb0404;
    font-size: 14px;
}

.time-filter-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    flex: 1;
}

.time-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.25s ease;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.time-btn:hover {
    background: var(--bg-table-hover);
    border-color: #bb0404;
    color: #bb0404;
    transform: translateY(-1px);
}

.time-btn.active {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    border-color: #8a0303;
    box-shadow: 0 4px 12px rgba(187, 4, 4, 0.35);
    transform: translateY(-1px);
}

.time-btn-custom {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    border-color: #6D28D9;
}

.time-btn-custom:hover {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    color: #FFFFFF;
    border-color: #5B21B6;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
}

.time-btn-custom.active {
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    border-color: #5B21B6;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.45);
}

/* ============================================================
   MAIN FILTER BAR
   ============================================================ */
.filter-bar-main {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.filter-form-main {
    display: flex;
    align-items: flex-end;
    gap: 14px;
    flex-wrap: wrap;
}

.filter-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
    min-width: 160px;
}

.filter-item label {
    font-size: 11px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.filter-item label i {
    color: #bb0404;
    font-size: 11px;
}

.filter-input {
    padding: 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.25s ease;
    width: 100%;
}

.filter-input:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187, 4, 4, 0.12);
    background: var(--bg-card);
}

.filter-select {
    cursor: pointer;
}

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    flex-shrink: 0;
}

.btn-filter-main {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 4px 12px rgba(187, 4, 4, 0.3);
    white-space: nowrap;
}

.btn-filter-main:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(187, 4, 4, 0.45);
    color: #FFFFFF;
}

.btn-reset-main {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.btn-reset-main:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
    border-color: #94A3B8;
    transform: translateY(-2px);
}

/* ============================================================
   SUMMARY CARDS - SOFT BACKGROUND
   ============================================================ */
.summary-cards-soft {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    max-width: 100%;
}

.summary-card-soft {
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

.summary-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}

.summary-card-soft-purple {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.summary-card-soft-purple .summary-icon-soft {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.summary-card-soft-purple .summary-value-soft { color: #6D28D9; }

.summary-card-soft-green {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.summary-card-soft-green .summary-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.summary-card-soft-green .summary-value-soft { color: #047857; }

.summary-card-soft-red {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.summary-card-soft-red .summary-icon-soft {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.summary-card-soft-red .summary-value-soft { color: #B91C1C; }

.summary-card-soft-blue {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.summary-card-soft-blue .summary-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.summary-card-soft-blue .summary-value-soft { color: #1D4ED8; }

html.dark-mode .summary-card-soft-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .summary-card-soft-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-card-soft-red { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .summary-card-soft-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .summary-card-soft-purple .summary-value-soft { color: #C4B5FD; }
html.dark-mode .summary-card-soft-green .summary-value-soft { color: #6EE7B7; }
html.dark-mode .summary-card-soft-red .summary-value-soft { color: #FCA5A5; }
html.dark-mode .summary-card-soft-blue .summary-value-soft { color: #93C5FD; }

.summary-icon-soft {
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

.summary-card-soft:hover .summary-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.summary-info-soft {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}

.summary-label-soft {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    font-weight: 800;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-value-soft {
    font-size: 20px;
    font-weight: 900;
    word-break: break-word;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

.summary-sub-soft {
    font-size: 10px;
    font-weight: 600;
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 3px;
    letter-spacing: 0.2px;
    line-height: 1.3;
}

.summary-sub-soft i {
    font-size: 9px;
    color: var(--text-light);
    flex-shrink: 0;
}

.summary-decoration-soft {
    position: absolute;
    top: -30px;
    right: -30px;
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    pointer-events: none;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
    max-width: 100%;
}

.page-header .header-left h2 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
    color: var(--text-primary);
}

.page-header .header-left h2 i {
    margin-right: 8px;
}

.page-header .header-left .text-muted {
    font-size: 12px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    max-width: 100%;
}

/* ============================================================
   BIGGER ACTION BUTTONS
   ============================================================ */
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

.btn-action-big i:first-child {
    font-size: 16px;
}

.btn-action-big::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s ease, height 0.6s ease;
}

.btn-action-big:hover::before {
    width: 300px;
    height: 300px;
}

.btn-action-big:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.btn-action-big > * {
    position: relative;
    z-index: 1;
}

.btn-action-deposit {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    color: #FFFFFF;
}
.btn-action-deposit:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    color: #FFFFFF;
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.45);
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

.btn-action-export {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
}
.btn-action-export:hover {
    background: linear-gradient(135deg, #1D4ED8 0%, #1E40AF 100%);
    color: #FFFFFF;
    box-shadow: 0 8px 24px rgba(37, 99, 235, 0.45);
}

.btn-action-export .dropdown-arrow {
    font-size: 11px;
    transition: transform 0.3s ease;
    margin-left: 2px;
}

/* ============================================================
   EXPORT DROPDOWN
   ============================================================ */
.dropdown {
    position: relative;
    display: inline-block;
}

.export-dropdown.open .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    min-width: 260px;
    background: var(--bg-card);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    z-index: 1000;
    animation: dropdownFadeIn 0.2s ease forwards;
}
.export-dropdown.open .dropdown-menu { display: block; }

@keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    text-decoration: none;
    color: var(--text-primary);
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
    border-bottom: 1px solid var(--border-color);
    position: relative;
}
.dropdown-menu a:last-child { border-bottom: none; }
.dropdown-menu a:hover {
    background: var(--bg-table-hover);
    padding-left: 22px;
}
.dropdown-menu a::before {
    content: '';
    position: absolute;
    left: 0; top: 0;
    width: 0; height: 100%;
    background: linear-gradient(90deg, #1E40AF, #2563EB);
    transition: width 0.2s ease;
}
.dropdown-menu a:hover::before { width: 4px; }
.dropdown-menu a > i {
    font-size: 24px;
    width: 30px;
    text-align: center;
    flex-shrink: 0;
}
.dropdown-menu a > div {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}
.dropdown-item-title {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
}
.dropdown-item-desc {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-muted);
}

/* ============================================================
   SEARCH BAR
   ============================================================ */
.search-bar-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    max-width: 100%;
}
.search-input-group {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 8px 14px;
    width: 320px;
    transition: all 0.3s ease;
    max-width: 100%;
}
.search-input-group:focus-within {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187, 4, 4, 0.12);
}
.search-input-group > i {
    color: #bb0404;
    font-size: 13px;
}
.search-input-group input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    font-family: 'Inter', sans-serif;
    min-width: 0;
}
.search-input-group input::placeholder {
    color: var(--text-light);
    font-size: 12px;
}
.search-input-group button {
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.search-input-group button:hover {
    background: #DC2626;
    color: white;
}
.search-count {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #F59E0B;
    color: #FFFFFF;
    border-radius: 8px;
    flex-shrink: 0;
}
.record-count {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    padding: 8px 16px;
    background: var(--bg-card);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    white-space: nowrap;
}

/* ============================================================
   REPORT GROUP
   ============================================================ */
.report-group {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    max-width: 100%;
}
.report-group:hover { box-shadow: 0 4px 16px var(--shadow-hover); }
.report-group.hidden-by-search { display: none !important; }

.report-group-header {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    color: #FFFFFF;
    max-width: 100%;
}
.report-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.report-icon {
    width: 42px;
    height: 42px;
    background: rgba(255,255,255,0.15);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #FFFFFF;
    flex-shrink: 0;
}
.report-header-info { flex: 1; min-width: 0; }
.report-header-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    flex-wrap: wrap;
}
.report-label {
    font-size: 11px;
    font-weight: 600;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.report-number {
    font-size: 16px;
    font-weight: 800;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    word-break: break-all;
}
.report-header-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.report-header-meta .meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    color: rgba(255,255,255,0.85);
    font-weight: 500;
    white-space: nowrap;
}
.report-header-right {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.report-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 6px 14px;
    background: rgba(255,255,255,0.12);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.15);
    white-space: nowrap;
}
.report-stat .stat-label {
    font-size: 9px;
    font-weight: 600;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.report-stat .stat-value {
    font-size: 14px;
    font-weight: 800;
    color: #FFFFFF;
    margin-top: 2px;
}
.report-stat .stat-value.text-success { color: #86EFAC; }
.report-stat .stat-value.text-danger { color: #FCA5A5; }
.report-header-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.btn-view-report, .btn-edit-report, .btn-delete-report {
    padding: 6px 14px;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.btn-view-report {
    background: rgba(255,255,255,0.2);
    color: #FFFFFF;
    border: 1px solid rgba(255,255,255,0.2);
}
.btn-view-report:hover { background: #FFFFFF; color: #bb0404; }
.btn-edit-report {
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border: 1px solid rgba(252, 211, 77, 0.3);
}
.btn-edit-report:hover { background: #FCD34D; color: #78350F; }
.btn-delete-report {
    background: rgba(248, 113, 113, 0.25);
    color: #FECACA;
    border: 1px solid rgba(248, 113, 113, 0.3);
    padding: 6px 10px;
}
.btn-delete-report:hover { background: #FCA5A5; color: #7F1D1D; }

/* ============================================================
   PROVIDERS TABLE
   ============================================================ */
.providers-table-wrapper {
    overflow-x: auto;
    overflow-y: hidden;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
    background: var(--bg-card);
}
.providers-table-wrapper::-webkit-scrollbar { height: 10px; }
.providers-table-wrapper::-webkit-scrollbar-track {
    background: var(--bg-table-even);
    border-radius: 5px;
    margin: 0 8px;
}
.providers-table-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #bb0404, #8a0303);
    border-radius: 5px;
}

.providers-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 13px;
    min-width: 1100px;
}

.table-search-row { background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%) !important; }
.table-search-cell {
    padding: 12px 16px !important;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%) !important;
    border-bottom: none !important;
    text-align: center !important;
    border-radius: 0 !important;
}
.table-search-row-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    flex-wrap: wrap;
    width: 100%;
    padding: 2px 0;
}
.table-search-wrapper {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 10px;
    padding: 8px 14px;
    width: 300px;
    max-width: 100%;
    flex-shrink: 0;
    order: 1;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    border: 2px solid transparent;
    transition: all 0.3s ease;
}
.table-search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
}
.table-search-icon { color: #bb0404; font-size: 13px; flex-shrink: 0; }
.table-search-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 2px;
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-weight: 500;
}
.table-search-input::placeholder { color: #9CA3AF; font-size: 12px; }
.table-search-clear {
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
.table-search-clear:hover { background: #DC2626; color: #FFFFFF; }
.table-search-count {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}
.table-scroll-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    flex-shrink: 0;
    order: 2;
    margin: 0 auto;
}
.scroll-label {
    font-size: 11px;
    font-weight: 800;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.scroll-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #bb0404;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
}

.providers-table thead tr:not(.table-search-row) {
    background: linear-gradient(135deg, #F8FAFC 0%, #E2E8F0 100%);
}
.providers-table thead th:not(.table-search-cell) {
    padding: 14px 16px;
    text-align: left;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 1px;
    border-bottom: 3px solid #bb0404;
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 5;
}
.providers-table thead th.text-right { text-align: right; }

.providers-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: all 0.25s ease;
}
.providers-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(187, 4, 4, 0.04), rgba(187, 4, 4, 0.02));
}
.providers-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.providers-table tbody td {
    padding: 14px 16px;
    color: var(--text-primary);
    vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }
.provider-row.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: linear-gradient(135deg, #F1F5F9, #E2E8F0);
    font-size: 11px;
    font-weight: 800;
    color: #475569;
    border: 2px solid #CBD5E1;
}
.date-cell {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-secondary);
    white-space: nowrap;
}
.date-cell i { color: #bb0404; font-size: 11px; }
.provider-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
.provider-icon-circle {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
    border: 2px solid rgba(255, 255, 255, 0.3);
    transition: all 0.3s ease;
}
.provider-icon-circle:hover { transform: scale(1.08) rotate(-5deg); }
.provider-name-text {
    font-weight: 800;
    color: var(--text-primary);
    font-size: 13px;
    white-space: nowrap;
    letter-spacing: 0.2px;
}
.code-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
}
.amount-float {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}
.amount-float-bold {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    background: linear-gradient(135deg, #1E40AF, #2563EB);
    color: #FFFFFF;
    border-radius: 8px;
    font-weight: 900;
    font-size: 13px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #1E40AF;
    white-space: nowrap;
    box-shadow: 0 3px 8px rgba(30, 64, 175, 0.25);
}
.amount-deposit {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #DCFCE7, #BBF7D0);
    color: #15803D;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #86EFAC;
    white-space: nowrap;
}
.amount-withdrawal {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FCA5A5;
    white-space: nowrap;
}

.provider-actions { display: flex; gap: 6px; justify-content: center; }
.btn-provider {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    font-size: 14px;
    position: relative;
    overflow: hidden;
}
.btn-provider-view {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}
.btn-provider-view:hover {
    background: linear-gradient(135deg, #1D4ED8, #2563EB);
    color: #FFFFFF;
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.4);
}
.btn-provider-edit {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border: 1.5px solid #FCD34D;
}
.btn-provider-edit:hover {
    background: linear-gradient(135deg, #D97706, #F59E0B);
    color: #FFFFFF;
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 16px rgba(217, 119, 6, 0.4);
}
.btn-provider-delete {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
.btn-provider-delete:hover {
    background: linear-gradient(135deg, #991B1B, #DC2626);
    color: #FFFFFF;
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 6px 16px rgba(153, 27, 27, 0.4);
}

.totals-row {
    background: linear-gradient(135deg, #F8FAFC 0%, #E2E8F0 100%) !important;
    border-top: 3px solid #bb0404;
}
.totals-row td {
    padding: 16px 16px;
    font-weight: 900;
    color: var(--text-primary);
    border-bottom: none;
    font-size: 13px;
}

.no-providers-message {
    padding: 30px;
    text-align: center;
    color: var(--text-muted);
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: var(--bg-table-even);
    font-weight: 500;
}
.no-provider-results {
    text-align: center;
    padding: 40px 20px;
    background: var(--bg-table-even);
}
.no-provider-results i {
    font-size: 44px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 12px;
}
.no-provider-results p {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 16px 0;
    font-weight: 500;
}

.empty-state, .no-search-results {
    text-align: center;
    padding: 60px 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    max-width: 100%;
}
.empty-state i, .no-search-results i {
    font-size: 56px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3, .no-search-results h3 {
    font-size: 18px;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p, .no-search-results p {
    color: var(--text-muted);
    font-size: 14px;
    margin: 0 0 20px 0;
}

.btn {
    padding: 10px 22px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-secondary {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); color: var(--text-primary); }
.btn-sm { padding: 5px 12px; font-size: 11px; }

/* ============================================================
   TRANSACTION MODAL
   ============================================================ */
.txn-modal-overlay {
    display: none;
    position: fixed;
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
@keyframes slideUpModal {
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
    animation: slideUpModal 0.3s ease forwards;
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
.txn-modal-header.txn-header-deposit {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
}
.txn-modal-header.txn-header-withdrawal {
    background: linear-gradient(135deg, #DC2626 0%, #EF4444 100%);
}
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
    position: relative;
    z-index: 1;
}
.txn-modal-header-content { flex: 1; min-width: 0; position: relative; z-index: 1; }
.txn-modal-header-content h3 {
    font-size: 18px; font-weight: 800;
    margin: 0 0 2px 0; color: #FFFFFF;
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

.txn-provider-info {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border: 2px solid #93C5FD;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.txn-provider-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: #0B5ED7;
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.txn-provider-details { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.txn-provider-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: #1E40AF;
}
.txn-provider-name {
    font-size: 15px; font-weight: 800; color: #1E293B;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.txn-provider-code {
    font-size: 11px; font-weight: 700;
    color: #1D4ED8;
    background: #FFFFFF;
    padding: 2px 10px;
    border-radius: 8px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}

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

.txn-balance-preview {
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.txn-balance-item { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.txn-balance-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: #92400E;
    display: flex; align-items: center; gap: 5px;
}
.txn-balance-value {
    font-size: 15px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
}
.txn-float-value { color: #1D4ED8; }
.txn-cash-value { color: #059669; }
.txn-after-value { color: #7C3AED; }

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

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-cards-soft { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 1024px) {
    .capital-compact-content { grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
    .capital-compact-value { font-size: 18px; }
    .filter-form-main { flex-wrap: wrap; }
    .filter-item { min-width: 140px; }
}

@media (max-width: 768px) {
    .summary-cards-soft { grid-template-columns: 1fr; }
    
    .time-filter-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .time-filter-left { justify-content: center; }
    .time-filter-buttons { justify-content: center; }
    .time-btn { flex: 1; min-width: 60px; }
    
    .filter-form-main {
        flex-direction: column;
        align-items: stretch;
    }
    .filter-item { min-width: 100%; }
    .filter-actions {
        width: 100%;
        flex-direction: column;
    }
    .btn-filter-main, .btn-reset-main {
        width: 100%;
        justify-content: center;
    }
    
    .header-right { width: 100%; flex-wrap: wrap; }
    .btn-action-big {
        flex: 1;
        min-width: 140px;
        justify-content: center;
        padding: 12px 20px;
        font-size: 13px;
    }
    
    .export-dropdown { flex: 1; }
    .export-dropdown .btn-action-export {
        width: 100%;
        justify-content: center;
    }
    
    .page-header { flex-direction: column; align-items: flex-start; }
    
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    
    .capital-card-compact {
        flex-direction: column;
        align-items: stretch;
        padding: 16px 18px;
        gap: 14px;
    }
    .capital-compact-icon {
        width: 50px; height: 50px; font-size: 22px;
        align-self: flex-start;
    }
    .capital-compact-content { grid-template-columns: 1fr; gap: 12px; }
    .capital-compact-value { font-size: 18px; }
    
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
    .summary-card-soft { padding: 14px 16px; gap: 12px; }
    .summary-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .summary-value-soft { font-size: 16px; }
    .summary-label-soft { font-size: 9px; }
    .summary-sub-soft { font-size: 9px; }
    
    .capital-compact-value { font-size: 16px; }
    .capital-compact-icon { width: 44px; height: 44px; font-size: 18px; }
    
    .time-btn { font-size: 11px; padding: 7px 12px; }
    .time-filter-label { font-size: 10px; }
    
    .btn-action-big { padding: 11px 16px; font-size: 12px; }
    .btn-action-big i:first-child { font-size: 14px; }
    
    .filter-item label { font-size: 10px; }
    .filter-input { font-size: 12px; padding: 9px 12px; }
    
    .scroll-btn { width: 32px; height: 32px; font-size: 13px; }
    .scroll-label { font-size: 9px; }
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
// TOGGLE EXPORT DROPDOWN
// ============================================================
function toggleExportDropdown(event) {
    event.stopPropagation();
    const dropdown = document.querySelector('.export-dropdown');
    dropdown.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    const dropdown = document.querySelector('.export-dropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.remove('open');
    }
});

// ============================================================
// EXPORT DATA
// ============================================================
function exportData(format) {
    const dropdown = document.querySelector('.export-dropdown');
    if (dropdown) dropdown.classList.remove('open');
    
    const urlParams = new URLSearchParams(window.location.search);
    const fromDate = urlParams.get('from_date') || '<?php echo $from_date; ?>';
    const toDate = urlParams.get('to_date') || '<?php echo $to_date; ?>';
    const branchId = '<?php echo $selected_branch; ?>';
    
    const exportUrl = `export.php?format=${format}&from_date=${fromDate}&to_date=${toDate}&branch_id=${branchId}`;
    
    const formatLabels = { 'pdf': 'PDF', 'csv': 'CSV', 'word': 'Word' };
    
    const msg = `Export Daily Reports as ${formatLabels[format]}?\n\n` +
                `Period: ${fromDate} to ${toDate}\n` +
                `Branch: <?php echo htmlspecialchars($branch_name); ?>\n` +
                `Records: <?php echo count($reports); ?> reports`;
    
    if (confirm(msg)) {
        const exportBtn = document.querySelector('.btn-action-export');
        const originalContent = exportBtn.innerHTML;
        exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Exporting...</span>';
        exportBtn.disabled = true;
        
        window.location.href = exportUrl;
        
        setTimeout(function() {
            exportBtn.innerHTML = originalContent;
            exportBtn.disabled = false;
        }, 2000);
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
        formData.append('branch_id', '<?php echo $selected_branch; ?>');
        
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
    
    if (!providerId) { showModalMessage('Please select a provider.', 'error'); return; }
    if (amount <= 0) { showModalMessage('Please enter a valid amount.', 'error'); return; }
    
    formData.set('amount', amount);
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
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
            showModalMessage(data.message || 'An error occurred. Please try again.', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save';
        }
    } catch (err) {
        showModalMessage('Network error. Please try again.', 'error');
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

function deleteReport(id) {
    if (confirm('Are you sure you want to delete this daily report?')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const txnOverlay = document.getElementById('txnModalOverlay');
        if (txnOverlay && txnOverlay.classList.contains('show')) {
            closeTransactionModal();
            return;
        }
        
        const dropdown = document.querySelector('.export-dropdown');
        if (dropdown && dropdown.classList.contains('open')) {
            dropdown.classList.remove('open');
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