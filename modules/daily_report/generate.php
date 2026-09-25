<?php
// ================================================================
// FILE: modules/daily_report/generate.php
// GENERATE DAILY REPORT
// ✅ FIXED: Modern design na soft background cards
// ✅ FIXED: Dark mode inatumia html.dark-mode
// ✅ FIXED: Consistent styling na files nyingine
// ✅ NEW: Preview cards zenye rangi na icons
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

if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// CHECK IF ADMIN
// ============================================================
$is_admin = ($role === 'admin' || $role === 'super_admin');

$error = '';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

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

try {
    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $branch_name = 'All Branches';
    $branch_code = '';
    if ($selected_branch > 0) {
        foreach ($branches as $b) {
            if ($b['id'] == $selected_branch) {
                $branch_name = $b['branch_name'];
                $branch_code = $b['branch_code'] ?? '';
                break;
            }
        }
    }
    
    // ============================================================
    // CHECK EXISTING REPORT
    // ============================================================
    $sql = "SELECT id FROM daily_reports WHERE report_date = ?";
    $params = [$selected_date];
    
    if ($selected_branch > 0) {
        $sql .= " AND branch_id = ?";
        $params[] = $selected_branch;
    }
    
    if (!$is_admin) {
        $sql .= " AND employee_id = ?";
        $params[] = $user_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $existing_report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ============================================================
    // GET MORNING REPORT
    // ============================================================
    $sql = "SELECT * FROM morning_reports WHERE report_date = ?";
    $params = [$selected_date];
    
    if ($selected_branch > 0) {
        $sql .= " AND branch_id = ?";
        $params[] = $selected_branch;
    }
    
    if (!$is_admin) {
        $sql .= " AND employee_id = ?";
        $params[] = $user_id;
    }
    
    $sql .= " ORDER BY id DESC LIMIT 1";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $morning_report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ============================================================
    // GET ALL PROVIDERS
    // ============================================================
    $providers_data = [];
    if ($morning_report) {
        $stmt = $db->prepare("
            SELECT 
                mrp.provider_id,
                mrp.provider_code,
                mrp.provider_name,
                mrp.float_balance,
                mrp.cash_balance,
                p.icon_class,
                p.color_code
            FROM morning_report_providers mrp
            LEFT JOIN providers p ON mrp.provider_id = p.id
            WHERE mrp.report_id = ?
            ORDER BY mrp.provider_name
        ");
        $stmt->execute([$morning_report['id']]);
        $providers_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $branches = [];
    $existing_report = null;
    $morning_report = null;
    $providers_data = [];
}

// Calculate totals
$morning_float = $morning_report['cumm_total'] ?? 0;
$morning_cash = $morning_report['cash_balance'] ?? 0;
$total_capital = $morning_float + $morning_cash;
$providers_count = count($providers_data);

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate'])) {
    try {
        if ($existing_report) {
            $error = 'A daily report already exists for this date and branch.';
        } elseif (!$morning_report) {
            $error = 'No morning report found for this date. Please submit morning report first.';
        } elseif (empty($providers_data)) {
            $error = 'No providers found in the morning report.';
        } else {
            $report_number = generateNumber('DR');
            
            $db->beginTransaction();
            
            // STEP 1: INSERT INTO daily_reports (SUMMARY)
            $sql = "INSERT INTO daily_reports (
                report_number, employee_id, branch_id, branch, report_date,
                morning_report_id,
                morning_total, evening_total, float_difference,
                total_commission, other_income, total_deposits, total_withdrawals,
                current_float, current_cash, total_business_income,
                total_expenses, total_cash_out, total_salaries, net_profit, net_profit_after_salaries,
                opening_capital, additional_capital, profit_allocated, current_capital
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $report_number,
                $user_id,
                $selected_branch,
                $branch_name,
                $selected_date,
                $morning_report['id'],
                $morning_float,
                0,
                0,
                0,
                0,
                0,
                0,
                $morning_float,
                $morning_cash,
                $morning_float,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                $total_capital
            ]);
            
            $report_id = $db->lastInsertId();
            
            // STEP 2: INSERT INTO daily_report_providers
            $stmt_provider = $db->prepare("
                INSERT INTO daily_report_providers (
                    daily_report_id, provider_id, provider_code, provider_name,
                    morning_float, morning_cash, current_float, current_cash,
                    total_deposits, total_withdrawals
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($providers_data as $pd) {
                $stmt_provider->execute([
                    $report_id,
                    $pd['provider_id'],
                    $pd['provider_code'],
                    $pd['provider_name'],
                    $pd['float_balance'],
                    $pd['cash_balance'],
                    $pd['float_balance'],
                    $pd['cash_balance'],
                    0,
                    0
                ]);
            }
            
            $db->commit();
            
            logActivity($user_id, 'Generate Daily Report', 'Daily Report', $report_id, null, json_encode([
                'report_number' => $report_number,
                'date' => $selected_date,
                'branch' => $branch_name,
                'providers' => $providers_count,
                'float' => $morning_float,
                'cash' => $morning_cash,
                'generated_by' => $user_id
            ]));
            
            $_SESSION['success_message'] = 'Daily report generated successfully! ' . $providers_count . ' providers included.';
            header('Location: view.php?id=' . $report_id);
            exit();
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error: " . $e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH INDICATOR
        ============================================================ -->
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
            </div>
            <div class="branch-indicator-right">
                <?php if ($is_admin): ?>
                    <span class="role-badge role-admin">
                        <i class="fas fa-shield-alt"></i> ADMIN MODE
                    </span>
                <?php else: ?>
                    <span class="role-badge role-employee">
                        <i class="fas fa-user"></i> EMPLOYEE MODE
                    </span>
                <?php endif; ?>
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-magic" style="color:#7C3AED;"></i> Generate Daily Report</h2>
                <p class="text-muted">
                    <?php if ($is_admin): ?>
                        <i class="fas fa-shield-alt"></i> Admin mode - unaona morning reports zote za branch
                    <?php else: ?>
                        <i class="fas fa-user"></i> Employee mode - unaona morning reports zako tu
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-right">
                <a href="index.php?branch_id=<?php echo $selected_branch; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        DATE SELECTOR
        ============================================================ -->
        <div class="selector-card">
            <div class="selector-header">
                <div class="selector-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div>
                    <h4>Select Date</h4>
                    <p>Chagua tarehe ya daily report</p>
                </div>
            </div>
            <form method="GET" action="" class="date-form">
                <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                <div class="form-group">
                    <label>Report Date</label>
                    <div class="input-with-icon">
                        <i class="fas fa-calendar-alt input-icon"></i>
                        <input type="date" name="date" 
                               value="<?php echo htmlspecialchars($selected_date); ?>" 
                               class="form-control" 
                               onchange="this.form.submit()">
                    </div>
                </div>
            </form>
        </div>

        <?php if (!$morning_report): ?>
            <!-- ============================================================
            NO MORNING REPORT
            ============================================================ -->
            <div class="state-card state-card-warning">
                <div class="state-icon state-icon-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3>No Morning Report Found</h3>
                <p>
                    Hakuna morning report kwa <strong><?php echo date('d M Y', strtotime($selected_date)); ?></strong>
                    <?php if ($selected_branch > 0): ?>
                        kwa branch <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                    <?php endif; ?>
                </p>
                <?php if (!$is_admin): ?>
                    <p class="state-note">
                        <i class="fas fa-info-circle"></i>
                        Employee mode: unaona morning reports zako tu
                    </p>
                <?php endif; ?>
                <a href="../morning_report/add.php?date=<?php echo $selected_date; ?>" class="btn-state btn-state-primary">
                    <i class="fas fa-plus"></i> Submit Morning Report
                </a>
            </div>
            
        <?php elseif ($existing_report): ?>
            <!-- ============================================================
            REPORT ALREADY EXISTS
            ============================================================ -->
            <div class="state-card state-card-info">
                <div class="state-icon state-icon-info">
                    <i class="fas fa-info-circle"></i>
                </div>
                <h3>Daily Report Already Exists</h3>
                <p>
                    Daily report kwa tarehe <strong><?php echo date('d M Y', strtotime($selected_date)); ?></strong> 
                    imekwisha kuwa generated.
                    <?php if ($selected_branch > 0): ?>
                        Kwa branch <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                    <?php endif; ?>
                </p>
                <a href="view.php?id=<?php echo $existing_report['id']; ?>" class="btn-state btn-state-info">
                    <i class="fas fa-eye"></i> View Existing Report
                </a>
            </div>
            
        <?php else: ?>
            <!-- ============================================================
            MORNING REPORT FOUND
            ============================================================ -->
            
            <!-- INFO BAR -->
            <div class="info-bar">
                <div class="info-bar-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="info-bar-content">
                    <span class="info-bar-label">Morning Report Found</span>
                    <span class="info-bar-value"><?php echo htmlspecialchars($morning_report['report_number']); ?></span>
                </div>
                <?php if ($is_admin): ?>
                    <span class="info-bar-badge">
                        <i class="fas fa-user"></i> Employee ID: <?php echo htmlspecialchars($morning_report['employee_id']); ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <!-- ============================================================
            PREVIEW STATS - SOFT BACKGROUND
            ============================================================ -->
            <div class="stats-grid-soft">
                <!-- Total Float -->
                <div class="stat-card-soft stat-card-soft-float">
                    <div class="stat-icon-soft">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-info-soft">
                        <span class="stat-label-soft">Total Float</span>
                        <span class="stat-value-soft"><?php echo formatCurrency($morning_float); ?></span>
                        <span class="stat-sub-soft">
                            <i class="fas fa-university"></i>
                            From morning report
                        </span>
                    </div>
                    <div class="stat-decoration-soft"></div>
                </div>
                
                <!-- Cash Balance -->
                <div class="stat-card-soft stat-card-soft-cash">
                    <div class="stat-icon-soft">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info-soft">
                        <span class="stat-label-soft">Cash Balance</span>
                        <span class="stat-value-soft"><?php echo formatCurrency($morning_cash); ?></span>
                        <span class="stat-sub-soft">
                            <i class="fas fa-wallet"></i>
                            Branch cash
                        </span>
                    </div>
                    <div class="stat-decoration-soft"></div>
                </div>
                
                <!-- Total Capital -->
                <div class="stat-card-soft stat-card-soft-capital">
                    <div class="stat-icon-soft">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info-soft">
                        <span class="stat-label-soft">Total Capital</span>
                        <span class="stat-value-soft"><?php echo formatCurrency($total_capital); ?></span>
                        <span class="stat-sub-soft">
                            <i class="fas fa-calculator"></i>
                            Float + Cash
                        </span>
                    </div>
                    <div class="stat-decoration-soft"></div>
                </div>
                
                <!-- Providers Count -->
                <div class="stat-card-soft stat-card-soft-providers">
                    <div class="stat-icon-soft">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="stat-info-soft">
                        <span class="stat-label-soft">Providers</span>
                        <span class="stat-value-soft"><?php echo number_format($providers_count); ?></span>
                        <span class="stat-sub-soft">
                            <i class="fas fa-list"></i>
                            From morning report
                        </span>
                    </div>
                    <div class="stat-decoration-soft"></div>
                </div>
            </div>
            
            <!-- ============================================================
            PROVIDERS PREVIEW
            ============================================================ -->
            <div class="info-card">
                <div class="info-card-header">
                    <div class="info-card-header-left">
                        <div class="info-card-icon">
                            <i class="fas fa-sun"></i>
                        </div>
                        <div>
                            <h3>Providers from Morning Report</h3>
                            <p>Report: <strong><?php echo htmlspecialchars($morning_report['report_number']); ?></strong> 
                               • Date: <?php echo date('d M Y', strtotime($selected_date)); ?></p>
                        </div>
                    </div>
                    <span class="info-badge">
                        <i class="fas fa-university"></i> <?php echo $providers_count; ?> Providers
                    </span>
                </div>
                
                <div class="table-wrapper">
                    <table class="providers-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Provider</th>
                                <th>Code</th>
                                <th class="text-right">Float</th>
                                <th class="text-right">Cash</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $i = 1;
                            $sum_float = 0;
                            $sum_cash = 0;
                            foreach ($providers_data as $pd): 
                                $sum_float += floatval($pd['float_balance']);
                                $sum_cash += floatval($pd['cash_balance']);
                                $provider_color = $pd['color_code'] ?? '#3B82F6';
                                $provider_icon = $pd['icon_class'] ?? 'fas fa-university';
                            ?>
                                <tr>
                                    <td>
                                        <span class="row-number"><?php echo $i++; ?></span>
                                    </td>
                                    <td>
                                        <div class="provider-cell">
                                            <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                                <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                            </div>
                                            <span class="provider-name-text">
                                                <?php echo htmlspecialchars($pd['provider_name']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="code-badge"><?php echo htmlspecialchars($pd['provider_code']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-float"><?php echo formatCurrency($pd['float_balance']); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-cash"><?php echo formatCurrency($pd['cash_balance']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="totals-row">
                                <td colspan="3"><strong>TOTAL</strong></td>
                                <td class="text-right"><strong class="amount-float"><?php echo formatCurrency($morning_float); ?></strong></td>
                                <td class="text-right"><strong class="amount-cash"><?php echo formatCurrency($morning_cash); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- ============================================================
            GENERATE ACTION CARD
            ============================================================ -->
            <div class="generate-action-card">
                <div class="generate-info">
                    <div class="generate-info-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="generate-info-content">
                        <h4>Ready to Generate</h4>
                        <p>
                            Bonyeza <strong>Generate</strong> kwa ku-save providers wote kwenye daily report.
                            <?php if (!$is_admin): ?>
                                <br><small>Jina lako litahifadhiwa kama <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Employee'); ?></strong></small>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <form method="POST" action="" onsubmit="return confirmGenerate();">
                    <button type="submit" name="generate" class="btn-generate-big">
                        <i class="fas fa-magic"></i> 
                        Generate Daily Report
                        <span class="btn-count"><?php echo $providers_count; ?> providers</span>
                    </button>
                </form>
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

/* ============================================================
   BRANCH INDICATOR
   ============================================================ */
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
}
.branch-indicator-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.role-admin {
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border: 1px solid rgba(252, 211, 77, 0.3);
}
.role-employee {
    background: rgba(96, 165, 250, 0.25);
    color: #BFDBFE;
    border: 1px solid rgba(96, 165, 250, 0.3);
}
.date-display {
    font-size: 12px;
    color: rgba(255,255,255,0.9);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
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
    width: 100%;
}
.page-header .header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted {
    font-size: 12px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.header-right { display: flex; gap: 10px; flex-wrap: wrap; }

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.25s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.btn-back:hover {
    background: var(--bg-card);
    color: var(--text-primary);
    border-color: #94A3B8;
    transform: translateY(-2px);
}

/* ============================================================
   ALERTS
   ============================================================ */
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
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; line-height: 1.6; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   SELECTOR CARD
   ============================================================ */
.selector-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 20px 24px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}
.selector-header {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.selector-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 1.5px solid #C4B5FD;
}
html.dark-mode .selector-icon {
    background: linear-gradient(135deg, #2D1B5F, #4C1D95);
    color: #C4B5FD;
    border-color: #A78BFA;
}
.selector-header h4 {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.selector-header p {
    font-size: 12px;
    color: var(--text-muted);
    margin: 2px 0 0 0;
}
.date-form { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; }

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-group label {
    font-size: 11px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
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
    padding: 12px 14px 12px 42px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    min-width: 220px;
    transition: all 0.25s ease;
    outline: none;
}
.form-control:focus {
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
    background: var(--bg-card);
}

/* ============================================================
   INFO BAR
   ============================================================ */
.info-bar {
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border: 1.5px solid #6EE7B7;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
html.dark-mode .info-bar {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%);
    border-color: #10B981;
}
.info-bar-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid rgba(16, 185, 129, 0.3);
}
html.dark-mode .info-bar-icon {
    background: rgba(52, 211, 153, 0.2);
    color: #34D399;
    border-color: rgba(52, 211, 153, 0.4);
}
.info-bar-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}
.info-bar-label {
    font-size: 10px;
    font-weight: 800;
    color: #065F46;
    text-transform: uppercase;
    letter-spacing: 1px;
}
html.dark-mode .info-bar-label { color: #A7F3D0; }
.info-bar-value {
    font-size: 14px;
    font-weight: 900;
    color: #065F46;
    font-family: 'Courier New', monospace;
}
html.dark-mode .info-bar-value { color: #D1FAE5; }
.info-bar-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    border: 1.5px solid rgba(16, 185, 129, 0.3);
}
html.dark-mode .info-bar-badge {
    background: rgba(52, 211, 153, 0.2);
    color: #34D399;
    border-color: rgba(52, 211, 153, 0.4);
}

/* ============================================================
   STATS GRID - SOFT BACKGROUND
   ============================================================ */
.stats-grid-soft {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 16px;
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

/* SOFT BLUE - Float */
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

/* SOFT GREEN - Cash */
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

/* SOFT PURPLE - Capital */
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

/* SOFT ORANGE - Providers */
.stat-card-soft-providers {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
}
.stat-card-soft-providers .stat-icon-soft {
    background: rgba(245, 158, 11, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}
.stat-card-soft-providers .stat-value-soft { color: #B45309; }

/* Dark mode */
html.dark-mode .stat-card-soft-float { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .stat-card-soft-cash { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .stat-card-soft-capital { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .stat-card-soft-providers { background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
html.dark-mode .stat-card-soft-float .stat-value-soft { color: #60A5FA; }
html.dark-mode .stat-card-soft-cash .stat-value-soft { color: #34D399; }
html.dark-mode .stat-card-soft-capital .stat-value-soft { color: #C4B5FD; }
html.dark-mode .stat-card-soft-providers .stat-value-soft { color: #FBBF24; }

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

/* ============================================================
   STATE CARDS
   ============================================================ */
.state-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 50px 24px;
    border: 1.5px solid var(--border-color);
    text-align: center;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.state-icon {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    margin: 0 auto 20px;
    border: 2px solid;
}
.state-icon-warning {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border-color: #FCD34D;
}
.state-icon-info {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #2563EB;
    border-color: #93C5FD;
}
html.dark-mode .state-icon-warning {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FBBF24;
    border-color: #F59E0B;
}
html.dark-mode .state-icon-info {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #60A5FA;
    border-color: #3B82F6;
}

.state-card h3 {
    font-size: 22px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.state-card p {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 16px 0;
    line-height: 1.6;
}
.state-card p strong {
    color: var(--text-primary);
    font-weight: 700;
}
.state-note {
    font-size: 12px !important;
    color: var(--text-light) !important;
    margin-bottom: 20px !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-state {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 800;
    text-decoration: none;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border: none;
    cursor: pointer;
}
.btn-state-primary {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(187, 4, 4, 0.3);
}
.btn-state-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(187, 4, 4, 0.45);
    color: #FFFFFF;
}
.btn-state-info {
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
}
.btn-state-info:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(37, 99, 235, 0.45);
    color: #FFFFFF;
}

/* ============================================================
   INFO CARD (Providers Table)
   ============================================================ */
.info-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.info-card-header {
    padding: 16px 22px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.info-card-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.info-card-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 1.5px solid #FCD34D;
}
html.dark-mode .info-card-icon {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FBBF24;
    border-color: #F59E0B;
}
.info-card-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.info-card-header p {
    font-size: 12px;
    color: var(--text-muted);
    margin: 2px 0 0 0;
}
.info-card-header p strong {
    color: #7C3AED;
    font-family: 'Courier New', monospace;
}
.info-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
    white-space: nowrap;
}
html.dark-mode .info-badge {
    background: rgba(167, 139, 250, 0.2);
    color: #C4B5FD;
    border-color: rgba(167, 139, 250, 0.4);
}

/* TABLE */
.table-wrapper {
    overflow-x: auto;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    max-height: 450px;
    overflow-y: auto;
}
.providers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 700px;
}
.providers-table thead {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    position: sticky;
    top: 0;
    z-index: 10;
}
.providers-table thead th {
    padding: 12px 16px;
    text-align: left;
    color: #FFFFFF;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.providers-table thead th.text-right { text-align: right; }
.providers-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
    vertical-align: middle;
}
.providers-table tbody tr:hover { background: rgba(124, 58, 237, 0.04); }
.providers-table tbody tr:nth-child(even) { background: var(--bg-input); }
.providers-table tbody td.text-right { text-align: right; }

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

.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon-circle {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 14px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.provider-name-text {
    font-weight: 800;
    color: var(--text-primary);
    font-size: 13px;
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
    border: 1.5px solid #93C5FD;
}
html.dark-mode .code-badge { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

.amount-float {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #DBEAFE;
    color: #1D4ED8;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #93C5FD;
    white-space: nowrap;
}
.amount-cash {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    background: #DCFCE7;
    color: #15803D;
    border-radius: 8px;
    font-weight: 800;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #86EFAC;
    white-space: nowrap;
}
html.dark-mode .amount-float { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .amount-cash { background: #14532D; color: #4ADE80; border-color: #16A34A; }

.totals-row {
    background: linear-gradient(135deg, #F8FAFC 0%, #E2E8F0 100%) !important;
    border-top: 3px solid #7C3AED;
}
html.dark-mode .totals-row {
    background: linear-gradient(135deg, #334155 0%, #1e293b 100%) !important;
}
.totals-row td {
    padding: 14px 16px;
    font-weight: 900;
    color: var(--text-primary);
    border-bottom: none;
}

/* ============================================================
   GENERATE ACTION CARD
   ============================================================ */
.generate-action-card {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border: 2px solid #7C3AED;
    border-radius: 14px;
    padding: 22px 26px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: 0 4px 16px rgba(124, 58, 237, 0.15);
}
html.dark-mode .generate-action-card {
    background: linear-gradient(135deg, #2D1B5F 0%, #1E1B4B 100%);
    border-color: #7C3AED;
    box-shadow: 0 4px 16px rgba(124, 58, 237, 0.3);
}
.generate-info {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    min-width: 250px;
}
.generate-info-icon {
    width: 52px;
    height: 52px;
    border-radius: 14px;
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
html.dark-mode .generate-info-icon {
    background: rgba(167, 139, 250, 0.2);
    color: #C4B5FD;
    border-color: rgba(167, 139, 250, 0.4);
}
.generate-info-content {
    flex: 1;
    min-width: 0;
}
.generate-info-content h4 {
    font-size: 14px;
    font-weight: 800;
    color: #5B21B6;
    margin: 0 0 2px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
html.dark-mode .generate-info-content h4 { color: #C4B5FD; }
.generate-info-content p {
    font-size: 12px;
    color: var(--text-muted);
    margin: 0;
    line-height: 1.5;
}
.generate-info-content p strong { color: #7C3AED; }
.generate-info-content p small {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    color: var(--text-light);
}

.btn-generate-big {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 16px 32px;
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: #FFFFFF;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 900;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 14px rgba(124, 58, 237, 0.4);
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
    position: relative;
    overflow: hidden;
}
.btn-generate-big::before {
    content: '';
    position: absolute;
    top: 50%; left: 50%;
    width: 0; height: 0;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.6s ease, height 0.6s ease;
}
.btn-generate-big:hover::before { width: 300px; height: 300px; }
.btn-generate-big:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(124, 58, 237, 0.55);
    background: linear-gradient(135deg, #6D28D9 0%, #5B21B6 100%);
    color: #FFFFFF;
}
.btn-generate-big > * { position: relative; z-index: 1; }
.btn-count {
    background: rgba(255, 255, 255, 0.25);
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    margin-left: 4px;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .stats-grid-soft { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .branch-indicator {
        flex-direction: column;
        align-items: flex-start;
    }
    .branch-indicator-right { width: 100%; }
    
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn-back { width: 100%; justify-content: center; }
    
    .selector-card {
        flex-direction: column;
        align-items: stretch;
    }
    .date-form { width: 100%; }
    .form-control { min-width: 100%; width: 100%; }
    
    .stats-grid-soft { grid-template-columns: 1fr; }
    
    .generate-action-card {
        flex-direction: column;
        text-align: center;
    }
    .generate-info {
        flex-direction: column;
        text-align: center;
        min-width: 100%;
    }
    .generate-action-card form { width: 100%; }
    .btn-generate-big {
        width: 100%;
        justify-content: center;
    }
    
    .state-card h3 { font-size: 18px; }
    .btn-state { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .stat-value-soft { font-size: 16px; }
    .stat-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .info-card-header h3 { font-size: 13px; }
    .info-card-header p { font-size: 11px; }
    .provider-name-text { font-size: 12px; }
    .code-badge { font-size: 9px; padding: 3px 8px; }
    .amount-float, .amount-cash { font-size: 11px; padding: 4px 10px; }
    .btn-generate-big {
        padding: 14px 24px;
        font-size: 12px;
    }
}
</style>

<script>
// ============================================================
// CONFIRM GENERATE
// ============================================================
function confirmGenerate() {
    var providersCount = <?php echo $providers_count; ?>;
    var msg = 'Generate daily report?\n\n' +
              'This will save:\n' +
              '- Summary\n' +
              '- ' + providersCount + ' providers\n\n' +
              'Continue?';
    
    if (!confirm(msg)) {
        return false;
    }
    
    // Disable button
    var btn = document.querySelector('.btn-generate-big');
    if (btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
        btn.disabled = true;
    }
    
    return true;
}

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