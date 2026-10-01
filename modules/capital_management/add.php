<?php
// ================================================================
// FILE: modules/capital_management/add.php
// WAKALA FINANCIAL SYSTEM - ADD CAPITAL TRANSACTION
// ✅ GREEN THEME + BEAUTIFUL CARDS
// ✅ Cash on TOP, Providers on BOTTOM
// ✅ 3 providers per row
// ✅ NO DUPLICATE — Salaries counted ONCE only
// ✅ ALL TIME profit (kutoka 2000-01-01 hadi leo)
// ✅ NO AUTO-FILL — wewe mwenyewe unachagua cash au provider
// ✅ LIVE SHARED POOL — cash + providers zote zinahesabiwa
// ✅ Cash + Providers zote zinahesabiwa kwenye Already Allocated
// ✅ MODAL VALIDATION: Beautiful error modal (no alert())
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

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// HELPER: Calculate TOTAL FLOAT from LATEST record per provider
// ============================================================
function calculateTotalFloatForBranch($db, $branch_id) {
    $stmt = $db->prepare("
        SELECT id FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dr) return 0;
    
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
    $stmt->execute([$dr['id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return floatval($result['total_float'] ?? 0);
}

// ============================================================
// HELPER: Pata current capital kwa branch
// ============================================================
function getCurrentCapitalForBranch($db, $branch_id) {
    $result = ['cash' => 0, 'float' => 0, 'capital' => 0, 'source' => 'none', 'has_daily_report' => false];
    
    $stmt = $db->prepare("
        SELECT id, current_cash, current_capital
        FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dr) {
        $cash = floatval($dr['current_cash'] ?? 0);
        $float = calculateTotalFloatForBranch($db, $branch_id);
        $capital = $float + $cash;
        
        if ($cash > 0 || $float > 0 || $capital > 0) {
            $result['cash'] = $cash;
            $result['float'] = $float;
            $result['capital'] = $capital;
            $result['source'] = 'daily_reports';
            $result['has_daily_report'] = true;
            return $result;
        }
    }
    
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
    
    return $result;
}

/**
 * Get current float ya kila provider kwa branch
 */
function getProviderFloats($db, $branch_id) {
    $floats = [];
    
    $stmt = $db->prepare("
        SELECT id FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dr) {
        $stmt = $db->prepare("
            SELECT drp1.provider_id, drp1.current_float
            FROM daily_report_providers drp1
            INNER JOIN (
                SELECT provider_id, MAX(id) as max_id
                FROM daily_report_providers
                WHERE daily_report_id = ?
                GROUP BY provider_id
            ) drp2 ON drp1.id = drp2.max_id
        ");
        $stmt->execute([$dr['id']]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $floats[intval($row['provider_id'])] = floatval($row['current_float']);
        }
    }
    
    if (!empty($floats)) return $floats;
    
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
 * Check kama branch ina daily_report
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
// FIXED: Get available profit kwa branch
//
// ✅ NO DUPLICATE — Salaries counted ONCE only
// ✅ ALL TIME profit (default: 2000-01-01 to today)
// ✅ Already Allocated = CASH + PROVIDERS zote
// ============================================================
function getAvailableProfit($db, $branch_id, $from_date = null, $to_date = null) {
    if (!$from_date) $from_date = '2000-01-01';
    if (!$to_date)   $to_date   = date('Y-m-d');
    
    // 1. COMMISSION
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(total_commission), 0) as total_commission,
            COALESCE(SUM(other_income), 0) as other_income
        FROM commissions 
        WHERE branch_id = ? 
          AND commission_date BETWEEN ? AND ?
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $commission = floatval($row['total_commission'] ?? 0);
    $other_income_comm = floatval($row['other_income'] ?? 0);
    
    // 2. OTHER INCOME (fallback)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(other_income), 0) as other_income
        FROM daily_reports 
        WHERE branch_id = ? 
          AND report_date BETWEEN ? AND ?
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $other_income_dr = floatval($row['other_income'] ?? 0);
    $other_income = max($other_income_comm, $other_income_dr);
    
    // 3. EXPENSES (Jumla ya ALL — ina salaries)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_expenses
        FROM expenses 
        WHERE branch_id = ? 
          AND expense_date BETWEEN ? AND ?
          AND is_business_expense = 1
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_expenses = floatval($row['total_expenses'] ?? 0);
    
    // 3b. Check salary-related expenses
    $stmt = $db->prepare("
        SELECT COUNT(*) as count_salary_expenses
        FROM expenses 
        WHERE branch_id = ? 
          AND expense_date BETWEEN ? AND ?
          AND is_salary_related = 1
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $salary_expenses_count = intval($row['count_salary_expenses'] ?? 0);
    
    // 4. SALARIES — TUMA TU KAMA EXPENSES HAINA SALARY RECORDS
    $salaries = 0;
    $using_salary_from_table = false;
    
    if ($salary_expenses_count == 0) {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(net_pay), 0) as total_salaries
            FROM employee_salaries 
            WHERE branch_id = ? 
              AND payment_date BETWEEN ? AND ?
              AND status = 'paid'
        ");
        $stmt->execute([$branch_id, $from_date, $to_date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $salaries = floatval($row['total_salaries'] ?? 0);
        $using_salary_from_table = true;
    }
    
    // 5. CASH OUT
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_cash_out
        FROM capital_management 
        WHERE branch_id = ? 
          AND transaction_date BETWEEN ? AND ?
          AND transaction_type IN ('cash_out', 'adjustment')
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cash_out = floatval($row['total_cash_out'] ?? 0);
    
    // 6. TOTAL PROFIT
    $total_profit = $commission + $other_income - $total_expenses - $salaries - $cash_out;
    
    // 7. ALREADY ALLOCATED — ✅ CASH + PROVIDERS ZOTE
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN reference_module = 'cash' THEN amount ELSE 0 END), 0) as cash_allocated,
            COALESCE(SUM(CASE WHEN reference_module = 'provider' THEN amount ELSE 0 END), 0) as provider_allocated,
            COALESCE(SUM(amount), 0) as total_allocated
        FROM capital_management 
        WHERE branch_id = ? 
          AND transaction_date BETWEEN ? AND ?
          AND transaction_type = 'profit_allocation'
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cash_allocated = floatval($row['cash_allocated'] ?? 0);
    $provider_allocated = floatval($row['provider_allocated'] ?? 0);
    $already_allocated = floatval($row['total_allocated'] ?? 0);
    
    // 8. AVAILABLE PROFIT
    $available_profit = max(0, $total_profit - $already_allocated);
    
    // 9. COMMISSION BREAKDOWN per provider
    $stmt = $db->prepare("
        SELECT provider_data, total_commission
        FROM commissions 
        WHERE branch_id = ?
          AND commission_date BETWEEN ? AND ?
    ");
    $stmt->execute([$branch_id, $from_date, $to_date]);
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $provider_commissions = [];
    foreach ($commissions as $c) {
        $data = json_decode($c['provider_data'] ?? '{}', true);
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
    
    return [
        'profit' => $available_profit,
        'total_profit' => $total_profit,
        'already_allocated' => $already_allocated,
        'cash_allocated' => $cash_allocated,
        'provider_allocated' => $provider_allocated,
        'commission' => $commission,
        'other_income' => $other_income,
        'expenses' => $total_expenses,
        'salaries' => $salaries,
        'salary_expenses_count' => $salary_expenses_count,
        'using_salary_from_table' => $using_salary_from_table,
        'cash_out' => $cash_out,
        'provider_commissions' => $provider_commissions,
        'from_date' => $from_date,
        'to_date' => $to_date
    ];
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
        
        // ✅ VALIDATION: Profit Allocation — Total (cash + providers) ≤ Available
        if ($transaction_type === 'profit_allocation') {
            $profit_data_check = getAvailableProfit($db, $branch_id);
            $total_input = $cash_amount + $total_provider;
            
            if ($total_input > $profit_data_check['profit']) {
                throw new Exception(
                    'Profit Allocation exceeds available profit. ' .
                    'Available: ' . formatCurrency($profit_data_check['profit']) . 
                    ', Requested: ' . formatCurrency($total_input)
                );
            }
        }
        
        if ($is_out) {
            $current = getCurrentCapitalForBranch($db, $branch_id);
            $has_dr = hasDailyReport($db, $branch_id);
            
            if ($has_cash && $cash_amount > 0) {
                if (!$has_dr && $current['cash'] <= 0) {
                    throw new Exception('Cannot perform Cash Out without a Daily Report. Please create Opening Capital first.');
                }
                if ($cash_amount > $current['cash']) {
                    throw new Exception('Insufficient cash. Available: ' . formatCurrency($current['cash']) . ', Requested: ' . formatCurrency($cash_amount) . '.');
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
                        throw new Exception('Cannot perform Cash Out for ' . $pname . ' without a Daily Report.');
                    }
                    if ($amt > $available) {
                        throw new Exception('Insufficient float for ' . $pname . '. Available: ' . formatCurrency($available) . ', Requested: ' . formatCurrency($amt) . '.');
                    }
                }
            }
            
            $total_out = $cash_amount;
            foreach ($provider_amounts as $amt_raw) {
                $total_out += floatval(str_replace(',', '', $amt_raw));
            }
            
            if ($total_out > $current['capital']) {
                throw new Exception('Total exceeds available Capital. Available: ' . formatCurrency($current['capital']) . ', Requested: ' . formatCurrency($total_out) . '.');
            }
        }
        
        $db->beginTransaction();
        
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
        
        if (!$dr_id && !$is_out) {
            $report_number = 'DR-' . date('Ymd') . '-' . mt_rand(1000, 9999);
            $stmt = $db->prepare("
                INSERT INTO daily_reports 
                (report_number, employee_id, branch, branch_id, report_date,
                 current_cash, current_float, current_capital, created_at)
                VALUES (?, ?, ?, ?, ?, 0, 0, 0, NOW())
            ");
            $stmt->execute([$report_number, $user_id, $branch_name, $branch_id, $transaction_date]);
            $dr_id = $db->lastInsertId();
            $latest_dr = ['id' => $dr_id, 'current_cash' => 0, 'current_capital' => 0];
        }
        
        $total_added = 0;
        $transactions_created = [];
        
        // PROCESS CASH
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
                $new_cash = $is_out ? max(0, $old_cash - $cash_amount) : $old_cash + $cash_amount;
                $total_float = calculateTotalFloatForBranch($db, $branch_id);
                $new_capital = $total_float + $new_cash;
                
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET current_cash = ?, current_float = ?, current_capital = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_cash, $total_float, $new_capital, $dr_id]);
                
                $latest_dr['current_cash'] = $new_cash;
                $latest_dr['current_capital'] = $new_capital;
            }
            
            $total_added += $cash_amount;
        }
        
        // PROCESS PROVIDERS
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
                    
                    $current_cash = floatval($latest_dr['current_cash']);
                    $new_total_float = calculateTotalFloatForBranch($db, $branch_id);
                    $new_capital = $new_total_float + $current_cash;
                    
                    $stmt = $db->prepare("
                        UPDATE daily_reports 
                        SET current_float = ?, current_capital = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$new_total_float, $new_capital, $dr_id]);
                    
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
        header('Location: add.php?branch=' . $branch_id);
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

// GET AVAILABLE PROFIT (ALL TIME)
$profit_data = [
    'profit' => 0, 'total_profit' => 0, 'already_allocated' => 0,
    'cash_allocated' => 0, 'provider_allocated' => 0,
    'commission' => 0, 'other_income' => 0, 'expenses' => 0, 
    'salaries' => 0, 'cash_out' => 0,
    'salary_expenses_count' => 0, 'using_salary_from_table' => false,
    'provider_commissions' => [],
    'from_date' => '2000-01-01', 'to_date' => date('Y-m-d')
];

if ($selected_branch > 0) {
    $profit_data = getAvailableProfit($db, $selected_branch);
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
        $p['provider_commission'] = $profit_data['provider_commissions'][$pid] ?? 0;
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
                <p class="text-muted">Fill in Cash and Provider amounts together</p>
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

        <!-- NO DAILY REPORT WARNING -->
        <?php if ($selected_branch > 0 && !$has_daily_report && $current_capital <= 0): ?>
            <div class="no-daily-report-warning">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>No Opening Capital for this branch</strong>
                    <p>
                        You can add <strong>Opening Capital</strong>, <strong>Additional Capital</strong>, 
                        or <strong>Profit Allocation</strong>. 
                        However, <strong>Cash Out will not be allowed</strong> until you have sufficient capital.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- PROFIT AVAILABILITY BANNER -->
        <?php if ($selected_branch > 0 && $profit_data['total_profit'] > 0): ?>
            <div class="profit-available-banner">
                <div class="pab-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="pab-content">
                    <span class="pab-label">
                        Available Profit (All Time — Shared Pool)
                        <?php if ($profit_data['already_allocated'] > 0): ?>
                            <span style="color: #FCD34D; font-weight: 700; margin-left: 8px;">
                                (<?php echo formatCurrency($profit_data['already_allocated']); ?> already used)
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="pab-value" id="pabTotalValue"><?php echo formatCurrency($profit_data['profit']); ?></span>
                    
                    <div class="pab-breakdown">
                        <span class="pab-item">
                            <i class="fas fa-hand-holding-usd"></i>
                            Commission: <strong><?php echo formatCurrency($profit_data['commission']); ?></strong>
                        </span>
                        <?php if ($profit_data['other_income'] > 0): ?>
                            <span class="pab-item">
                                <i class="fas fa-plus-circle"></i>
                                Other Income: <strong><?php echo formatCurrency($profit_data['other_income']); ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($profit_data['expenses'] > 0): ?>
                            <span class="pab-item pab-item-neg">
                                <i class="fas fa-receipt"></i>
                                Expenses: <strong>-<?php echo formatCurrency($profit_data['expenses']); ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($profit_data['salaries'] > 0): ?>
                            <span class="pab-item pab-item-neg">
                                <i class="fas fa-users"></i>
                                Salaries: <strong>-<?php echo formatCurrency($profit_data['salaries']); ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($profit_data['cash_out'] > 0): ?>
                            <span class="pab-item pab-item-neg">
                                <i class="fas fa-money-bill-wave"></i>
                                Cash Out: <strong>-<?php echo formatCurrency($profit_data['cash_out']); ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($profit_data['cash_allocated'] > 0): ?>
                            <span class="pab-item pab-item-neg">
                                <i class="fas fa-wallet"></i>
                                Cash Allocated: <strong>-<?php echo formatCurrency($profit_data['cash_allocated']); ?></strong>
                            </span>
                        <?php endif; ?>
                        <?php if ($profit_data['provider_allocated'] > 0): ?>
                            <span class="pab-item pab-item-neg">
                                <i class="fas fa-university"></i>
                                Provider Allocated: <strong>-<?php echo formatCurrency($profit_data['provider_allocated']); ?></strong>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Remaining profit -->
                    <div class="pab-remaining">
                        <span class="pab-remaining-label">
                            <i class="fas fa-calculator"></i>
                            Remaining after input:
                        </span>
                        <span class="pab-remaining-value" id="pabRemaining"><?php echo formatCurrency($profit_data['profit']); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- SUMMARY CARDS -->
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
                <input type="hidden" name="profit_pool" id="profitPoolHidden" value="<?php echo floatval($profit_data['profit']); ?>">
                
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
                
                <!-- TRANSACTION TYPE -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-tag"></i> Transaction Type</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <div class="type-options-grid">
                        <?php 
                        $default_type = ($profit_data['profit'] > 0) ? 'profit_allocation' : 'additional';
                        
                        foreach ($type_labels as $key => $label): 
                            $is_disabled = in_array($key, ['cash_out', 'adjustment']) && $current_capital <= 0;
                            
                            if ($key === 'profit_allocation' && $profit_data['profit'] <= 0) {
                                $is_disabled = true;
                            }
                            
                            $gradient = $label['gradient'] ?? 'linear-gradient(135deg, #059669, #047857)';
                        ?>
                            <label class="type-option-card type-option-<?php echo $label['color']; ?> <?php echo $is_disabled ? 'type-option-disabled' : ''; ?>">
                                <input type="radio" 
                                       name="transaction_type" 
                                       value="<?php echo $key; ?>" 
                                       <?php echo $key == $default_type ? 'checked' : ''; ?>
                                       <?php echo $is_disabled ? 'disabled' : ''; ?>
                                       onchange="onTypeChange('<?php echo $key; ?>')">
                                <div class="toc-content">
                                    <div class="toc-icon" style="background: <?php echo $gradient; ?>;">
                                        <i class="fas <?php echo $label['icon']; ?>"></i>
                                    </div>
                                    <div class="toc-text">
                                        <span class="toc-title"><?php echo $label['label']; ?></span>
                                        <span class="toc-desc">
                                            <?php if ($key === 'profit_allocation' && $profit_data['profit'] > 0): ?>
                                                <span style="color: #7C3AED; font-weight: 700;">
                                                    <i class="fas fa-coins"></i> <?php echo formatCurrency($profit_data['profit']); ?>
                                                </span>
                                            <?php elseif ($is_disabled): ?>
                                                <span style="color: #DC2626; font-weight: 700;">
                                                    <i class="fas fa-lock"></i> 
                                                    <?php echo $key === 'profit_allocation' ? 'No profit' : 'No capital'; ?>
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
                
                <!-- CASH SECTION -->
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
                                Leave empty if you don't want to change cash
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- PROVIDERS SECTION -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Floats</h3>
                        <span class="section-badge">
                            Optional • <?php echo count($all_providers); ?> providers
                        </span>
                    </div>
                    
                    <?php if (count($all_providers) > 0): ?>
                        <div class="providers-amount-grid">
                            <?php foreach ($all_providers as $p): ?>
                                <div class="provider-amount-card provider-amount-green"
                                     data-provider-id="<?php echo $p['id']; ?>">
                                    
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
                                    
                                    <?php if ($p['provider_commission'] > 0): ?>
                                        <div class="pac-commission">
                                            <i class="fas fa-hand-holding-usd"></i>
                                            <span class="pac-commission-label">Commission:</span>
                                            <span class="pac-commission-value"><?php echo formatCurrency($p['provider_commission']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- ✅ "Available to add" inaonyeshwa tu kwa profit_allocation -->
                                    <?php if ($profit_data['profit'] > 0): ?>
                                        <div class="pac-available" 
                                             data-provider-id="<?php echo $p['id']; ?>"
                                             style="<?php echo ($default_type === 'profit_allocation') ? '' : 'display:none;'; ?>">
                                            <span class="pac-available-label">
                                                <i class="fas fa-chart-line"></i>
                                                Available to add:
                                            </span>
                                            <span class="pac-available-value" data-provider-id="<?php echo $p['id']; ?>">
                                                <?php echo formatCurrency($profit_data['profit']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="pac-input-wrapper">
                                        <span class="pac-currency">TSh</span>
                                        <input type="text" 
                                               name="provider_amounts[<?php echo $p['id']; ?>]" 
                                               class="pac-input provider-amount-input money-input" 
                                               placeholder="0"
                                               inputmode="numeric"
                                               data-provider-id="<?php echo $p['id']; ?>"
                                               data-available="<?php echo $p['current_float']; ?>"
                                               data-commission="<?php echo $p['provider_commission']; ?>"
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
                
                <!-- PREVIEW -->
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

<!-- VALIDATION MODAL -->
<div class="modal-overlay" id="validationModal" onclick="closeModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header" id="modalHeader">
            <div class="modal-icon" id="modalIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="modal-header-content">
                <h3 class="modal-title" id="modalTitle">Validation Error</h3>
                <p class="modal-subtitle" id="modalSubtitle">Please check the following</p>
            </div>
            <button type="button" class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-message" id="modalMessage">
                Error details will appear here
            </div>
            <div class="modal-details" id="modalDetails" style="display:none;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="modal-btn modal-btn-primary" onclick="closeModal()">
                <i class="fas fa-check"></i>
                <span>OK, I understand</span>
            </button>
        </div>
    </div>
</div>

<!-- CONFIRM MODAL -->
<div class="modal-overlay" id="confirmModal" onclick="closeConfirmModal(event)">
    <div class="modal-box modal-box-confirm" onclick="event.stopPropagation()">
        <div class="modal-header modal-header-confirm">
            <div class="modal-icon modal-icon-confirm">
                <i class="fas fa-question-circle"></i>
            </div>
            <div class="modal-header-content">
                <h3 class="modal-title">Confirm Transaction</h3>
                <p class="modal-subtitle">Please review before proceeding</p>
            </div>
            <button type="button" class="modal-close" onclick="closeConfirmModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="confirm-details" id="confirmDetails"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="modal-btn modal-btn-secondary" onclick="closeConfirmModal()">
                <i class="fas fa-times"></i>
                <span>Cancel</span>
            </button>
            <button type="button" class="modal-btn modal-btn-primary" onclick="confirmSubmit()">
                <i class="fas fa-check"></i>
                <span>Yes, Continue</span>
            </button>
        </div>
    </div>
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
    
    --green-primary: #059669;
    --green-dark: #047857;
    --green-light: #D1FAE5;
    --green-accent: #10B981;
    
    --purple-primary: #7C3AED;
    --purple-dark: #6D28D9;
    --purple-light: #EDE9FE;
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
    
    --green-light: #065F46;
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

.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 12px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 22px;
    color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6;
}

.no-branch-warning {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; background: #FEF3C7;
    border: 2px solid #FDE68A; border-radius: 12px;
    margin-bottom: 16px; color: #92400E;
}
.no-branch-warning i { font-size: 24px; flex-shrink: 0; margin-top: 2px; color: #D97706; }
.no-branch-warning strong { font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px; }
.no-branch-warning p { font-size: 13px; margin: 0; line-height: 1.5; }

.no-daily-report-warning {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 2px solid #60A5FA; border-radius: 12px;
    margin-bottom: 16px; color: #1E40AF;
}
.no-daily-report-warning i { font-size: 24px; flex-shrink: 0; margin-top: 2px; }
.no-daily-report-warning strong { font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px; }
.no-daily-report-warning p { font-size: 13px; margin: 0; line-height: 1.5; }

.profit-available-banner {
    display: flex; align-items: flex-start; gap: 18px;
    padding: 20px 24px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 50%, #5B21B6 100%);
    border-radius: 14px;
    margin-bottom: 20px;
    color: #FFFFFF;
    box-shadow: 0 8px 28px rgba(124, 58, 237, 0.35);
    position: relative; overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.15);
}
.profit-available-banner::before {
    content: '';
    position: absolute; top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.pab-icon {
    width: 64px; height: 64px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    position: relative; z-index: 1;
    backdrop-filter: blur(10px);
}
.pab-content {
    display: flex; flex-direction: column; gap: 10px;
    flex: 1; min-width: 0; position: relative; z-index: 1;
}
.pab-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.pab-value {
    font-size: 34px; font-weight: 900;
    color: #FFFFFF;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    text-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
    line-height: 1.1;
}
.pab-breakdown {
    display: flex; flex-wrap: wrap;
    gap: 10px; margin-top: 4px;
}
.pab-item {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    font-size: 11px; font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.pab-item i { font-size: 10px; opacity: 0.85; }
.pab-item strong {
    color: #FCD34D;
    font-family: 'Courier New', monospace;
}
.pab-item-neg strong { color: #FCA5A5; }

.pab-remaining {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px;
    background: rgba(252, 211, 77, 0.25);
    border-radius: 10px;
    border: 2px solid rgba(252, 211, 77, 0.4);
    margin-top: 8px;
    align-self: flex-start;
}
.pab-remaining-label {
    font-size: 11px; font-weight: 700;
    color: #FCD34D;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex; align-items: center; gap: 5px;
}
.pab-remaining-value {
    font-size: 18px; font-weight: 900;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

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
}
html.dark-mode .smc-label { color: #6EE7B7; }
.smc-value {
    font-size: 22px; font-weight: 900;
    color: #065F46;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word; line-height: 1.15;
}
html.dark-mode .smc-value { color: #D1FAE5; }

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

.selected-branch-locked {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border: 2px solid #10B981;
    border-radius: 12px; flex-wrap: wrap;
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
.bo-check { opacity: 0; color: #059669; font-size: 20px; flex-shrink: 0; }
.branch-option input[type="radio"]:checked + .bo-content .bo-check { opacity: 1; }

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
.toc-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.4);
}
.toc-text { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.toc-title { font-size: 14px; font-weight: 800; color: var(--ca-text); }
.toc-desc { font-size: 11px; color: var(--ca-text-secondary); font-weight: 500; }
.toc-check { 
    opacity: 0; color: #059669; font-size: 22px; 
    transition: all 0.3s ease; transform: scale(0.5);
}
.type-option-card input[type="radio"]:checked + .toc-content .toc-check { 
    opacity: 1; transform: scale(1);
}
.type-option-disabled { opacity: 0.55; cursor: not-allowed; }
.type-option-disabled .toc-content { background: #F3F4F6; border-color: #D1D5DB; }
.type-option-disabled:hover .toc-content { border-color: #D1D5DB; transform: none; box-shadow: none; }
.type-option-disabled .toc-icon { filter: grayscale(0.5); }

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
.cash-input-card.danger {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    box-shadow: 0 8px 32px rgba(220, 38, 38, 0.35);
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

.providers-amount-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
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
    top: -40px; right: -40px;
    width: 120px; height: 120px;
    background: rgba(16, 185, 129, 0.15);
    border-radius: 50%;
    pointer-events: none;
    z-index: 0;
}
html.dark-mode .provider-amount-card {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 50%, #047857 100%);
    border-color: #10B981;
}
.provider-amount-card:hover {
    border-color: #059669;
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(5, 150, 105, 0.25);
}

.pac-header {
    padding: 12px 14px;
    background: rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(10px);
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.2);
    display: flex; align-items: center; gap: 10px;
    position: relative; z-index: 1;
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
}
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
.pac-current {
    padding: 10px 14px;
    background: rgba(255, 255, 255, 0.4);
    display: flex; justify-content: space-between; align-items: center;
    font-size: 11px;
    border-bottom: 1.5px solid rgba(5, 150, 105, 0.15);
    position: relative; z-index: 1;
}
.pac-current-label { 
    color: #047857; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    font-size: 10px;
}
.pac-current-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #059669;
    font-size: 13px;
}

.pac-commission {
    padding: 8px 14px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    display: flex; align-items: center; gap: 6px;
    font-size: 11px;
    border-bottom: 1.5px solid rgba(217, 119, 6, 0.2);
    position: relative; z-index: 1;
}
.pac-commission i { color: #D97706; font-size: 12px; }
.pac-commission-label {
    color: #92400E;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 9px;
}
.pac-commission-value {
    margin-left: auto;
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #B45309;
    font-size: 12px;
}

.pac-available {
    padding: 8px 14px;
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    display: flex; align-items: center; justify-content: space-between;
    gap: 6px;
    font-size: 11px;
    border-bottom: 1.5px solid rgba(124, 58, 237, 0.2);
    position: relative; z-index: 1;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.pac-available-label {
    color: #6D28D9;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-size: 9px;
    display: flex; align-items: center; gap: 4px;
}
.pac-available-label i { color: #7C3AED; font-size: 10px; }
.pac-available-value {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    color: #6D28D9;
    font-size: 13px;
    transition: all 0.3s ease;
}
.pac-available-zero {
    color: #DC2626 !important;
    animation: pulseRed 1.5s ease-in-out infinite;
}
@keyframes pulseRed {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.pac-input-wrapper {
    display: flex; align-items: center;
    padding: 10px 14px;
    background: rgba(255, 255, 255, 0.85);
    position: relative; z-index: 1;
}
.pac-currency {
    font-size: 13px; font-weight: 900;
    color: #059669;
    padding: 0 10px 0 0;
    border-right: 2px solid #D1FAE5;
    margin-right: 10px;
    font-family: 'Courier New', monospace;
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
}
.pac-input::placeholder { color: rgba(5, 150, 105, 0.4); font-weight: 700; }

.empty-providers {
    text-align: center; padding: 30px 20px;
    background: var(--ca-input-bg);
    border-radius: 10px;
    border: 1px dashed var(--ca-border);
    color: var(--ca-text-secondary);
}
.empty-providers i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

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
.preview-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(5, 150, 105, 0.2);
}
.preview-item.preview-highlight {
    background: linear-gradient(135deg, #059669, #047857);
    border-color: #059669;
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.4);
}
.pi-label {
    font-size: 10px; font-weight: 800;
    color: #065F46;
    text-transform: uppercase; letter-spacing: 1.2px;
}
.preview-highlight .pi-label { color: rgba(255, 255, 255, 0.9); }
.pi-value {
    font-size: 18px; font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-word; line-height: 1.2;
    color: #065F46;
}
.pi-amount { color: #059669; }
.preview-highlight .pi-value { 
    color: #FFFFFF; 
    font-size: 22px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

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

.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(8px);
    z-index: 99999;
    justify-content: center;
    align-items: center;
    padding: 20px;
    overflow-y: auto;
}
.modal-overlay.show {
    display: flex;
    animation: modalFadeIn 0.25s ease forwards;
}
@keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal-box {
    background: #FFFFFF;
    border-radius: 20px;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4);
    animation: modalSlideUp 0.35s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    overflow: hidden;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}
@keyframes modalSlideUp {
    from { opacity: 0; transform: translateY(40px) scale(0.92); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
html.dark-mode .modal-box { background: #1E293B; }

.modal-header {
    padding: 24px 26px 20px 26px;
    display: flex; align-items: flex-start; gap: 16px;
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    border-bottom: 2px solid #FCA5A5;
}
html.dark-mode .modal-header {
    background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%);
    border-bottom-color: #DC2626;
}
.modal-header-confirm {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border-bottom-color: #93C5FD;
}
.modal-icon {
    width: 56px; height: 56px;
    border-radius: 50%;
    background: #FFFFFF;
    color: #DC2626;
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    flex-shrink: 0;
    border: 3px solid #FCA5A5;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.25);
    animation: iconPulse 1.5s ease-in-out infinite;
}
@keyframes iconPulse {
    0%, 100% { transform: scale(1); box-shadow: 0 4px 16px rgba(220, 38, 38, 0.25); }
    50% { transform: scale(1.08); box-shadow: 0 8px 24px rgba(220, 38, 38, 0.4); }
}
.modal-icon-confirm {
    background: #FFFFFF;
    color: #1D4ED8;
    border-color: #93C5FD;
    animation: none;
}
.modal-header-content { flex: 1; min-width: 0; padding-top: 4px; }
.modal-title {
    font-size: 20px; font-weight: 800;
    color: #991B1B; margin: 0 0 4px 0;
}
html.dark-mode .modal-title { color: #FEE2E2; }
.modal-header-confirm .modal-title { color: #1E40AF; }
.modal-subtitle {
    font-size: 12px; font-weight: 600;
    color: #7F1D1D; margin: 0; opacity: 0.8;
}
html.dark-mode .modal-subtitle { color: #FCA5A5; }
.modal-header-confirm .modal-subtitle { color: #1D4ED8; }
.modal-close {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.6);
    border: 1.5px solid rgba(220, 38, 38, 0.3);
    color: #991B1B;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all 0.25s ease;
    flex-shrink: 0;
}
.modal-close:hover {
    background: #991B1B; color: #FFFFFF;
    transform: rotate(90deg) scale(1.05);
}
.modal-body {
    padding: 24px 26px;
    overflow-y: auto;
    flex: 1;
}
.modal-message {
    font-size: 15px; font-weight: 600;
    color: #1F2937;
    line-height: 1.6;
    padding: 16px 18px;
    background: #FEF2F2;
    border-radius: 12px;
    border-left: 4px solid #DC2626;
    margin-bottom: 16px;
}
html.dark-mode .modal-message { background: #1F2937; color: #F1F5F9; }

.modal-details {
    display: flex; flex-direction: column; gap: 10px;
}
.modal-detail-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px;
    background: #F9FAFB;
    border-radius: 10px;
    border: 1.5px solid #E5E7EB;
    font-size: 13px;
}
html.dark-mode .modal-detail-item { background: #334155; border-color: #475569; }
.modal-detail-label {
    font-weight: 700; color: #6B7280;
    display: flex; align-items: center; gap: 6px;
}
.modal-detail-label i { color: #DC2626; font-size: 12px; }
.modal-detail-value {
    font-weight: 900;
    font-family: 'Courier New', monospace;
    color: #1F2937; font-size: 14px;
}
html.dark-mode .modal-detail-value { color: #F1F5F9; }
.modal-detail-value.value-danger { color: #DC2626; }
.modal-detail-value.value-success { color: #059669; }

.confirm-details { display: flex; flex-direction: column; gap: 12px; }
.confirm-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px;
    background: #F9FAFB;
    border-radius: 12px;
    border: 1.5px solid #E5E7EB;
}
html.dark-mode .confirm-row { background: #334155; border-color: #475569; }
.confirm-row-label {
    font-size: 12px; font-weight: 700;
    color: #6B7280;
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 8px;
}
.confirm-row-label i { color: #1D4ED8; font-size: 14px; }
.confirm-row-value {
    font-size: 15px; font-weight: 900;
    color: #1F2937;
    font-family: 'Courier New', monospace;
}
html.dark-mode .confirm-row-value { color: #F1F5F9; }
.confirm-row-value.value-amount { color: #059669; font-size: 18px; }

.modal-footer {
    padding: 18px 26px 22px 26px;
    display: flex; gap: 12px;
    justify-content: flex-end;
    border-top: 1.5px solid #E5E7EB;
    background: #F9FAFB;
    flex-wrap: wrap;
}
html.dark-mode .modal-footer { background: #0F172A; border-top-color: #334155; }

.modal-btn {
    padding: 12px 24px;
    border-radius: 10px;
    border: none;
    font-family: 'Inter', sans-serif;
    font-size: 14px; font-weight: 700;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    white-space: nowrap;
}
.modal-btn-primary {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
}
.modal-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(5, 150, 105, 0.5);
}
.modal-btn-secondary {
    background: #FFFFFF;
    color: #6B7280;
    border: 1.5px solid #D1D5DB;
}
html.dark-mode .modal-btn-secondary {
    background: #334155; color: #CBD5E1;
    border-color: #475569;
}

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
    .profit-available-banner { flex-direction: column; align-items: flex-start; }
    .pab-icon { width: 52px; height: 52px; font-size: 22px; }
    .pab-value { font-size: 24px; }
    .modal-footer { flex-direction: column; }
    .modal-btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .header-left h2 { font-size: 18px; }
    .cic-input { font-size: 20px; }
    .pac-input { font-size: 16px; }
    .smc-value { font-size: 18px; }
    .pab-value { font-size: 20px; }
    .modal-title { font-size: 17px; }
    .modal-icon { width: 48px; height: 48px; font-size: 22px; }
}
</style>

<script>
// ============================================================
// ✅ GLOBAL VARIABLES
// ============================================================
var ORIGINAL_PROFIT_POOL = <?php echo floatval($profit_data['profit']); ?>;
var DEFAULT_TYPE = '<?php echo $default_type; ?>';

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
// ✅ UPDATE PREVIEW + LIVE SHARED POOL
// ✅ CASH + PROVIDERS zote zinahesabiwa kwenye pool
// ============================================================
function updatePreview() {
    // ✅ Read cash amount
    var cashInput = document.getElementById('cashAmountInput');
    var cashAmount = 0;
    if (cashInput && cashInput.value) {
        cashAmount = parseFloat(cashInput.value.replace(/,/g, '')) || 0;
    }
    
    // ✅ Read all provider amounts
    var totalProviders = 0;
    document.querySelectorAll('.provider-amount-input').forEach(function(input) {
        if (input.value) {
            totalProviders += parseFloat(input.value.replace(/,/g, '')) || 0;
        }
    });
    
    // ✅ Total = Cash + Providers
    var totalAmount = cashAmount + totalProviders;
    
    var type = document.querySelector('input[name="transaction_type"]:checked');
    var typeValue = type ? type.value : '';
    var isOut = type && ['cash_out', 'adjustment'].includes(type.value);
    var isProfitAllocation = (typeValue === 'profit_allocation');
    
    // Direction
    var directionEl = document.getElementById('previewDirection');
    if (directionEl) {
        if (isOut) {
            directionEl.innerHTML = '<span class="direction-badge badge-out"><i class="fas fa-arrow-up"></i> OUTGOING</span>';
        } else {
            directionEl.innerHTML = '<span class="direction-badge badge-in"><i class="fas fa-arrow-down"></i> INCOMING</span>';
        }
    }
    
    // Amount
    var amountEl = document.getElementById('previewAmount');
    if (amountEl) amountEl.textContent = 'TSh ' + totalAmount.toLocaleString('en-US');
    
    // New Capital
    var currentCapital = <?php echo floatval($current_capital); ?>;
    var newCapital = isOut ? Math.max(0, currentCapital - totalAmount) : currentCapital + totalAmount;
    var newCapitalEl = document.getElementById('previewNewCapital');
    if (newCapitalEl) newCapitalEl.textContent = 'TSh ' + newCapital.toLocaleString('en-US');
    
    // Cash card warning
    var cashCard = document.getElementById('cashCard');
    if (cashCard) {
        if (isOut && cashAmount > 0) {
            cashCard.classList.add('danger');
        } else {
            cashCard.classList.remove('danger');
        }
    }
    
    // ============================================================
    // ✅ LIVE SHARED POOL UPDATE — cash + providers zote zinahesabiwa
    // ============================================================
    if (isProfitAllocation && ORIGINAL_PROFIT_POOL > 0) {
        // ✅ Total used = cash + providers
        var remaining = Math.max(0, ORIGINAL_PROFIT_POOL - totalAmount);
        
        // Update banner remaining
        var pabRemaining = document.getElementById('pabRemaining');
        if (pabRemaining) {
            pabRemaining.textContent = 'TSh ' + remaining.toLocaleString('en-US');
            if (remaining <= 0) {
                pabRemaining.style.color = '#FCA5A5';
            } else {
                pabRemaining.style.color = '#FFFFFF';
            }
        }
        
        // ✅ Update ALL providers "Available to Add" kwa value moja
        document.querySelectorAll('.pac-available-value').forEach(function(el) {
            el.textContent = 'TSh ' + remaining.toLocaleString('en-US');
            if (remaining <= 0) {
                el.classList.add('pac-available-zero');
            } else {
                el.classList.remove('pac-available-zero');
            }
        });
    }
}

// ============================================================
// ✅ ON TYPE CHANGE — Hakuna auto-fill yoyote
// ============================================================
function onTypeChange(type) {
    // ✅ Onyesha/ficha "Available to Add" kulingana na type
    var availableBlocks = document.querySelectorAll('.pac-available');
    if (type === 'profit_allocation' && ORIGINAL_PROFIT_POOL > 0) {
        availableBlocks.forEach(function(el) {
            el.style.display = 'flex';
        });
    } else {
        availableBlocks.forEach(function(el) {
            el.style.display = 'none';
        });
    }
    
    // ✅ Reset remaining display
    var pabRemaining = document.getElementById('pabRemaining');
    if (pabRemaining) pabRemaining.style.color = '#FFFFFF';
    document.querySelectorAll('.pac-available-value').forEach(function(el) {
        el.classList.remove('pac-available-zero');
    });
    
    updatePreview();
}

function onBranchChange(branchId) {
    window.location.href = 'add.php?branch=' + branchId;
}

// ============================================================
// ✅ MODAL HELPERS
// ============================================================
function showValidationModal(title, subtitle, message, details) {
    var modal = document.getElementById('validationModal');
    var titleEl = document.getElementById('modalTitle');
    var subtitleEl = document.getElementById('modalSubtitle');
    var messageEl = document.getElementById('modalMessage');
    var detailsEl = document.getElementById('modalDetails');
    
    titleEl.textContent = title || 'Validation Error';
    subtitleEl.textContent = subtitle || 'Please check the following';
    messageEl.textContent = message || 'Error details will appear here';
    
    if (details && details.length > 0) {
        detailsEl.style.display = 'flex';
        detailsEl.innerHTML = '';
        details.forEach(function(item) {
            var div = document.createElement('div');
            div.className = 'modal-detail-item';
            div.innerHTML = 
                '<span class="modal-detail-label"><i class="fas fa-' + (item.icon || 'circle') + '"></i> ' + item.label + '</span>' +
                '<span class="modal-detail-value ' + (item.class || '') + '">' + item.value + '</span>';
            detailsEl.appendChild(div);
        });
    } else {
        detailsEl.style.display = 'none';
    }
    
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('validationModal').classList.remove('show');
    document.body.style.overflow = '';
}

function showConfirmModal(details, onConfirm) {
    var modal = document.getElementById('confirmModal');
    var detailsEl = document.getElementById('confirmDetails');
    
    detailsEl.innerHTML = '';
    details.forEach(function(item) {
        var div = document.createElement('div');
        div.className = 'confirm-row';
        div.innerHTML = 
            '<span class="confirm-row-label"><i class="fas fa-' + (item.icon || 'circle') + '"></i> ' + item.label + '</span>' +
            '<span class="confirm-row-value ' + (item.class || '') + '">' + item.value + '</span>';
        detailsEl.appendChild(div);
    });
    
    window._confirmCallback = onConfirm;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal(event) {
    if (event && event.target !== event.currentTarget) return;
    document.getElementById('confirmModal').classList.remove('show');
    document.body.style.overflow = '';
    window._confirmCallback = null;
}

function confirmSubmit() {
    if (typeof window._confirmCallback === 'function') {
        window._confirmCallback();
    }
    closeConfirmModal();
}

// ============================================================
// ✅ VALIDATE FORM
// ============================================================
function validateForm() {
    var branch = document.querySelector('input[name="branch_id"]:checked');
    var branchHidden = document.querySelector('input[name="branch_id"][type="hidden"]');
    
    if (!branch && !branchHidden) {
        showValidationModal('Branch Required', 'Please select a branch to continue',
            'Capital transactions must be applied to a specific branch. Please select one from the list.', []);
        return false;
    }
    
    var type = document.querySelector('input[name="transaction_type"]:checked');
    if (!type) {
        showValidationModal('Transaction Type Required', 'Please select a type',
            'You must choose a transaction type to proceed.', []);
        return false;
    }
    
    var cashInput = document.getElementById('cashAmountInput');
    var cashAmount = cashInput ? parseFloat(cashInput.value.replace(/,/g, '')) || 0 : 0;
    
    var totalProviders = 0;
    document.querySelectorAll('.provider-amount-input').forEach(function(input) {
        if (input.value) totalProviders += parseFloat(input.value.replace(/,/g, '')) || 0;
    });
    
    if (cashAmount <= 0 && totalProviders <= 0) {
        showValidationModal('Amount Required', 'Please enter an amount',
            'You must enter at least one amount — either in Cash, Provider, or both.', []);
        return false;
    }
    
    var isOut = ['cash_out', 'adjustment'].includes(type.value);
    var totalAmount = cashAmount + totalProviders;
    var currentCapital = <?php echo floatval($current_capital); ?>;
    var currentCash = <?php echo floatval($current_cash); ?>;
    
    // ✅ PROFIT ALLOCATION VALIDATION
    if (type.value === 'profit_allocation') {
        if (totalAmount > ORIGINAL_PROFIT_POOL) {
            showValidationModal(
                'Profit Allocation Exceeds Available',
                'Requested amount is higher than available profit',
                'You are trying to allocate more than the available profit pool. Please reduce the amount.',
                [
                    { icon: 'chart-line', label: 'Available Profit', value: 'TSh ' + ORIGINAL_PROFIT_POOL.toLocaleString('en-US'), class: 'value-success' },
                    { icon: 'calculator', label: 'You Requested', value: 'TSh ' + totalAmount.toLocaleString('en-US'), class: 'value-danger' },
                    { icon: 'exclamation-circle', label: 'Excess', value: 'TSh ' + (totalAmount - ORIGINAL_PROFIT_POOL).toLocaleString('en-US'), class: 'value-danger' }
                ]
            );
            return false;
        }
    }
    
    // ✅ CASH OUT VALIDATION
    if (isOut) {
        if (cashAmount > 0 && currentCash <= 0) {
            showValidationModal('Cannot Perform Cash Out', 'No cash available for this branch',
                'There is no cash available. Please create Opening Capital first or reduce the cash amount.',
                [
                    { icon: 'wallet', label: 'Current Cash', value: 'TSh ' + currentCash.toLocaleString('en-US'), class: 'value-danger' },
                    { icon: 'calculator', label: 'Requested Cash', value: 'TSh ' + cashAmount.toLocaleString('en-US'), class: 'value-danger' }
                ]
            );
            return false;
        }
        
        if (cashAmount > currentCash) {
            showValidationModal('Insufficient Cash', 'Cash amount exceeds available balance',
                'The cash amount you entered is more than what is available in this branch.',
                [
                    { icon: 'wallet', label: 'Available Cash', value: 'TSh ' + currentCash.toLocaleString('en-US'), class: 'value-success' },
                    { icon: 'calculator', label: 'Requested Cash', value: 'TSh ' + cashAmount.toLocaleString('en-US'), class: 'value-danger' },
                    { icon: 'exclamation-circle', label: 'Short By', value: 'TSh ' + (cashAmount - currentCash).toLocaleString('en-US'), class: 'value-danger' }
                ]
            );
            return false;
        }
        
        if (totalAmount > currentCapital) {
            showValidationModal('Exceeds Available Capital', 'Total amount is higher than capital',
                'The total amount you entered is more than the available capital for this branch.',
                [
                    { icon: 'vault', label: 'Available Capital', value: 'TSh ' + currentCapital.toLocaleString('en-US'), class: 'value-success' },
                    { icon: 'calculator', label: 'Total Requested', value: 'TSh ' + totalAmount.toLocaleString('en-US'), class: 'value-danger' },
                    { icon: 'exclamation-circle', label: 'Short By', value: 'TSh ' + (totalAmount - currentCapital).toLocaleString('en-US'), class: 'value-danger' }
                ]
            );
            return false;
        }
    }
    
    var typeLabel = type.parentElement.querySelector('.toc-title').textContent.trim();
    
    var confirmDetails = [
        { icon: 'tag', label: 'Transaction Type', value: typeLabel },
        { icon: 'wallet', label: 'Cash Amount', value: 'TSh ' + cashAmount.toLocaleString('en-US') },
        { icon: 'university', label: 'Providers Total', value: 'TSh ' + totalProviders.toLocaleString('en-US') },
        { icon: 'calculator', label: 'Total Amount', value: 'TSh ' + totalAmount.toLocaleString('en-US'), class: 'value-amount' },
        { icon: 'arrow-' + (isOut ? 'up' : 'down'), label: 'Direction', value: isOut ? 'OUTGOING' : 'INCOMING' }
    ];
    
    showConfirmModal(confirmDetails, function() {
        var submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        document.getElementById('capitalForm').submit();
    });
    
    return false;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAny unsaved data will be lost.');
}

// ============================================================
// ✅ INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // ✅ Trigger initial update (hakuna auto-fill yoyote)
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
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closeConfirmModal();
        }
    });
});
</script>

</body>
</html>