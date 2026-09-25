<?php
// ================================================================
// FILE: modules/daily_report/edit.php
// EDIT DAILY REPORT (ADMIN)
// ✅ FIXED: Ondoa provider_id na provider_code (hazipo kwenye daily_reports)
// ✅ FIXED: Dark mode inatumia html.dark-mode
// ✅ NEW: Modern design na soft background cards
// ✅ NEW: Inaonyesha providers breakdown kwa marejeo
// ✅ NEW: Validation nzuri
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET REPORT
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT dr.*, 
               e.full_name as employee_name,
               b.branch_name as branch_display_name,
               b.branch_code as branch_display_code,
               mr.report_number as morning_report_number,
               mr.report_date as morning_report_date
        FROM daily_reports dr
        LEFT JOIN employees e ON dr.employee_id = e.id
        LEFT JOIN branches b ON dr.branch_id = b.id
        LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
        WHERE dr.id = ?
    ");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching report: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$report) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// ============================================================
// LOAD DROPDOWN DATA
// ============================================================
try {
    // Branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Morning reports
    $stmt = $db->prepare("
        SELECT id, report_number, report_date, branch_id 
        FROM morning_reports 
        ORDER BY report_date DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $morning_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Evening stocks
    $stmt = $db->prepare("
        SELECT id, stock_number, stock_date 
        FROM evening_stocks 
        ORDER BY stock_date DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $evening_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Commissions
    $stmt = $db->prepare("
        SELECT id, commission_number, commission_date 
        FROM commissions 
        ORDER BY commission_date DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // GET PROVIDERS BREAKDOWN (READ-ONLY REFERENCE)
    // ============================================================
    $stmt = $db->prepare("
        SELECT 
            drp.*,
            p.icon_class,
            p.color_code,
            p.provider_type
        FROM daily_report_providers drp
        LEFT JOIN providers p ON drp.provider_id = p.id
        WHERE drp.daily_report_id = ?
        ORDER BY drp.provider_name
    ");
    $stmt->execute([$id]);
    $providers_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $branches = [];
    $morning_reports = [];
    $evening_stocks = [];
    $commissions = [];
    $providers_breakdown = [];
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $report_date = $_POST['report_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $morning_report_id = !empty($_POST['morning_report_id']) ? intval($_POST['morning_report_id']) : null;
        $evening_stock_id = !empty($_POST['evening_stock_id']) ? intval($_POST['evening_stock_id']) : null;
        $commission_id = !empty($_POST['commission_id']) ? intval($_POST['commission_id']) : null;
        
        // Financial data
        $total_deposits = floatval($_POST['total_deposits'] ?? 0);
        $total_withdrawals = floatval($_POST['total_withdrawals'] ?? 0);
        $total_commission = floatval($_POST['total_commission'] ?? 0);
        $other_income = floatval($_POST['other_income'] ?? 0);
        $total_expenses = floatval($_POST['total_expenses'] ?? 0);
        $total_salaries = floatval($_POST['total_salaries'] ?? 0);
        $total_cash_out = floatval($_POST['total_cash_out'] ?? 0);
        
        // Stock data
        $morning_total = floatval($_POST['morning_total'] ?? 0);
        $evening_total = floatval($_POST['evening_total'] ?? 0);
        $float_difference = $evening_total - $morning_total;
        
        // Capital data
        $opening_capital = floatval($_POST['opening_capital'] ?? 0);
        $additional_capital = floatval($_POST['additional_capital'] ?? 0);
        $profit_allocated = floatval($_POST['profit_allocated'] ?? 0);
        
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation
        if (empty($report_date)) {
            $error = 'Please select report date';
        } elseif ($branch_id <= 0) {
            $error = 'Please select a branch';
        } else {
            // Get branch name
            $branch_name = 'Main';
            $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $branch = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($branch) {
                $branch_name = $branch['branch_name'];
            }
            
            // Calculate totals
            $total_business_income = $total_commission + $other_income;
            $net_profit = $total_business_income - $total_expenses - $total_cash_out;
            $net_profit_after_salaries = $net_profit - $total_salaries;
            $current_capital = $opening_capital + $additional_capital + $profit_allocated;
            
            $sql = "UPDATE daily_reports SET 
                branch = ?,
                branch_id = ?,
                report_date = ?,
                morning_report_id = ?,
                evening_stock_id = ?,
                commission_id = ?,
                morning_total = ?,
                evening_total = ?,
                float_difference = ?,
                total_commission = ?,
                total_deposits = ?,
                total_withdrawals = ?,
                other_income = ?,
                total_business_income = ?,
                total_expenses = ?,
                total_cash_out = ?,
                total_salaries = ?,
                net_profit = ?,
                net_profit_after_salaries = ?,
                opening_capital = ?,
                additional_capital = ?,
                profit_allocated = ?,
                current_capital = ?,
                notes = ?,
                updated_at = NOW()
                WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $branch_name,
                $branch_id > 0 ? $branch_id : null,
                $report_date,
                $morning_report_id,
                $evening_stock_id,
                $commission_id,
                $morning_total,
                $evening_total,
                $float_difference,
                $total_commission,
                $total_deposits,
                $total_withdrawals,
                $other_income,
                $total_business_income,
                $total_expenses,
                $total_cash_out,
                $total_salaries,
                $net_profit,
                $net_profit_after_salaries,
                $opening_capital,
                $additional_capital,
                $profit_allocated,
                $current_capital,
                $notes,
                $id
            ]);
            
            if ($result) {
                logActivity($user_id, 'Edit Daily Report', 'Daily Report', $id, '', 
                    'Updated report: ' . $report['report_number']);
                $success = 'Daily report updated successfully!';
                header('Refresh: 2; URL=view.php?id=' . $id);
            } else {
                $error = 'Failed to update report. Please try again.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error updating daily report: " . $e->getMessage());
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        REPORT HEADER CARD
        ============================================================ -->
        <div class="report-header-card">
            <div class="report-header-left">
                <div class="report-header-icon">
                    <i class="fas fa-edit"></i>
                </div>
                <div class="report-header-info">
                    <span class="report-header-label">Editing Report</span>
                    <h1 class="report-header-number"><?php echo htmlspecialchars($report['report_number']); ?></h1>
                    <div class="report-header-meta">
                        <span class="report-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d M Y', strtotime($report['report_date'])); ?>
                        </span>
                        <span class="report-meta-item">
                            <i class="fas fa-store-alt"></i>
                            <?php echo htmlspecialchars($report['branch_display_name'] ?? 'Main'); ?>
                            <?php if ($report['branch_display_code']): ?>
                                (<?php echo htmlspecialchars($report['branch_display_code']); ?>)
                            <?php endif; ?>
                        </span>
                        <span class="report-meta-item">
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="report-header-right">
                <a href="view.php?id=<?php echo $id; ?>" class="btn-back-header">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Details</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        PROVIDERS BREAKDOWN (READ-ONLY REFERENCE)
        ============================================================ -->
        <?php if (!empty($providers_breakdown)): ?>
        <div class="providers-reference-card">
            <div class="providers-reference-header">
                <h3>
                    <i class="fas fa-university"></i>
                    Providers Breakdown
                    <span class="providers-count"><?php echo count($providers_breakdown); ?> providers</span>
                </h3>
                <span class="readonly-badge">
                    <i class="fas fa-lock"></i> Read-only
                </span>
            </div>
            
            <div class="providers-reference-grid">
                <?php foreach ($providers_breakdown as $p): 
                    $provider_color = $p['color_code'] ?? '#3B82F6';
                    $provider_icon = $p['icon_class'] ?? 'fas fa-university';
                ?>
                    <div class="provider-ref-item">
                        <div class="provider-ref-icon" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                            <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                        </div>
                        <div class="provider-ref-info">
                            <span class="provider-ref-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                            <span class="provider-ref-code"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                        </div>
                        <div class="provider-ref-balance">
                            <span class="provider-ref-label">Float</span>
                            <span class="provider-ref-value"><?php echo formatCurrency($p['current_float']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        EDIT FORM
        ============================================================ -->
        <div class="form-card">
            <form method="POST" action="" class="report-form" id="editForm">
                
                <!-- ============================================================
                BASIC INFORMATION
                ============================================================ -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <h4>Basic Information</h4>
                            <p class="form-section-desc">Report number, date, na branch</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Report Number</label>
                            <div class="form-control-disabled">
                                <i class="fas fa-hashtag"></i>
                                <span><?php echo htmlspecialchars($report['report_number']); ?></span>
                            </div>
                            <small class="form-hint">Report number haiwezi kubadilishwa</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Report Date <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fas fa-calendar-alt input-icon"></i>
                                <input type="date" 
                                       name="report_date" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($report['report_date']); ?>" 
                                       required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch <span class="required">*</span></label>
                            <div class="input-with-icon">
                                <i class="fas fa-store-alt input-icon"></i>
                                <select name="branch_id" class="form-control" required>
                                    <option value="">-- Select Branch --</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" 
                                                <?php echo $report['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($b['branch_name']); ?>
                                            (<?php echo htmlspecialchars($b['branch_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Created By</label>
                            <div class="form-control-disabled">
                                <i class="fas fa-user"></i>
                                <span><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                REFERENCES
                ============================================================ -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-link"></i>
                        </div>
                        <div>
                            <h4>References</h4>
                            <p class="form-section-desc">Morning report, evening stock, commission</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Morning Report</label>
                            <div class="input-with-icon">
                                <i class="fas fa-sun input-icon"></i>
                                <select name="morning_report_id" class="form-control">
                                    <option value="">-- Select Morning Report --</option>
                                    <?php foreach ($morning_reports as $mr): ?>
                                        <option value="<?php echo $mr['id']; ?>" 
                                                <?php echo $report['morning_report_id'] == $mr['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($mr['report_number']); ?> 
                                            - <?php echo date('d M Y', strtotime($mr['report_date'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Evening Stock</label>
                            <div class="input-with-icon">
                                <i class="fas fa-moon input-icon"></i>
                                <select name="evening_stock_id" class="form-control">
                                    <option value="">-- Select Evening Stock --</option>
                                    <?php foreach ($evening_stocks as $es): ?>
                                        <option value="<?php echo $es['id']; ?>" 
                                                <?php echo $report['evening_stock_id'] == $es['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($es['stock_number']); ?> 
                                            - <?php echo date('d M Y', strtotime($es['stock_date'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Commission</label>
                            <div class="input-with-icon">
                                <i class="fas fa-percent input-icon"></i>
                                <select name="commission_id" class="form-control">
                                    <option value="">-- Select Commission --</option>
                                    <?php foreach ($commissions as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" 
                                                <?php echo $report['commission_id'] == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['commission_number']); ?> 
                                            - <?php echo date('d M Y', strtotime($c['commission_date'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                STOCK INFORMATION
                ============================================================ -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-cubes"></i>
                        </div>
                        <div>
                            <h4>Stock Information</h4>
                            <p class="form-section-desc">Morning na evening totals</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Morning Total (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-sun input-icon"></i>
                                <input type="number" 
                                       name="morning_total" 
                                       id="morningTotal"
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['morning_total']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Evening Total (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-moon input-icon"></i>
                                <input type="number" 
                                       name="evening_total" 
                                       id="eveningTotal"
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['evening_total']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Float Difference</label>
                            <div class="form-control-disabled calculated">
                                <i class="fas fa-calculator"></i>
                                <span id="floatDifference"><?php echo number_format($report['float_difference'], 2); ?></span>
                            </div>
                            <small class="form-hint">Automatically calculated (Evening - Morning)</small>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                FINANCIAL INFORMATION
                ============================================================ -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-coins"></i>
                        </div>
                        <div>
                            <h4>Financial Information</h4>
                            <p class="form-section-desc">Deposits, withdrawals, income, expenses</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Deposits (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-arrow-down input-icon deposit-icon"></i>
                                <input type="number" 
                                       name="total_deposits" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['total_deposits']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Total Withdrawals (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-arrow-up input-icon withdrawal-icon"></i>
                                <input type="number" 
                                       name="total_withdrawals" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['total_withdrawals']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Commission (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-percent input-icon"></i>
                                <input type="number" 
                                       name="total_commission" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['total_commission']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Other Income (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-plus-circle input-icon"></i>
                                <input type="number" 
                                       name="other_income" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['other_income']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Expenses (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-receipt input-icon expense-icon"></i>
                                <input type="number" 
                                       name="total_expenses" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['total_expenses']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Total Salaries (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-users input-icon"></i>
                                <input type="number" 
                                       name="total_salaries" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['total_salaries']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Total Cash Out (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-money-bill-wave input-icon expense-icon"></i>
                                <input type="number" 
                                       name="total_cash_out" 
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['total_cash_out']); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                CAPITAL INFORMATION
                ============================================================ -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div>
                            <h4>Capital Information</h4>
                            <p class="form-section-desc">Opening, additional, na current capital</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opening Capital (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-wallet input-icon"></i>
                                <input type="number" 
                                       name="opening_capital" 
                                       id="openingCapital"
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['opening_capital']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Additional Capital (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-plus-circle input-icon"></i>
                                <input type="number" 
                                       name="additional_capital" 
                                       id="additionalCapital"
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['additional_capital']); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Profit Allocated (TSh)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-chart-line input-icon"></i>
                                <input type="number" 
                                       name="profit_allocated" 
                                       id="profitAllocated"
                                       class="form-control money-field" 
                                       placeholder="0.00" 
                                       step="0.01" 
                                       value="<?php echo htmlspecialchars($report['profit_allocated']); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Current Capital</label>
                            <div class="form-control-disabled calculated highlight">
                                <i class="fas fa-calculator"></i>
                                <span id="currentCapital"><?php echo number_format($report['current_capital'], 2); ?></span>
                            </div>
                            <small class="form-hint">Opening + Additional + Profit Allocated</small>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                NOTES
                ============================================================ -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-icon">
                            <i class="fas fa-sticky-note"></i>
                        </div>
                        <div>
                            <h4>Additional Notes</h4>
                            <p class="form-section-desc">Maelezo ya ziada kuhusu report</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" 
                                  class="form-control" 
                                  rows="4" 
                                  placeholder="Andika maelezo ya ziada hapa..."><?php echo htmlspecialchars($report['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- ============================================================
                FORM ACTIONS
                ============================================================ -->
                <div class="form-actions">
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Report
                    </button>
                </div>
            </form>
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

/* ============================================================
   REPORT HEADER CARD
   ============================================================ */
.report-header-card {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: 0 6px 24px rgba(187, 4, 4, 0.3);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}

.report-header-card::before {
    content: '';
    position: absolute;
    top: -60%;
    right: -5%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.report-header-left {
    display: flex;
    align-items: center;
    gap: 18px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}

.report-header-icon {
    width: 60px;
    height: 60px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #FFFFFF;
    flex-shrink: 0;
}

.report-header-info {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.report-header-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.75);
}

.report-header-number {
    font-size: 24px;
    font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.5px;
    font-family: 'Inter', 'Courier New', monospace;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-all;
}

.report-header-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.report-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.btn-back-header {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.18);
    color: #FFFFFF;
    border-radius: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    transition: all 0.25s ease;
    position: relative;
    z-index: 1;
    white-space: nowrap;
}

.btn-back-header:hover {
    background: #FFFFFF;
    color: #bb0404;
    transform: translateX(-4px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
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
    font-size: 13px;
    font-weight: 600;
    animation: slideDown 0.4s ease forwards;
    position: relative;
}

.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1.5px solid #A7F3D0;
}

.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FECACA;
}

html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
    transition: opacity 0.2s;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   PROVIDERS REFERENCE CARD
   ============================================================ */
.providers-reference-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.providers-reference-header {
    padding: 14px 20px;
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-bottom: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

html.dark-mode .providers-reference-header {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
}

.providers-reference-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: #1E40AF;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

html.dark-mode .providers-reference-header h3 { color: #BFDBFE; }

.providers-reference-header h3 i { color: #2563EB; font-size: 16px; }

.providers-count {
    background: #2563EB;
    color: #FFFFFF;
    padding: 3px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    margin-left: 4px;
}

.readonly-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(37, 99, 235, 0.15);
    color: #1D4ED8;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}

html.dark-mode .readonly-badge {
    background: rgba(96, 165, 250, 0.2);
    color: #93C5FD;
    border-color: rgba(96, 165, 250, 0.4);
}

.providers-reference-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    padding: 16px 20px;
}

.provider-ref-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
}

.provider-ref-item:hover {
    border-color: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
}

.provider-ref-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
}

.provider-ref-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.provider-ref-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.provider-ref-code {
    font-size: 10px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}

html.dark-mode .provider-ref-code { background: #1E3A5F; color: #60A5FA; }

.provider-ref-balance {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    flex-shrink: 0;
}

.provider-ref-label {
    font-size: 9px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.provider-ref-value {
    font-size: 13px;
    font-weight: 800;
    color: #1D4ED8;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}

html.dark-mode .provider-ref-value { color: #60A5FA; }

/* ============================================================
   FORM CARD
   ============================================================ */
.form-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 0;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* ============================================================
   FORM SECTIONS
   ============================================================ */
.form-section {
    padding: 22px 26px;
    border-bottom: 1.5px solid var(--border-color);
}

.form-section:last-of-type {
    border-bottom: none;
}

.form-section-header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 20px;
}

.form-section-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    color: #bb0404;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid #FCA5A5;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.1);
}

html.dark-mode .form-section-icon {
    background: linear-gradient(135deg, #7F1D1D 0%, #991B1B 100%);
    color: #FCA5A5;
    border-color: #DC2626;
}

.form-section-header h4 {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 2px 0;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.form-section-desc {
    font-size: 12px;
    color: var(--text-muted);
    margin: 0;
    font-weight: 500;
}

/* ============================================================
   FORM ROWS
   ============================================================ */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}

.form-row:last-child {
    margin-bottom: 0;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.form-group label .required { color: #DC2626; }

/* Input with icon */
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
    transition: color 0.25s ease;
}

.input-with-icon:focus-within .input-icon {
    color: #bb0404;
}

.deposit-icon { color: #059669 !important; }
.withdrawal-icon { color: #DC2626 !important; }
.expense-icon { color: #F59E0B !important; }

/* Form control */
.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.25s ease;
    outline: none;
}

.form-control:focus {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187, 4, 4, 0.12);
    background: var(--bg-card);
}

textarea.form-control {
    padding: 12px 14px;
    font-weight: 500;
    resize: vertical;
    min-height: 100px;
    line-height: 1.5;
    font-family: 'Inter', sans-serif;
}

select.form-control {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 14px center;
    background-size: 16px;
    padding-right: 40px;
}

.money-field {
    font-family: 'Inter', 'Courier New', monospace;
    font-weight: 700;
    letter-spacing: 0.3px;
}

/* Disabled / Read-only */
.form-control-disabled {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: var(--bg-table-even);
    border: 1.5px dashed var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-secondary);
    font-family: 'Inter', 'Courier New', monospace;
    min-height: 45px;
}

.form-control-disabled i {
    color: var(--text-muted);
    font-size: 13px;
    flex-shrink: 0;
}

.form-control-disabled.calculated {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border-color: #FCD34D;
    border-style: solid;
    color: #78350F;
}

html.dark-mode .form-control-disabled.calculated {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #F59E0B;
    color: #FCD34D;
}

.form-control-disabled.calculated i { color: #D97706; }
html.dark-mode .form-control-disabled.calculated i { color: #FCD34D; }

.form-control-disabled.highlight {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border-color: #93C5FD;
    color: #1E40AF;
    font-size: 15px;
    font-weight: 800;
}

html.dark-mode .form-control-disabled.highlight {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
    color: #93C5FD;
}

.form-control-disabled.highlight i { color: #2563EB; }
html.dark-mode .form-control-disabled.highlight i { color: #60A5FA; }

.form-hint {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 26px;
    background: var(--bg-table-even);
    border-top: 1.5px solid var(--border-color);
    justify-content: flex-end;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 28px;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.btn-submit {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(187, 4, 4, 0.3);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(187, 4, 4, 0.45);
    color: #FFFFFF;
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-cancel {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.btn-cancel:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
    border-color: #94A3B8;
    transform: translateY(-2px);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .report-header-number { font-size: 20px; }
}

@media (max-width: 768px) {
    .report-header-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 18px 20px;
    }
    
    .report-header-number { font-size: 18px; }
    .report-header-icon { width: 52px; height: 52px; font-size: 22px; }
    .btn-back-header { width: 100%; justify-content: center; }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 14px;
    }
    
    .form-section { padding: 18px 20px; }
    .form-actions { padding: 16px 20px; flex-direction: column-reverse; }
    .form-actions .btn { width: 100%; }
    
    .providers-reference-grid {
        grid-template-columns: 1fr;
        padding: 14px 18px;
    }
}

@media (max-width: 480px) {
    .report-header-number { font-size: 16px; }
    .report-meta-item { font-size: 10px; padding: 3px 9px; }
    .form-section-header h4 { font-size: 13px; }
    .form-control { font-size: 12px; padding: 10px 12px 10px 38px; }
    .form-control-disabled { font-size: 12px; padding: 10px 12px; }
    .btn { font-size: 12px; padding: 10px 20px; }
}
</style>

<script>
// ============================================================
// AUTO CALCULATE FLOAT DIFFERENCE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const morningTotal = document.getElementById('morningTotal');
    const eveningTotal = document.getElementById('eveningTotal');
    const floatDiff = document.getElementById('floatDifference');
    
    function calculateFloatDiff() {
        const morning = parseFloat(morningTotal.value) || 0;
        const evening = parseFloat(eveningTotal.value) || 0;
        const diff = evening - morning;
        
        floatDiff.textContent = diff.toLocaleString('en-US', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        });
    }
    
    if (morningTotal) morningTotal.addEventListener('input', calculateFloatDiff);
    if (eveningTotal) eveningTotal.addEventListener('input', calculateFloatDiff);
    
    // ============================================================
    // AUTO CALCULATE CURRENT CAPITAL
    // ============================================================
    const openingCapital = document.getElementById('openingCapital');
    const additionalCapital = document.getElementById('additionalCapital');
    const profitAllocated = document.getElementById('profitAllocated');
    const currentCapital = document.getElementById('currentCapital');
    
    function calculateCurrentCapital() {
        const opening = parseFloat(openingCapital.value) || 0;
        const additional = parseFloat(additionalCapital.value) || 0;
        const profit = parseFloat(profitAllocated.value) || 0;
        const total = opening + additional + profit;
        
        currentCapital.textContent = total.toLocaleString('en-US', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        });
    }
    
    if (openingCapital) openingCapital.addEventListener('input', calculateCurrentCapital);
    if (additionalCapital) additionalCapital.addEventListener('input', calculateCurrentCapital);
    if (profitAllocated) profitAllocated.addEventListener('input', calculateCurrentCapital);
    
    // ============================================================
    // FORM SUBMIT VALIDATION
    // ============================================================
    const form = document.getElementById('editForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const reportDate = form.querySelector('input[name="report_date"]').value;
            const branchId = form.querySelector('select[name="branch_id"]').value;
            
            if (!reportDate) {
                e.preventDefault();
                alert('Tafadhali chagua report date.');
                return false;
            }
            
            if (!branchId) {
                e.preventDefault();
                alert('Tafadhali chagua branch.');
                return false;
            }
            
            if (!confirm('Una uhakika unataka kuhifadhi mabadiliko haya?')) {
                e.preventDefault();
                return false;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            return true;
        });
    }
    
    // ============================================================
    // AUTO-HIDE SUCCESS ALERT
    // ============================================================
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 5000);
    }
    
    // ============================================================
    // DARK MODE SYNC
    // ============================================================
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