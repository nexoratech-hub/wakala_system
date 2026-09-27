<?php
// ================================================================
// FILE: includes/salary_functions.php
// WAKALA FINANCIAL SYSTEM - SALARY CORE FUNCTIONS
// 
// Auto-generation logic:
//   Tarehe 21-27: Generate UPCOMING salaries
//   Tarehe 28-31: Promote UPCOMING -> WAITING
//                 + Generate WAITING (if not exists)
//
// Invisible to user - inaitwa kwenye KILA page load
//
// Version: 2.0 (Fixed paid_by NULL for auto-generated)
// ================================================================

if (!defined('SALARY_FUNCTIONS_LOADED')) {
    define('SALARY_FUNCTIONS_LOADED', true);
}

// ================================================================
// MAIN: Auto-Generate Salaries (Invisible)
// ================================================================

/**
 * Hii ni function kuu inayoitwa KILA page load.
 * Ina-check tarehe leo na ina-generate salaries inavyotakiwa.
 * 
 * MUHIMU: Ni SAFE ku-call mara nyingi - ina-guard yenyewe.
 * 
 * @param PDO $db Database connection
 * @param bool $force Force generation (ignore date check) - kwa manual
 * @return array Summary ya kile kilichofanyika
 */
function checkAndGenerateSalaries($db, $force = false) {
    // Static guard - isirudi kwenye request moja
    static $already_checked = false;
    if ($already_checked && !$force) {
        return ['checked' => false, 'reason' => 'already_checked'];
    }
    $already_checked = true;
    
    $result = [
        'checked'      => true,
        'today'        => date('Y-m-d'),
        'day'          => (int)date('d'),
        'upcoming_gen' => 0,
        'waiting_gen'  => 0,
        'promoted'     => 0,
        'errors'       => []
    ];
    
    try {
        $today = (int)date('d');
        $current_month = date('Y-m-01'); // First day ya mwezi huu
        
        // --------------------------------------------------------
        // TAREHE 21-27: Generate UPCOMING salary ya mwezi huu
        // --------------------------------------------------------
        if ($force || ($today >= 21 && $today <= 27)) {
            $gen = generateSalariesForMonth($db, $current_month, 'upcoming');
            $result['upcoming_gen'] = $gen;
        }
        
        // --------------------------------------------------------
        // TAREHE 28-31: Promote UPCOMING -> WAITING
        //               + Generate WAITING (kama haipo)
        // --------------------------------------------------------
        if ($force || $today >= 28) {
            $promoted = promoteUpcomingToWaiting($db, $current_month);
            $result['promoted'] = $promoted;
            
            $gen = generateSalariesForMonth($db, $current_month, 'waiting');
            $result['waiting_gen'] = $gen;
        }
        
        // --------------------------------------------------------
        // AUTO-PROMOTE: Kama mwezi umepita na bado kuna upcoming,
        // promote zote zilizobaki kwa mwezi uliopita
        // --------------------------------------------------------
        autoPromoteOldUpcoming($db);
        
    } catch (Exception $e) {
        $result['errors'][] = $e->getMessage();
        error_log("Salary auto-generation error: " . $e->getMessage());
    }
    
    return $result;
}

// ================================================================
// GENERATE: Create salaries kwa mwezi husika
// ================================================================

/**
 * Generate salaries kwa mwezi husika kwa employees wote active.
 * 
 * @param PDO $db
 * @param string $salary_month Format: Y-m-01 (first day of month)
 * @param string $status 'upcoming' au 'waiting'
 * @return int Number of salaries generated
 */
function generateSalariesForMonth($db, $salary_month, $status = 'waiting') {
    // Validate month format
    if (!preg_match('/^\d{4}-\d{2}-01$/', $salary_month)) {
        $salary_month = date('Y-m-01', strtotime($salary_month));
    }
    
    // Validate status
    if (!in_array($status, ['upcoming', 'waiting'])) {
        $status = 'waiting';
    }
    
    // --------------------------------------------------------
    // CHECK: Kama tumeshagenerate mwezi huu
    // --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT id, total_employees 
        FROM salary_generation_log 
        WHERE generation_month = ?
        LIMIT 1
    ");
    $stmt->execute([$salary_month]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($log && $log['total_employees'] > 0) {
        // Tayari imegenerate - check kama kuna employees wapya
        return generateMissingSalaries($db, $salary_month, $status);
    }
    
    // --------------------------------------------------------
    // GET: Employees wote active wenye base_salary > 0
    // --------------------------------------------------------
    $stmt = $db->prepare("
        SELECT 
            e.id,
            e.full_name,
            e.branch,
            e.branch_id,
            e.base_salary,
            COALESCE(e.default_bonus, 0) as default_bonus,
            COALESCE(e.default_allowances, 0) as default_allowances,
            COALESCE(e.default_deductions, 0) as default_deductions,
            COALESCE(e.default_tax, 0) as default_tax,
            COALESCE(e.cash_allocation, 0) as cash_allocation
        FROM employees e
        WHERE e.is_active = 1 
        AND e.employment_status = 'active'
        AND e.base_salary > 0
        ORDER BY e.branch_id, e.full_name
    ");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($employees)) {
        return 0;
    }
    
    // --------------------------------------------------------
    // GENERATE
    // --------------------------------------------------------
    $db->beginTransaction();
    $generated_count = 0;
    $total_amount = 0;
    
    try {
        foreach ($employees as $emp) {
            // Check kama salary ya mwezi huu ipo tayari
            $check = $db->prepare("
                SELECT id FROM employee_salaries 
                WHERE employee_id = ? AND salary_month = ?
                LIMIT 1
            ");
            $check->execute([$emp['id'], $salary_month]);
            if ($check->fetch()) {
                continue; // Already exists
            }
            
            // Calculate amounts
            $base_salary = floatval($emp['base_salary']);
            $bonus = floatval($emp['default_bonus']);
            $allowances = floatval($emp['default_allowances']);
            $deductions = floatval($emp['default_deductions']);
            $tax = floatval($emp['default_tax']);
            
            // Generate salary number
            $salary_number = 'SAL-' 
                           . date('Ymd', strtotime($salary_month)) 
                           . '-' 
                           . str_pad($emp['id'], 5, '0', STR_PAD_LEFT);
            
            // Insert (trigger itahesabu total_gross na net_pay)
            // ✅ FIXED: paid_by = NULL kwa auto-generated (bado haijalipwa)
            $stmt = $db->prepare("
                INSERT INTO employee_salaries 
                (salary_number, employee_id, branch, branch_id, salary_month,
                 base_salary, bonus, allowances, tax, deductions,
                 description, status, is_auto_generated, generated_at, 
                 payment_date, paid_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), 
                        CURDATE(), NULL, NOW())
            ");
            $stmt->execute([
                $salary_number,
                $emp['id'],
                $emp['branch'] ?? 'Main',
                $emp['branch_id'],
                $salary_month,
                $base_salary,
                $bonus,
                $allowances,
                $tax,
                $deductions,
                'Auto-generated salary for ' . date('F Y', strtotime($salary_month)),
                $status
            ]);
            
            // Get net_pay baada ya trigger
            $new_id = $db->lastInsertId();
            $check = $db->prepare("SELECT net_pay FROM employee_salaries WHERE id = ?");
            $check->execute([$new_id]);
            $np = $check->fetch(PDO::FETCH_ASSOC);
            $total_amount += floatval($np['net_pay'] ?? 0);
            
            $generated_count++;
        }
        
        // Log generation
        if ($generated_count > 0) {
            $stmt = $db->prepare("
                INSERT INTO salary_generation_log 
                (generation_month, branch_id, total_employees, total_amount, 
                 generation_type, created_at)
                VALUES (?, NULL, ?, ?, 'auto', NOW())
                ON DUPLICATE KEY UPDATE
                    total_employees = total_employees + VALUES(total_employees),
                    total_amount = total_amount + VALUES(total_amount)
            ");
            $stmt->execute([$salary_month, $generated_count, $total_amount]);
        }
        
        $db->commit();
        
        if ($generated_count > 0) {
            error_log("Salary auto-generated: {$generated_count} employees for {$salary_month} (status: {$status})");
        }
        
        return $generated_count;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("generateSalariesForMonth error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Generate salaries tu kwa employees ambao hawana salary ya mwezi huu
 * (Kwa kesi ya kuongeza employee mpya baada ya generation)
 */
function generateMissingSalaries($db, $salary_month, $status = 'waiting') {
    $stmt = $db->prepare("
        SELECT 
            e.id, e.full_name, e.branch, e.branch_id, e.base_salary,
            COALESCE(e.default_bonus, 0) as default_bonus,
            COALESCE(e.default_allowances, 0) as default_allowances,
            COALESCE(e.default_deductions, 0) as default_deductions,
            COALESCE(e.default_tax, 0) as default_tax
        FROM employees e
        LEFT JOIN employee_salaries es 
            ON es.employee_id = e.id AND es.salary_month = ?
        WHERE e.is_active = 1 
        AND e.employment_status = 'active'
        AND e.base_salary > 0
        AND es.id IS NULL
        ORDER BY e.branch_id, e.full_name
    ");
    $stmt->execute([$salary_month]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($employees)) return 0;
    
    $db->beginTransaction();
    $generated_count = 0;
    
    try {
        foreach ($employees as $emp) {
            $salary_number = 'SAL-' 
                           . date('Ymd', strtotime($salary_month)) 
                           . '-' 
                           . str_pad($emp['id'], 5, '0', STR_PAD_LEFT);
            
            // ✅ FIXED: paid_by = NULL
            $stmt = $db->prepare("
                INSERT INTO employee_salaries 
                (salary_number, employee_id, branch, branch_id, salary_month,
                 base_salary, bonus, allowances, tax, deductions,
                 description, status, is_auto_generated, generated_at, 
                 payment_date, paid_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), 
                        CURDATE(), NULL, NOW())
            ");
            $stmt->execute([
                $salary_number,
                $emp['id'],
                $emp['branch'] ?? 'Main',
                $emp['branch_id'],
                $salary_month,
                floatval($emp['base_salary']),
                floatval($emp['default_bonus']),
                floatval($emp['default_allowances']),
                floatval($emp['default_tax']),
                floatval($emp['default_deductions']),
                'Auto-generated salary for ' . date('F Y', strtotime($salary_month)),
                $status
            ]);
            $generated_count++;
        }
        
        $db->commit();
        return $generated_count;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ================================================================
// PROMOTE: UPCOMING -> WAITING (Tarehe 28)
// ================================================================

/**
 * Promote UPCOMING salaries to WAITING for the given month.
 * 
 * @param PDO $db
 * @param string $salary_month Format: Y-m-01
 * @return int Number of salaries promoted
 */
function promoteUpcomingToWaiting($db, $salary_month) {
    $stmt = $db->prepare("
        UPDATE employee_salaries 
        SET status = 'waiting', updated_at = NOW()
        WHERE salary_month = ? 
        AND status = 'upcoming'
    ");
    $stmt->execute([$salary_month]);
    $promoted = $stmt->rowCount();
    
    if ($promoted > 0) {
        error_log("Promoted {$promoted} salaries from upcoming to waiting for {$salary_month}");
    }
    
    return $promoted;
}

/**
 * Auto-promote ALL old upcoming salaries (from previous months).
 * Inaitwa kila siku ili kuhakikisha hakuna upcoming za mwezi uliopita.
 */
function autoPromoteOldUpcoming($db) {
    $stmt = $db->prepare("
        UPDATE employee_salaries 
        SET status = 'waiting', updated_at = NOW()
        WHERE status = 'upcoming' 
        AND salary_month < ?
    ");
    $stmt->execute([date('Y-m-01')]);
    $promoted = $stmt->rowCount();
    
    if ($promoted > 0) {
        error_log("Auto-promoted {$promoted} old upcoming salaries to waiting");
    }
    
    return $promoted;
}

// ================================================================
// BULK PAYMENT: Pay salaries
// ================================================================

/**
 * Bulk pay salaries - flexible, inaweza kulipa wachache au wote.
 * Kila salary inalipwa independently - kama mmoja inafeli, wengine wanaendelea.
 * 
 * MUHIMU: Inaruhusu kulipa UPCOMING salaries pia (early payment).
 * 
 * @param PDO $db
 * @param array $salary_ids Array ya salary IDs
 * @param int $paid_by Employee ID anayelipa
 * @param string $payment_method 'cash', 'bank_transfer', 'mobile_money', 'cheque'
 * @param string $paid_from 'cash' au 'capital'
 * @return array Summary ya payment
 */
function paySalaries($db, $salary_ids, $paid_by, $payment_method = 'cash', $paid_from = 'cash') {
    if (empty($salary_ids) || !is_array($salary_ids)) {
        return [
            'success' => 0,
            'failed' => 0,
            'total' => 0,
            'errors' => ['No salaries selected']
        ];
    }
    
    // Sanitize
    $salary_ids = array_filter(array_map('intval', $salary_ids), function($id) {
        return $id > 0;
    });
    
    if (empty($salary_ids)) {
        return ['success' => 0, 'failed' => 0, 'total' => 0, 'errors' => []];
    }
    
    $db->beginTransaction();
    
    $success_count = 0;
    $failed_count = 0;
    $total_amount = 0;
    $errors = [];
    $paid_salaries = [];
    
    try {
        foreach ($salary_ids as $salary_id) {
            // Get salary with employee info
            $stmt = $db->prepare("
                SELECT 
                    es.*,
                    e.full_name as employee_name,
                    e.employee_id as employee_code
                FROM employee_salaries es
                LEFT JOIN employees e ON es.employee_id = e.id
                WHERE es.id = ? 
                AND es.status IN ('waiting', 'upcoming')
                LIMIT 1
            ");
            $stmt->execute([$salary_id]);
            $salary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$salary) {
                $failed_count++;
                $errors[] = "Salary ID {$salary_id}: Not found or already paid";
                continue;
            }
            
            // --------------------------------------------------------
            // VALIDATE: Check cash kama inalipwa kutoka cash
            // --------------------------------------------------------
            if ($paid_from === 'cash' && !empty($salary['branch_id'])) {
                $cash_check = $db->prepare("
                    SELECT current_cash 
                    FROM daily_reports 
                    WHERE branch_id = ? 
                    ORDER BY report_date DESC, id DESC 
                    LIMIT 1
                ");
                $cash_check->execute([$salary['branch_id']]);
                $dr = $cash_check->fetch(PDO::FETCH_ASSOC);
                $available_cash = floatval($dr['current_cash'] ?? 0);
                
                if ($available_cash < $salary['net_pay']) {
                    $failed_count++;
                    $errors[] = "{$salary['employee_name']}: Insufficient cash "
                              . "(Available: " . number_format($available_cash) 
                              . ", Needed: " . number_format($salary['net_pay']) . ")";
                    continue;
                }
            }
            
            // --------------------------------------------------------
            // STEP 1: UPDATE salary -> PAID
            // --------------------------------------------------------
            $stmt = $db->prepare("
                UPDATE employee_salaries 
                SET status = 'paid',
                    paid_by = ?,
                    paid_at = NOW(),
                    payment_date = CURDATE(),
                    payment_method = ?,
                    paid_from = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$paid_by, $payment_method, $paid_from, $salary_id]);
            
            // --------------------------------------------------------
            // STEP 2: INSERT expense
            // --------------------------------------------------------
            $expense_number = 'EXP-SAL-' . date('Ymd') 
                            . '-' . str_pad($salary_id, 6, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("
                INSERT INTO expenses 
                (expense_number, employee_id, branch, branch_id, expense_date,
                 expense_name, category, amount, description,
                 is_business_expense, is_salary_related, salary_reference, 
                 created_at, updated_at)
                VALUES (?, ?, ?, ?, CURDATE(), ?, 'Salary', ?, ?, 1, 1, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $expense_number,
                $paid_by,
                $salary['branch'] ?? 'Main',
                $salary['branch_id'],
                'Salary - ' . $salary['employee_name'],
                $salary['net_pay'],
                'Salary for ' . date('F Y', strtotime($salary['salary_month'])),
                $salary_id
            ]);
            
            // --------------------------------------------------------
            // STEP 3: INSERT capital_management (cash_out)
            // --------------------------------------------------------
            if (!empty($salary['branch_id'])) {
                $capital_number = 'CAP-SAL-' . date('Ymd') 
                                . '-' . str_pad($salary_id, 6, '0', STR_PAD_LEFT);
                
                $stmt = $db->prepare("
                    INSERT INTO capital_management 
                    (capital_number, employee_id, branch, branch_id, transaction_date,
                     transaction_type, amount, description, reference_id, 
                     reference_module, notes, created_at, updated_at)
                    VALUES (?, ?, ?, ?, CURDATE(), 'cash_out', ?, ?, ?, 'salary', ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $capital_number,
                    $paid_by,
                    $salary['branch'] ?? 'Main',
                    $salary['branch_id'],
                    $salary['net_pay'],
                    'Salary payment - ' . $salary['employee_name'],
                    $salary_id,
                    'Month: ' . date('F Y', strtotime($salary['salary_month']))
                ]);
            }
            
            // --------------------------------------------------------
            // STEP 4: UPDATE daily_reports
            // --------------------------------------------------------
            if (!empty($salary['branch_id'])) {
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET total_salaries = COALESCE(total_salaries, 0) + ?,
                        current_cash = GREATEST(0, COALESCE(current_cash, 0) - ?),
                        net_profit_after_salaries = COALESCE(net_profit, 0) 
                            - (COALESCE(total_salaries, 0) + ?),
                        current_capital = GREATEST(0, COALESCE(current_capital, 0) - ?),
                        updated_at = NOW()
                    WHERE id = (
                        SELECT id FROM (
                            SELECT id FROM daily_reports 
                            WHERE branch_id = ?
                            ORDER BY report_date DESC, id DESC 
                            LIMIT 1
                        ) as tmp
                    )
                ");
                $stmt->execute([
                    $salary['net_pay'],
                    $salary['net_pay'],
                    $salary['net_pay'],
                    $salary['net_pay'],
                    $salary['branch_id']
                ]);
            }
            
            // --------------------------------------------------------
            // SUCCESS
            // --------------------------------------------------------
            $success_count++;
            $total_amount += floatval($salary['net_pay']);
            $paid_salaries[] = [
                'id' => $salary_id,
                'employee' => $salary['employee_name'],
                'amount' => $salary['net_pay']
            ];
        }
        
        $db->commit();
        
        // Log activity
        if ($success_count > 0 && function_exists('logActivity')) {
            logActivity(
                $paid_by,
                'Pay Salaries',
                'Salaries',
                null,
                '',
                "Paid {$success_count} salaries, total " . formatCurrency($total_amount)
            );
        }
        
        return [
            'success'      => $success_count,
            'failed'       => $failed_count,
            'total'        => $total_amount,
            'errors'       => $errors,
            'paid'         => $paid_salaries
        ];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("paySalaries error: " . $e->getMessage());
        
        return [
            'success' => 0,
            'failed'  => count($salary_ids),
            'total'   => 0,
            'errors'  => ['Database error: ' . $e->getMessage()]
        ];
    }
}

// ================================================================
// QUERIES: Get salary data
// ================================================================

/**
 * Get monthly salary summary kwa admin dashboard.
 * Inarudi months zote zenye salaries, na totals zake.
 * 
 * @param PDO $db
 * @param int $branch_id 0 = all branches
 * @param int $limit
 * @return array
 */
function getMonthlySalarySummary($db, $branch_id = 0, $limit = 24) {
    $sql = "
        SELECT 
            DATE_FORMAT(es.salary_month, '%Y-%m') as month_key,
            es.salary_month as month_date,
            DATE_FORMAT(es.salary_month, '%M %Y') as month_label,
            COUNT(*) as total_employees,
            SUM(es.net_pay) as total_amount,
            
            SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN es.status = 'waiting' THEN 1 ELSE 0 END) as waiting_count,
            SUM(CASE WHEN es.status = 'upcoming' THEN 1 ELSE 0 END) as upcoming_count,
            SUM(CASE WHEN es.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            
            SUM(CASE WHEN es.status = 'paid' THEN es.net_pay ELSE 0 END) as paid_amount,
            SUM(CASE WHEN es.status = 'waiting' THEN es.net_pay ELSE 0 END) as waiting_amount,
            SUM(CASE WHEN es.status = 'upcoming' THEN es.net_pay ELSE 0 END) as upcoming_amount,
            
            MIN(es.created_at) as first_created,
            MAX(es.updated_at) as last_updated
        FROM employee_salaries es
        WHERE 1=1
    ";
    $params = [];
    
    if ($branch_id > 0) {
        $sql .= " AND es.branch_id = ?";
        $params[] = $branch_id;
    }
    
    $sql .= " GROUP BY es.salary_month ORDER BY es.salary_month DESC LIMIT " . intval($limit);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get summary cards data kwa admin dashboard.
 * 
 * @param PDO $db
 * @param int $branch_id
 * @return array
 */
function getSalarySummaryCards($db, $branch_id = 0) {
    $result = [
        'total_paid'      => 0,
        'total_waiting'   => 0,
        'total_upcoming'  => 0,
        'total_employees' => 0,
        'paid_count'      => 0,
        'waiting_count'   => 0,
        'upcoming_count'  => 0,
        'current_month'   => date('Y-m')
    ];
    
    $sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN status = 'paid' THEN net_pay ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN status = 'waiting' THEN net_pay ELSE 0 END), 0) as total_waiting,
            COALESCE(SUM(CASE WHEN status = 'upcoming' THEN net_pay ELSE 0 END), 0) as total_upcoming,
            
            COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) as paid_count,
            COALESCE(SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END), 0) as waiting_count,
            COALESCE(SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END), 0) as upcoming_count
        FROM employee_salaries
        WHERE 1=1
    ";
    $params = [];
    
    if ($branch_id > 0) {
        $sql .= " AND branch_id = ?";
        $params[] = $branch_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        $result['total_paid']     = floatval($row['total_paid']);
        $result['total_waiting']  = floatval($row['total_waiting']);
        $result['total_upcoming'] = floatval($row['total_upcoming']);
        $result['paid_count']     = intval($row['paid_count']);
        $result['waiting_count']  = intval($row['waiting_count']);
        $result['upcoming_count'] = intval($row['upcoming_count']);
        $result['total_employees'] = $result['paid_count'] + $result['waiting_count'] + $result['upcoming_count'];
    }
    
    return $result;
}

/**
 * Get salaries za mwezi husika (kwa view_month.php).
 * 
 * @param PDO $db
 * @param string $salary_month Format: Y-m-01
 * @param int $branch_id 0 = all
 * @return array
 */
function getSalariesByMonth($db, $salary_month, $branch_id = 0) {
    $sql = "
        SELECT 
            es.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            e.position as employee_position,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code,
            pb.full_name as paid_by_name
        FROM employee_salaries es
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN branches b ON es.branch_id = b.id
        LEFT JOIN employees pb ON es.paid_by = pb.id
        WHERE es.salary_month = ?
    ";
    $params = [$salary_month];
    
    if ($branch_id > 0) {
        $sql .= " AND es.branch_id = ?";
        $params[] = $branch_id;
    }
    
    // Order: waiting first, then upcoming, then paid
    $sql .= " ORDER BY 
        CASE es.status 
            WHEN 'waiting' THEN 1 
            WHEN 'upcoming' THEN 2 
            WHEN 'paid' THEN 3 
            ELSE 4 
        END,
        e.full_name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get single salary kwa view/receipt.
 * 
 * @param PDO $db
 * @param int $salary_id
 * @param int $employee_id Optional - kama employee anaona yake tu
 * @return array|null
 */
function getSalaryById($db, $salary_id, $employee_id = null) {
    $sql = "
        SELECT 
            es.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.email as employee_email,
            e.phone as employee_phone,
            e.position as employee_position,
            e.profile_pic as employee_avatar,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code,
            b.location as branch_location,
            pb.full_name as paid_by_name
        FROM employee_salaries es
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN branches b ON es.branch_id = b.id
        LEFT JOIN employees pb ON es.paid_by = pb.id
        WHERE es.id = ?
    ";
    $params = [$salary_id];
    
    if ($employee_id !== null) {
        $sql .= " AND es.employee_id = ?";
        $params[] = $employee_id;
    }
    
    $sql .= " LIMIT 1";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get employee salaries history (kwa employee view).
 * 
 * @param PDO $db
 * @param int $employee_id
 * @param int $limit
 * @return array
 */
function getEmployeeSalaries($db, $employee_id, $limit = 24) {
    $stmt = $db->prepare("
        SELECT 
            es.*,
            e.full_name as employee_name,
            e.employee_id as employee_code
        FROM employee_salaries es
        LEFT JOIN employees e ON es.employee_id = e.id
        WHERE es.employee_id = ?
        ORDER BY es.salary_month DESC
        LIMIT " . intval($limit)
    );
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Delete salary (kwa admin) - reverses impact
 * 
 * @param PDO $db
 * @param int $salary_id
 * @return bool
 */
function deleteSalary($db, $salary_id) {
    try {
        $db->beginTransaction();
        
        // Get salary
        $stmt = $db->prepare("SELECT * FROM employee_salaries WHERE id = ?");
        $stmt->execute([$salary_id]);
        $salary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$salary) {
            $db->rollBack();
            return false;
        }
        
        // Kama ilikuwa PAID, reverse impact
        if ($salary['status'] === 'paid') {
            // Delete expense
            $stmt = $db->prepare("DELETE FROM expenses WHERE salary_reference = ?");
            $stmt->execute([$salary_id]);
            
            // Delete capital_management
            $stmt = $db->prepare("
                DELETE FROM capital_management 
                WHERE reference_id = ? AND reference_module = 'salary'
            ");
            $stmt->execute([$salary_id]);
            
            // Reverse daily_reports impact
            if (!empty($salary['branch_id'])) {
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET total_salaries = GREATEST(0, COALESCE(total_salaries, 0) - ?),
                        current_cash = COALESCE(current_cash, 0) + ?,
                        net_profit_after_salaries = COALESCE(net_profit, 0) 
                            - GREATEST(0, COALESCE(total_salaries, 0) - ?),
                        current_capital = COALESCE(current_capital, 0) + ?,
                        updated_at = NOW()
                    WHERE id = (
                        SELECT id FROM (
                            SELECT id FROM daily_reports 
                            WHERE branch_id = ?
                            ORDER BY report_date DESC, id DESC 
                            LIMIT 1
                        ) as tmp
                    )
                ");
                $stmt->execute([
                    $salary['net_pay'],
                    $salary['net_pay'],
                    $salary['net_pay'],
                    $salary['net_pay'],
                    $salary['branch_id']
                ]);
            }
        }
        
        // Delete salary
        $stmt = $db->prepare("DELETE FROM employee_salaries WHERE id = ?");
        $stmt->execute([$salary_id]);
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("deleteSalary error: " . $e->getMessage());
        return false;
    }
}

// ================================================================
// HELPERS
// ================================================================

/**
 * Get status badge CSS class
 */
function getSalaryStatusClass($status) {
    $classes = [
        'paid'      => 'status-paid',
        'waiting'   => 'status-waiting',
        'upcoming'  => 'status-upcoming',
        'cancelled' => 'status-cancelled',
        'reversed'  => 'status-reversed'
    ];
    return $classes[$status] ?? 'status-default';
}

/**
 * Get status label
 */
function getSalaryStatusLabel($status) {
    $labels = [
        'paid'      => 'Paid',
        'waiting'   => 'Waiting',
        'upcoming'  => 'Upcoming',
        'cancelled' => 'Cancelled',
        'reversed'  => 'Reversed'
    ];
    return $labels[$status] ?? ucfirst($status);
}