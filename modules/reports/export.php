<?php
// ================================================================
// FILE: modules/reports/export.php
// WAKALA FINANCIAL SYSTEM - COMPREHENSIVE REPORTS EXPORT
// Export PDF / CSV / Word
// ✅ FIXED: Logo included
// ✅ FIXED: All reports (Expenses, Salaries, Transfers, Transactions, Capital)
// ✅ FIXED: Current Float + Cash included
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

// ============================================================
// PARAMETERS
// ============================================================
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'pdf';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if (!in_array($format, ['pdf', 'csv', 'word'])) {
    $format = 'pdf';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
    $from_date = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $to_date = date('Y-m-d');
}

if (!$is_admin) {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $selected_branch = intval($emp['branch_id'] ?? 0);
}

// ============================================================
// BRANCH INFO
// ============================================================
$branch_name = 'All Branches';
$branch_code = '';
$branch_location = '';

if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$selected_branch]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        $branch_name = $branch['branch_name'];
        $branch_code = $branch['branch_code'] ?? '';
        $branch_location = $branch['location'] ?? '';
    }
}

// ============================================================
// COMPANY SETTINGS
// ============================================================
$company_name = 'Wakala System';
$company_address = '';
$company_phone = '';
$company_email = '';

try {
    $stmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email')
    ");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $company_name = $settings['company_name'] ?? $company_name;
    $company_address = $settings['company_address'] ?? '';
    $company_phone = $settings['company_phone'] ?? '';
    $company_email = $settings['company_email'] ?? '';
} catch (Exception $e) {}

// ============================================================
// LOGO PATH (kwa HTML)
// ============================================================
$logo_path = '../../assets/images/logo.PNG';
if (!file_exists($logo_path)) {
    $logo_path = '../../assets/images/default-logo.png';
}
$logo_exists = file_exists($logo_path);
$logo_full_path = realpath($logo_path);

// ============================================================
// HELPER
// ============================================================
function branchWhere(&$params, $selected_branch, $column = 'branch_id') {
    if ($selected_branch > 0) {
        $params[] = $selected_branch;
        return " AND {$column} = ?";
    }
    return "";
}

$data = [];

// ============================================================
// 1. TRANSACTIONS - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        t.*,
        p.provider_name,
        p.icon_class,
        p.color_code,
        b.branch_name as branch_display_name,
        e.full_name as employee_name
    FROM transactions t
    LEFT JOIN providers p ON t.provider_id = p.id
    LEFT JOIN branches b ON t.branch_id = b.id
    LEFT JOIN employees e ON t.employee_id = e.id
    WHERE t.transaction_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch, 't.branch_id');
$sql .= " ORDER BY t.transaction_date DESC, t.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['transactions_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Transactions summary
$total_deposits = 0;
$total_withdrawals = 0;
$deposit_count = 0;
$withdrawal_count = 0;
foreach ($data['transactions_detailed'] as $t) {
    if ($t['transaction_type'] === 'deposit') {
        $total_deposits += floatval($t['amount']);
        $deposit_count++;
    } else {
        $total_withdrawals += floatval($t['amount']);
        $withdrawal_count++;
    }
}

$data['transactions_summary'] = [
    'total_count' => count($data['transactions_detailed']),
    'deposit_count' => $deposit_count,
    'withdrawal_count' => $withdrawal_count,
    'total_deposits' => $total_deposits,
    'total_withdrawals' => $total_withdrawals,
];

// ============================================================
// 2. TRANSFERS - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        t.*,
        b.branch_name as branch_display_name,
        e.full_name as employee_name
    FROM transfers t
    LEFT JOIN branches b ON t.branch_id = b.id
    LEFT JOIN employees e ON t.employee_id = e.id
    WHERE t.transfer_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch, 't.branch_id');
$sql .= " ORDER BY t.transfer_date DESC, t.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['transfers_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_transfers = 0;
foreach ($data['transfers_detailed'] as $t) {
    $total_transfers += floatval($t['amount']);
}
$data['transfers_summary'] = [
    'total_count' => count($data['transfers_detailed']),
    'total_amount' => $total_transfers,
];

// ============================================================
// 3. EXPENSES - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        ex.*,
        e.full_name as employee_name,
        b.branch_name as branch_display_name
    FROM expenses ex
    LEFT JOIN employees e ON ex.employee_id = e.id
    LEFT JOIN branches b ON ex.branch_id = b.id
    WHERE ex.expense_date BETWEEN ? AND ?
    AND ex.is_business_expense = 1
";
$sql .= branchWhere($params, $selected_branch, 'ex.branch_id');
$sql .= " ORDER BY ex.expense_date DESC, ex.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['expenses_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_expenses = 0;
foreach ($data['expenses_detailed'] as $ex) {
    $total_expenses += floatval($ex['amount']);
}
$data['expenses_summary'] = [
    'total_count' => count($data['expenses_detailed']),
    'total_amount' => $total_expenses,
];

// ============================================================
// 4. SALARIES - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        es.*,
        e.full_name as employee_name,
        e.employee_id as employee_code,
        b.branch_name as branch_display_name,
        pb.full_name as paid_by_name
    FROM employee_salaries es
    LEFT JOIN employees e ON es.employee_id = e.id
    LEFT JOIN branches b ON es.branch_id = b.id
    LEFT JOIN employees pb ON es.paid_by = pb.id
    WHERE es.salary_month BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch, 'es.branch_id');
$sql .= " ORDER BY es.salary_month DESC, es.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['salaries_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_paid = 0;
$total_waiting = 0;
$total_upcoming = 0;
$paid_count = 0;
$waiting_count = 0;
$upcoming_count = 0;

foreach ($data['salaries_detailed'] as $s) {
    if ($s['status'] === 'paid') {
        $total_paid += floatval($s['net_pay']);
        $paid_count++;
    } elseif ($s['status'] === 'waiting') {
        $total_waiting += floatval($s['net_pay']);
        $waiting_count++;
    } elseif ($s['status'] === 'upcoming') {
        $total_upcoming += floatval($s['net_pay']);
        $upcoming_count++;
    }
}

$data['salaries_summary'] = [
    'total_count' => count($data['salaries_detailed']),
    'paid_count' => $paid_count,
    'waiting_count' => $waiting_count,
    'upcoming_count' => $upcoming_count,
    'total_paid' => $total_paid,
    'total_waiting' => $total_waiting,
    'total_upcoming' => $total_upcoming,
    'total_amount' => $total_paid + $total_waiting + $total_upcoming,
];

// ============================================================
// 5. DAILY REPORTS
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        COUNT(*) as total_reports,
        COALESCE(SUM(total_commission), 0) as total_commission,
        COALESCE(SUM(other_income), 0) as total_other_income,
        COALESCE(SUM(net_profit), 0) as total_profit
    FROM daily_reports
    WHERE report_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['daily_reports'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ============================================================
// 6. COMMISSIONS - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        c.*,
        b.branch_name as branch_display_name,
        e.full_name as employee_name
    FROM commissions c
    LEFT JOIN branches b ON c.branch_id = b.id
    LEFT JOIN employees e ON c.employee_id = e.id
    WHERE c.commission_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch, 'c.branch_id');
$sql .= " ORDER BY c.commission_date DESC, c.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['commissions_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_commission = 0;
$total_other_income = 0;
foreach ($data['commissions_detailed'] as $c) {
    $total_commission += floatval($c['total_commission']);
    $total_other_income += floatval($c['other_income']);
}
$data['commissions_summary'] = [
    'total_count' => count($data['commissions_detailed']),
    'total_commission' => $total_commission,
    'total_other_income' => $total_other_income,
];

// ============================================================
// 7. CAPITAL MOVEMENT - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        cm.*,
        b.branch_name as branch_display_name,
        e.full_name as employee_name
    FROM capital_management cm
    LEFT JOIN branches b ON cm.branch_id = b.id
    LEFT JOIN employees e ON cm.employee_id = e.id
    WHERE cm.transaction_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch, 'cm.branch_id');
$sql .= " ORDER BY cm.transaction_date DESC, cm.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['capital_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cap_opening = 0;
$cap_additional = 0;
$cap_profit = 0;
$cap_cashout = 0;
$cap_adjust = 0;

foreach ($data['capital_detailed'] as $cm) {
    switch ($cm['transaction_type']) {
        case 'opening': $cap_opening += floatval($cm['amount']); break;
        case 'additional': $cap_additional += floatval($cm['amount']); break;
        case 'profit_allocation': $cap_profit += floatval($cm['amount']); break;
        case 'cash_out': $cap_cashout += floatval($cm['amount']); break;
        case 'adjustment': $cap_adjust += floatval($cm['amount']); break;
    }
}
$data['capital_summary'] = [
    'total_count' => count($data['capital_detailed']),
    'opening' => $cap_opening,
    'additional' => $cap_additional,
    'profit_alloc' => $cap_profit,
    'cash_out' => $cap_cashout,
    'adjustment' => $cap_adjust,
];

// ============================================================
// 8. STORE CASH OUT - DETAILED
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        sco.*,
        b.branch_name as branch_display_name,
        e.full_name as employee_name
    FROM store_cash_out sco
    LEFT JOIN branches b ON sco.branch_id = b.id
    LEFT JOIN employees e ON sco.employee_id = e.id
    WHERE sco.cashout_date BETWEEN ? AND ?
";
$sql .= branchWhere($params, $selected_branch, 'sco.branch_id');
$sql .= " ORDER BY sco.cashout_date DESC, sco.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['cashout_detailed'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_cashout = 0;
foreach ($data['cashout_detailed'] as $sco) {
    $total_cashout += floatval($sco['amount']);
}
$data['cashout_summary'] = [
    'total_count' => count($data['cashout_detailed']),
    'total_amount' => $total_cashout,
];

// ============================================================
// 9. CURRENT CAPITAL (All Branches sum)
// ============================================================
$current_float = 0;
$current_cash = 0;
$current_capital = 0;

if ($selected_branch > 0) {
    $stmt = $db->prepare("
        SELECT * FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_branch]);
    $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($latest_dr) {
        $current_cash = floatval($latest_dr['current_cash'] ?? 0);
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
        $stmt->execute([$latest_dr['id']]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_float = floatval($f['total_float'] ?? 0);
        $current_capital = $current_float + $current_cash;
    }
} else {
    $stmt = $db->prepare("SELECT id FROM branches WHERE is_active = 1");
    $stmt->execute();
    $active_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($active_branches as $b) {
        $stmt = $db->prepare("
            SELECT * FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$b['id']]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest_dr) {
            $current_cash += floatval($latest_dr['current_cash'] ?? 0);
            
            $stmt2 = $db->prepare("
                SELECT COALESCE(SUM(latest.current_float), 0) as branch_float
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
            $stmt2->execute([$latest_dr['id']]);
            $bf = $stmt2->fetch(PDO::FETCH_ASSOC);
            $current_float += floatval($bf['branch_float'] ?? 0);
        }
    }
    $current_capital = $current_float + $current_cash;
}

// ============================================================
// 10. TOP EMPLOYEES
// ============================================================
$params = [$from_date, $to_date];
$sql = "
    SELECT 
        e.id, e.full_name, e.employee_id as emp_code,
        COUNT(t.id) as txn_count,
        COALESCE(SUM(t.amount), 0) as total_amount
    FROM employees e
    LEFT JOIN transactions t ON t.employee_id = e.id 
        AND t.transaction_date BETWEEN ? AND ?
";
if ($selected_branch > 0) {
    $sql .= " WHERE e.branch_id = ?";
    $params[] = $selected_branch;
} else {
    $sql .= " WHERE 1=1";
}
$sql .= " AND e.is_active = 1 AND e.employment_status = 'active'
    GROUP BY e.id
    HAVING txn_count > 0
    ORDER BY txn_count DESC
    LIMIT 10";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$data['top_employees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// PERIOD LABEL
// ============================================================
$period_label = date('d M Y', strtotime($from_date)) . ' — ' . date('d M Y', strtotime($to_date));
$generated_at = date('d M Y H:i:s');
$filename_base = 'wakala_comprehensive_report_' . date('Y-m-d_His');

// ============================================================
// HELPER: Convert logo to base64 (for Word/PDF HTML)
// ============================================================
function getLogoBase64($path) {
    if (file_exists($path)) {
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        return 'data:image/' . strtolower($type) . ';base64,' . base64_encode($data);
    }
    return '';
}

$logo_base64 = getLogoBase64($logo_path);

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, [$company_name]);
    fputcsv($output, ['COMPREHENSIVE BUSINESS REPORT']);
    fputcsv($output, ['Period:', $period_label]);
    fputcsv($output, ['Branch:', $branch_name . ($branch_code ? " ({$branch_code})" : '')]);
    fputcsv($output, ['Generated:', $generated_at]);
    fputcsv($output, []);
    
    // ============================================================
    // SECTION 1: EXECUTIVE SUMMARY
    // ============================================================
    fputcsv($output, ['========== EXECUTIVE SUMMARY ==========']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Current Capital', number_format($current_capital, 2)]);
    fputcsv($output, ['Current Float', number_format($current_float, 2)]);
    fputcsv($output, ['Current Cash', number_format($current_cash, 2)]);
    fputcsv($output, ['Net Profit', number_format($data['daily_reports']['total_profit'] ?? 0, 2)]);
    fputcsv($output, []);
    
    // ============================================================
    // SECTION 2: TRANSACTIONS DETAILED
    // ============================================================
    if (!empty($data['transactions_detailed'])) {
        fputcsv($output, ['========== TRANSACTIONS ==========']);
        fputcsv($output, ['#', 'Date', 'Transaction #', 'Type', 'Provider', 'Amount', 'Employee', 'Branch', 'Reference']);
        $i = 1;
        foreach ($data['transactions_detailed'] as $t) {
            fputcsv($output, [
                $i++,
                date('Y-m-d', strtotime($t['transaction_date'])),
                $t['transaction_number'],
                ucfirst($t['transaction_type']),
                $t['provider_name'] ?? 'N/A',
                number_format($t['amount'], 2),
                $t['employee_name'] ?? 'N/A',
                $t['branch_display_name'] ?? 'Main',
                $t['reference_number'] ?? '-'
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['TRANSACTIONS SUMMARY']);
        fputcsv($output, ['Total Deposits', number_format($data['transactions_summary']['total_deposits'], 2)]);
        fputcsv($output, ['Total Withdrawals', number_format($data['transactions_summary']['total_withdrawals'], 2)]);
        fputcsv($output, []);
    }
    
    // ============================================================
    // SECTION 3: TRANSFERS DETAILED
    // ============================================================
    if (!empty($data['transfers_detailed'])) {
        fputcsv($output, ['========== TRANSFERS ==========']);
        fputcsv($output, ['#', 'Date', 'Transfer #', 'Type', 'Provider', 'Amount', 'Employee', 'Branch']);
        $i = 1;
        foreach ($data['transfers_detailed'] as $t) {
            $type_label = $t['transfer_type'] === 'cash_to_float' ? 'Cash → Float' : 'Float → Cash';
            fputcsv($output, [
                $i++,
                date('Y-m-d', strtotime($t['transfer_date'])),
                $t['transfer_number'],
                $type_label,
                $t['provider_name'] ?? 'N/A',
                number_format($t['amount'], 2),
                $t['employee_name'] ?? 'N/A',
                $t['branch_display_name'] ?? 'Main'
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['TRANSFERS SUMMARY']);
        fputcsv($output, ['Total Amount', number_format($data['transfers_summary']['total_amount'], 2)]);
        fputcsv($output, []);
    }
    
    // ============================================================
    // SECTION 4: EXPENSES DETAILED
    // ============================================================
    if (!empty($data['expenses_detailed'])) {
        fputcsv($output, ['========== EXPENSES ==========']);
        fputcsv($output, ['#', 'Date', 'Expense #', 'Category', 'Expense Name', 'Amount', 'Employee', 'Branch']);
        $i = 1;
        foreach ($data['expenses_detailed'] as $ex) {
            fputcsv($output, [
                $i++,
                date('Y-m-d', strtotime($ex['expense_date'])),
                $ex['expense_number'],
                $ex['category'],
                $ex['expense_name'],
                number_format($ex['amount'], 2),
                $ex['employee_name'] ?? 'N/A',
                $ex['branch_display_name'] ?? 'Main'
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['EXPENSES SUMMARY']);
        fputcsv($output, ['Total Expenses', number_format($data['expenses_summary']['total_amount'], 2)]);
        fputcsv($output, []);
    }
    
    // ============================================================
    // SECTION 5: SALARIES DETAILED
    // ============================================================
    if (!empty($data['salaries_detailed'])) {
        fputcsv($output, ['========== SALARIES ==========']);
        fputcsv($output, ['#', 'Month', 'Salary #', 'Employee', 'Base', 'Bonus', 'Allowances', 'Deductions', 'Tax', 'Net Pay', 'Status']);
        $i = 1;
        foreach ($data['salaries_detailed'] as $s) {
            fputcsv($output, [
                $i++,
                date('M Y', strtotime($s['salary_month'])),
                $s['salary_number'],
                $s['employee_name'] ?? 'N/A',
                number_format($s['base_salary'], 2),
                number_format($s['bonus'], 2),
                number_format($s['allowances'], 2),
                number_format($s['deductions'], 2),
                number_format($s['tax'], 2),
                number_format($s['net_pay'], 2),
                ucfirst($s['status'])
            ]);
        }
        fputcsv($output, []);
        fputcsv($output, ['SALARIES SUMMARY']);
        fputcsv($output, ['Total Paid', number_format($data['salaries_summary']['total_paid'], 2)]);
        fputcsv($output, ['Total Waiting', number_format($data['salaries_summary']['total_waiting'], 2)]);
        fputcsv($output, ['Total Upcoming', number_format($data['salaries_summary']['total_upcoming'], 2)]);
        fputcsv($output, []);
    }
    
    // ============================================================
    // SECTION 6: CAPITAL MOVEMENT
    // ============================================================
    if (!empty($data['capital_detailed'])) {
        fputcsv($output, ['========== CAPITAL MOVEMENT ==========']);
        fputcsv($output, ['#', 'Date', 'Capital #', 'Type', 'Amount', 'Description', 'Branch']);
        $i = 1;
        foreach ($data['capital_detailed'] as $cm) {
            fputcsv($output, [
                $i++,
                date('Y-m-d', strtotime($cm['transaction_date'])),
                $cm['capital_number'],
                ucfirst(str_replace('_', ' ', $cm['transaction_type'])),
                number_format($cm['amount'], 2),
                $cm['description'] ?? '-',
                $cm['branch_display_name'] ?? 'Main'
            ]);
        }
        fputcsv($output, []);
    }
    
    // ============================================================
    // SECTION 7: STORE CASH OUT
    // ============================================================
    if (!empty($data['cashout_detailed'])) {
        fputcsv($output, ['========== STORE CASH OUT ==========']);
        fputcsv($output, ['#', 'Date', 'Cashout #', 'Amount', 'Reason', 'Taken By', 'Branch']);
        $i = 1;
        foreach ($data['cashout_detailed'] as $sco) {
            fputcsv($output, [
                $i++,
                date('Y-m-d', strtotime($sco['cashout_date'])),
                $sco['cashout_number'],
                number_format($sco['amount'], 2),
                $sco['reason'],
                $sco['taken_by'] ?? 'N/A',
                $sco['branch_display_name'] ?? 'Main'
            ]);
        }
        fputcsv($output, []);
    }
    
    // ============================================================
    // SECTION 8: TOP EMPLOYEES
    // ============================================================
    if (!empty($data['top_employees'])) {
        fputcsv($output, ['========== TOP EMPLOYEES ==========']);
        fputcsv($output, ['Rank', 'Employee', 'Transactions', 'Total Amount']);
        $rank = 1;
        foreach ($data['top_employees'] as $top) {
            fputcsv($output, [
                $rank++,
                $top['full_name'],
                number_format($top['txn_count']),
                number_format($top['total_amount'], 2)
            ]);
        }
    }
    
    fputcsv($output, []);
    fputcsv($output, ['--- End of Report ---']);
    fputcsv($output, ['Generated by ' . $company_name]);
    
    fclose($output);
    exit();
}

// ============================================================
// WORD EXPORT
// ============================================================
if ($format === 'word') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.doc"');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:w="urn:schemas-microsoft-com:office:word"
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <title>Wakala Report</title>
        <style>
            @page { size: A4; margin: 1.5cm; }
            body { font-family: 'Calibri', 'Arial', sans-serif; font-size: 10pt; color: #1F2937; line-height: 1.4; }
            .header { border-bottom: 3px solid #1e40af; padding-bottom: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 16px; }
            .logo { width: 70px; height: 70px; border-radius: 50%; border: 2px solid #1e40af; object-fit: contain; flex-shrink: 0; background: #FFF; padding: 4px; }
            .header-content { flex: 1; }
            .company-name { font-size: 18pt; font-weight: bold; color: #1e40af; margin: 0 0 4px 0; }
            .report-title { font-size: 12pt; font-weight: bold; color: #2563eb; margin: 0 0 6px 0; }
            .meta { font-size: 9pt; color: #6B7280; }
            .meta strong { color: #1F2937; }
            .section-title { background: #1e40af; color: #FFFFFF; padding: 8px 14px; margin: 18px 0 10px 0; font-size: 11pt; font-weight: bold; }
            .data-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 15px; }
            .data-table th { background: #DBEAFE; color: #1e40af; padding: 6px 8px; text-align: left; font-weight: bold; border: 1px solid #93C5FD; font-size: 8.5pt; }
            .data-table td { padding: 5px 8px; border: 1px solid #CBD5E1; color: #1F2937; font-size: 9pt; }
            .data-table tr:nth-child(even) td { background: #F8FAFC; }
            .data-table td.text-right { text-align: right; font-family: 'Courier New', monospace; font-weight: bold; }
            .data-table tr.total-row td { background: #FEF3C7 !important; font-weight: bold; border-top: 2px solid #1e40af; }
            .text-positive { color: #059669; }
            .text-negative { color: #DC2626; }
            .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #CBD5E1; font-size: 8pt; color: #6B7280; text-align: center; }
        </style>
    </head>
    <body>
        
        <!-- HEADER -->
        <div class="header">
            <?php if ($logo_base64): ?>
                <img src="<?php echo $logo_base64; ?>" class="logo" alt="Logo">
            <?php endif; ?>
            <div class="header-content">
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="report-title">📊 COMPREHENSIVE BUSINESS REPORT</div>
                <div class="meta">
                    <strong>Period:</strong> <?php echo $period_label; ?> &nbsp;|&nbsp;
                    <strong>Branch:</strong> <?php echo htmlspecialchars($branch_name); ?>
                    <?php if ($branch_code): ?>(<?php echo htmlspecialchars($branch_code); ?>)<?php endif; ?>
                    <br>
                    <strong>Generated:</strong> <?php echo $generated_at; ?>
                </div>
            </div>
        </div>
        
        <!-- EXECUTIVE SUMMARY -->
        <div class="section-title">📈 EXECUTIVE SUMMARY</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 60%;">Metric</th>
                    <th style="width: 40%;" class="text-right">Value</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><strong>Current Capital</strong></td><td class="text-right"><?php echo formatCurrency($current_capital); ?></td></tr>
                <tr><td><strong>Current Float</strong></td><td class="text-right"><?php echo formatCurrency($current_float); ?></td></tr>
                <tr><td><strong>Current Cash</strong></td><td class="text-right"><?php echo formatCurrency($current_cash); ?></td></tr>
                <tr><td>Net Profit</td><td class="text-right text-positive"><?php echo formatCurrency($data['daily_reports']['total_profit'] ?? 0); ?></td></tr>
            </tbody>
        </table>
        
        <!-- TRANSACTIONS -->
        <?php if (!empty($data['transactions_detailed'])): ?>
        <div class="section-title">💳 TRANSACTIONS (<?php echo count($data['transactions_detailed']); ?> records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 70px;">Date</th>
                    <th>Transaction #</th>
                    <th style="width: 70px;">Type</th>
                    <th>Provider</th>
                    <th style="width: 100px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['transactions_detailed'] as $t): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($t['transaction_number']); ?></td>
                        <td><?php echo ucfirst($t['transaction_type']); ?></td>
                        <td><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></td>
                        <td class="text-right <?php echo $t['transaction_type'] === 'deposit' ? 'text-positive' : 'text-negative'; ?>">
                            <?php echo formatCurrency($t['amount']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL DEPOSITS / WITHDRAWALS</td>
                    <td class="text-right">
                        <span class="text-positive">+<?php echo formatCurrency($data['transactions_summary']['total_deposits']); ?></span><br>
                        <span class="text-negative">-<?php echo formatCurrency($data['transactions_summary']['total_withdrawals']); ?></span>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- TRANSFERS -->
        <?php if (!empty($data['transfers_detailed'])): ?>
        <div class="section-title">🔄 TRANSFERS (<?php echo count($data['transfers_detailed']); ?> records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 70px;">Date</th>
                    <th>Transfer #</th>
                    <th>Type</th>
                    <th>Provider</th>
                    <th style="width: 100px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['transfers_detailed'] as $t): 
                    $type_label = $t['transfer_type'] === 'cash_to_float' ? 'Cash → Float' : 'Float → Cash';
                ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($t['transfer_date'])); ?></td>
                        <td><?php echo htmlspecialchars($t['transfer_number']); ?></td>
                        <td><?php echo $type_label; ?></td>
                        <td><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo formatCurrency($t['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL TRANSFERS</td>
                    <td class="text-right"><?php echo formatCurrency($data['transfers_summary']['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- EXPENSES -->
        <?php if (!empty($data['expenses_detailed'])): ?>
        <div class="section-title">💰 EXPENSES (<?php echo count($data['expenses_detailed']); ?> records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 70px;">Date</th>
                    <th>Expense #</th>
                    <th>Category</th>
                    <th>Expense Name</th>
                    <th style="width: 100px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['expenses_detailed'] as $ex): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($ex['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars($ex['expense_number']); ?></td>
                        <td><?php echo htmlspecialchars($ex['category']); ?></td>
                        <td><?php echo htmlspecialchars($ex['expense_name']); ?></td>
                        <td class="text-right text-negative"><?php echo formatCurrency($ex['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL EXPENSES</td>
                    <td class="text-right text-negative"><?php echo formatCurrency($data['expenses_summary']['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- SALARIES -->
        <?php if (!empty($data['salaries_detailed'])): ?>
        <div class="section-title">👥 SALARIES (<?php echo count($data['salaries_detailed']); ?> records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Month</th>
                    <th>Employee</th>
                    <th style="width: 80px;" class="text-right">Base</th>
                    <th style="width: 80px;" class="text-right">Net Pay</th>
                    <th style="width: 70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['salaries_detailed'] as $s): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('M Y', strtotime($s['salary_month'])); ?></td>
                        <td><?php echo htmlspecialchars($s['employee_name'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo formatCurrency($s['base_salary']); ?></td>
                        <td class="text-right text-positive"><?php echo formatCurrency($s['net_pay']); ?></td>
                        <td><?php echo ucfirst($s['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align:right;">TOTAL SALARIES</td>
                    <td class="text-right">
                        Paid: <?php echo formatCurrency($data['salaries_summary']['total_paid']); ?><br>
                        Waiting: <?php echo formatCurrency($data['salaries_summary']['total_waiting']); ?>
                    </td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- CAPITAL MOVEMENT -->
        <?php if (!empty($data['capital_detailed'])): ?>
        <div class="section-title">🏢 CAPITAL MOVEMENT (<?php echo count($data['capital_detailed']); ?> records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 70px;">Date</th>
                    <th>Capital #</th>
                    <th>Type</th>
                    <th style="width: 100px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['capital_detailed'] as $cm): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($cm['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($cm['capital_number']); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $cm['transaction_type'])); ?></td>
                        <td class="text-right"><?php echo formatCurrency($cm['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- STORE CASH OUT -->
        <?php if (!empty($data['cashout_detailed'])): ?>
        <div class="section-title">💸 STORE CASH OUT (<?php echo count($data['cashout_detailed']); ?> records)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 70px;">Date</th>
                    <th>Cashout #</th>
                    <th>Reason</th>
                    <th style="width: 100px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['cashout_detailed'] as $sco): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($sco['cashout_date'])); ?></td>
                        <td><?php echo htmlspecialchars($sco['cashout_number']); ?></td>
                        <td><?php echo htmlspecialchars($sco['reason']); ?></td>
                        <td class="text-right text-negative"><?php echo formatCurrency($sco['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- TOP EMPLOYEES -->
        <?php if (!empty($data['top_employees'])): ?>
        <div class="section-title">🏆 TOP EMPLOYEES</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Employee</th>
                    <th style="width: 100px;" class="text-right">Transactions</th>
                    <th style="width: 130px;" class="text-right">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 1; foreach ($data['top_employees'] as $top): ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><?php echo htmlspecialchars($top['full_name']); ?></td>
                        <td class="text-right"><?php echo number_format($top['txn_count']); ?></td>
                        <td class="text-right text-positive"><?php echo formatCurrency($top['total_amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- FOOTER -->
        <div class="footer">
            Generated by <strong><?php echo htmlspecialchars($company_name); ?></strong> • <?php echo $generated_at; ?>
        </div>
        
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// PDF EXPORT (HTML for print)
// ============================================================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Wakala Report — <?php echo $period_label; ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: #F3F4F6;
            color: #1F2937;
            margin: 0;
            padding: 24px;
            font-size: 12px;
            line-height: 1.5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #FFFFFF;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
        }
        
        /* HEADER */
        .header {
            border-bottom: 3px solid #1e40af;
            padding-bottom: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .logo-box {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid #1e40af;
            overflow: hidden;
            background: #FFFFFF;
            padding: 6px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);
        }
        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .header-left { flex: 1; min-width: 0; }
        .header-left h1 {
            font-size: 24px;
            font-weight: 900;
            color: #1e40af;
            margin: 0 0 4px 0;
            letter-spacing: -0.3px;
        }
        .header-left .report-title {
            font-size: 14px;
            font-weight: 700;
            color: #2563eb;
            margin: 0 0 10px 0;
        }
        .header-left .meta {
            font-size: 11px;
            color: #6B7280;
            line-height: 1.7;
        }
        .header-left .meta strong { color: #1F2937; }
        .header-right {
            text-align: right;
            flex-shrink: 0;
        }
        .badge {
            display: inline-block;
            padding: 8px 16px;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: #FFFFFF;
            border-radius: 8px;
            font-weight: 800;
            font-size: 11px;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .doc-id {
            font-size: 10px;
            color: #9CA3AF;
            font-family: 'Courier New', monospace;
        }
        
        /* SECTION */
        .section {
            margin-bottom: 24px;
        }
        .section-title {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: #FFFFFF;
            padding: 10px 18px;
            margin: 0 0 12px 0;
            font-size: 13px;
            font-weight: 800;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        
        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 16px;
            border-radius: 8px;
            overflow: hidden;
        }
        thead { background: #DBEAFE; }
        thead th {
            color: #1e40af;
            padding: 10px 12px;
            text-align: left;
            font-weight: 800;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 2px solid #93C5FD;
            white-space: nowrap;
        }
        thead th.text-right { text-align: right; }
        tbody td {
            padding: 9px 12px;
            border-bottom: 1px solid #E5E7EB;
            color: #1F2937;
            vertical-align: middle;
        }
        tbody tr:nth-child(even) td { background: #F8FAFC; }
        tbody tr:hover td { background: #EFF6FF; }
        tbody td.text-right {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 800;
            font-size: 11px;
            white-space: nowrap;
        }
        tbody tr.total-row td {
            background: #FEF3C7 !important;
            font-weight: 900;
            color: #78350F;
            border-top: 2px solid #1e40af;
            border-bottom: none;
            padding: 12px;
        }
        .text-positive { color: #059669; }
        .text-negative { color: #DC2626; }
        
        /* KPI GRID */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .kpi-card {
            padding: 16px;
            border-radius: 10px;
            border: 2px solid;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .kpi-card.blue { background: #EFF6FF; border-color: #93C5FD; }
        .kpi-card.green { background: #ECFDF5; border-color: #6EE7B7; }
        .kpi-card.orange { background: #FFFBEB; border-color: #FCD34D; }
        .kpi-card.purple { background: #F5F3FF; border-color: #C4B5FD; }
        .kpi-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #6B7280;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: 900;
            font-family: 'Courier New', monospace;
            letter-spacing: -0.5px;
        }
        .kpi-card.blue .kpi-value { color: #1D4ED8; }
        .kpi-card.green .kpi-value { color: #047857; }
        .kpi-card.orange .kpi-value { color: #B45309; }
        .kpi-card.purple .kpi-value { color: #6D28D9; }
        
        /* FOOTER */
        .footer {
            margin-top: 30px;
            padding-top: 16px;
            border-top: 2px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: #9CA3AF;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        /* PRINT */
        .print-btn-container {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #E5E7EB;
        }
        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: #FFFFFF;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            box-shadow: 0 4px 16px rgba(30, 64, 175, 0.4);
            transition: all 0.3s ease;
            font-family: inherit;
        }
        .btn-print:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(30, 64, 175, 0.55);
        }
        
        @media print {
            body { background: #FFFFFF; padding: 0; font-size: 10px; }
            .container { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
            .print-btn-container { display: none !important; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
            .section { break-inside: avoid; }
            .kpi-grid { break-inside: avoid; }
        }
        
        @media (max-width: 768px) {
            body { padding: 12px; }
            .container { padding: 20px; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .header { flex-direction: column; align-items: flex-start; }
            .header-right { text-align: left; }
            table { font-size: 10px; }
            thead th, tbody td { padding: 8px; }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- HEADER -->
    <div class="header">
        <div class="logo-box">
            <?php if ($logo_exists): ?>
                <img src="<?php echo $logo_base64; ?>" alt="Logo">
            <?php else: ?>
                <i style="font-size:32px;color:#1e40af;">🏢</i>
            <?php endif; ?>
        </div>
        <div class="header-left">
            <h1><?php echo htmlspecialchars($company_name); ?></h1>
            <p class="report-title">📊 COMPREHENSIVE BUSINESS REPORT</p>
            <div class="meta">
                <strong>Period:</strong> <?php echo $period_label; ?>
                <br>
                <strong>Branch:</strong> <?php echo htmlspecialchars($branch_name); ?>
                <?php if ($branch_code): ?>(<?php echo htmlspecialchars($branch_code); ?>)<?php endif; ?>
                <?php if ($branch_location): ?> • <?php echo htmlspecialchars($branch_location); ?><?php endif; ?>
                <br>
                <strong>Generated:</strong> <?php echo $generated_at; ?>
            </div>
        </div>
        <div class="header-right">
            <div class="badge">BUSINESS REPORT</div>
            <div class="doc-id">ID: <?php echo strtoupper(substr(md5($filename_base), 0, 8)); ?></div>
        </div>
    </div>
    
    <!-- EXECUTIVE SUMMARY -->
    <div class="kpi-grid">
        <div class="kpi-card blue">
            <span class="kpi-label">Current Capital</span>
            <span class="kpi-value"><?php echo formatCurrency($current_capital); ?></span>
        </div>
        <div class="kpi-card green">
            <span class="kpi-label">Current Float</span>
            <span class="kpi-value"><?php echo formatCurrency($current_float); ?></span>
        </div>
        <div class="kpi-card orange">
            <span class="kpi-label">Current Cash</span>
            <span class="kpi-value"><?php echo formatCurrency($current_cash); ?></span>
        </div>
        <div class="kpi-card purple">
            <span class="kpi-label">Net Profit</span>
            <span class="kpi-value"><?php echo formatCurrency($data['daily_reports']['total_profit'] ?? 0); ?></span>
        </div>
    </div>
    
    <!-- TRANSACTIONS -->
    <?php if (!empty($data['transactions_detailed'])): ?>
    <div class="section">
        <h2 class="section-title">💳 Transactions (<?php echo count($data['transactions_detailed']); ?> records)</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 80px;">Date</th>
                    <th>Transaction #</th>
                    <th style="width: 80px;">Type</th>
                    <th>Provider</th>
                    <th style="width: 120px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['transactions_detailed'] as $t): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($t['transaction_number']); ?></td>
                        <td><?php echo ucfirst($t['transaction_type']); ?></td>
                        <td><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></td>
                        <td class="text-right <?php echo $t['transaction_type'] === 'deposit' ? 'text-positive' : 'text-negative'; ?>">
                            <?php echo formatCurrency($t['amount']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL DEPOSITS</td>
                    <td class="text-right text-positive"><?php echo formatCurrency($data['transactions_summary']['total_deposits']); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL WITHDRAWALS</td>
                    <td class="text-right text-negative"><?php echo formatCurrency($data['transactions_summary']['total_withdrawals']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- TRANSFERS -->
    <?php if (!empty($data['transfers_detailed'])): ?>
    <div class="section">
        <h2 class="section-title">🔄 Transfers (<?php echo count($data['transfers_detailed']); ?> records)</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 80px;">Date</th>
                    <th>Transfer #</th>
                    <th>Type</th>
                    <th>Provider</th>
                    <th style="width: 120px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['transfers_detailed'] as $t): 
                    $type_label = $t['transfer_type'] === 'cash_to_float' ? 'Cash → Float' : 'Float → Cash';
                ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($t['transfer_date'])); ?></td>
                        <td><?php echo htmlspecialchars($t['transfer_number']); ?></td>
                        <td><?php echo $type_label; ?></td>
                        <td><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo formatCurrency($t['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL TRANSFERS</td>
                    <td class="text-right"><?php echo formatCurrency($data['transfers_summary']['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- EXPENSES -->
    <?php if (!empty($data['expenses_detailed'])): ?>
    <div class="section">
        <h2 class="section-title">💰 Expenses (<?php echo count($data['expenses_detailed']); ?> records)</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 80px;">Date</th>
                    <th>Expense #</th>
                    <th>Category</th>
                    <th>Expense Name</th>
                    <th style="width: 120px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['expenses_detailed'] as $ex): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($ex['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars($ex['expense_number']); ?></td>
                        <td><?php echo htmlspecialchars($ex['category']); ?></td>
                        <td><?php echo htmlspecialchars($ex['expense_name']); ?></td>
                        <td class="text-right text-negative"><?php echo formatCurrency($ex['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5" style="text-align:right;">TOTAL EXPENSES</td>
                    <td class="text-right text-negative"><?php echo formatCurrency($data['expenses_summary']['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- SALARIES -->
    <?php if (!empty($data['salaries_detailed'])): ?>
    <div class="section">
        <h2 class="section-title">👥 Salaries (<?php echo count($data['salaries_detailed']); ?> records)</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th>Month</th>
                    <th>Employee</th>
                    <th style="width: 90px;" class="text-right">Base</th>
                    <th style="width: 100px;" class="text-right">Net Pay</th>
                    <th style="width: 80px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['salaries_detailed'] as $s): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('M Y', strtotime($s['salary_month'])); ?></td>
                        <td><?php echo htmlspecialchars($s['employee_name'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo formatCurrency($s['base_salary']); ?></td>
                        <td class="text-right text-positive"><?php echo formatCurrency($s['net_pay']); ?></td>
                        <td><?php echo ucfirst($s['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align:right;">TOTAL PAID</td>
                    <td class="text-right text-positive"><?php echo formatCurrency($data['salaries_summary']['total_paid']); ?></td>
                    <td></td>
                </tr>
                <tr class="total-row">
                    <td colspan="4" style="text-align:right;">TOTAL WAITING</td>
                    <td class="text-right"><?php echo formatCurrency($data['salaries_summary']['total_waiting']); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- CAPITAL MOVEMENT -->
    <?php if (!empty($data['capital_detailed'])): ?>
    <div class="section">
        <h2 class="section-title">🏢 Capital Movement (<?php echo count($data['capital_detailed']); ?> records)</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 80px;">Date</th>
                    <th>Capital #</th>
                    <th>Type</th>
                    <th style="width: 120px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['capital_detailed'] as $cm): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($cm['transaction_date'])); ?></td>
                        <td><?php echo htmlspecialchars($cm['capital_number']); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $cm['transaction_type'])); ?></td>
                        <td class="text-right"><?php echo formatCurrency($cm['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- STORE CASH OUT -->
    <?php if (!empty($data['cashout_detailed'])): ?>
    <div class="section">
        <h2 class="section-title">💸 Store Cash Out (<?php echo count($data['cashout_detailed']); ?> records)</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 80px;">Date</th>
                    <th>Cashout #</th>
                    <th>Reason</th>
                    <th style="width: 120px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($data['cashout_detailed'] as $sco): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($sco['cashout_date'])); ?></td>
                        <td><?php echo htmlspecialchars($sco['cashout_number']); ?></td>
                        <td><?php echo htmlspecialchars($sco['reason']); ?></td>
                        <td class="text-right text-negative"><?php echo formatCurrency($sco['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align:right;">TOTAL CASH OUT</td>
                    <td class="text-right text-negative"><?php echo formatCurrency($data['cashout_summary']['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- TOP EMPLOYEES -->
    <?php if (!empty($data['top_employees'])): ?>
    <div class="section">
        <h2 class="section-title">🏆 Top Employees</h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>Employee</th>
                    <th style="width: 120px;" class="text-right">Transactions</th>
                    <th style="width: 150px;" class="text-right">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $rank = 1; foreach ($data['top_employees'] as $top): ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><?php echo htmlspecialchars($top['full_name']); ?></td>
                        <td class="text-right"><?php echo number_format($top['txn_count']); ?></td>
                        <td class="text-right text-positive"><?php echo formatCurrency($top['total_amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- FOOTER -->
    <div class="footer">
        <div><strong><?php echo htmlspecialchars($company_name); ?></strong> — Comprehensive Business Report</div>
        <div>Generated on <strong><?php echo $generated_at; ?></strong></div>
    </div>
    
    <!-- PRINT BUTTON -->
    <div class="print-btn-container">
        <button class="btn-print" onclick="window.print()">
            🖨️ Print / Save as PDF
        </button>
    </div>
    
</div>

<script>
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});
</script>

</body>
</html>