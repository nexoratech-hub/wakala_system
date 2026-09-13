<?php
// ================================================================
// FILE: modules/capital_management/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT CAPITAL TRANSACTION
// ✅ FIXED: Proper reversal + apply logic
// ✅ FIXED: Handles Cash ↔ Provider module changes
// ✅ FIXED: Updates daily_reports.current_cash + current_capital correctly
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
// GET TRANSACTION ID
// ============================================================
$transaction_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($transaction_id <= 0) {
    $_SESSION['error_message'] = 'Invalid transaction ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET TRANSACTION DATA
// ============================================================
try {
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
            WHERE cm.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $transaction = null;
}

if (!$transaction) {
    $_SESSION['error_message'] = 'Transaction not found.';
    header('Location: index.php');
    exit();
}

$branch_id = intval($transaction['branch_id']);

// ============================================================
// GET BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// GET PROVIDERS FOR THIS BRANCH
// ============================================================
$stmt = $db->prepare("
    SELECT 
        p.id,
        p.provider_name,
        p.provider_code as main_code,
        p.icon_class,
        p.color_code,
        p.provider_type,
        bp.provider_code as branch_provider_code,
        COALESCE(
            (SELECT drp.current_float FROM daily_report_providers drp
             INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
             WHERE dr.branch_id = ? AND drp.provider_id = p.id
             ORDER BY dr.report_date DESC, dr.id DESC LIMIT 1),
            0
        ) as current_float
    FROM providers p
    INNER JOIN branch_providers bp ON p.id = bp.provider_id
    WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
    ORDER BY p.display_order, p.provider_name
");
$stmt->execute([$branch_id, $branch_id]);
$providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// GET CURRENT BRANCH CAPITAL
// ============================================================
$current_cash = 0;
$current_float = 0;
$current_capital = 0;

$stmt = $db->prepare("
    SELECT current_cash, current_capital 
    FROM daily_reports 
    WHERE branch_id = ? 
    ORDER BY report_date DESC, id DESC 
    LIMIT 1
");
$stmt->execute([$branch_id]);
$dr = $stmt->fetch(PDO::FETCH_ASSOC);
if ($dr) {
    $current_cash = floatval($dr['current_cash'] ?? 0);
    $current_capital = floatval($dr['current_capital'] ?? 0);
}

$stmt = $db->prepare("
    SELECT COALESCE(SUM(drp.current_float), 0) as total_float
    FROM daily_report_providers drp
    INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
    WHERE dr.branch_id = ?
    AND dr.id = (SELECT MAX(id) FROM daily_reports WHERE branch_id = ?)
");
$stmt->execute([$branch_id, $branch_id]);
$current_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);

// ============================================================
// TYPE OPTIONS
// ============================================================
$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

$is_float = ($transaction['reference_module'] === 'provider' && !empty($transaction['reference_id']));

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_transaction') {
    try {
        $new_date = $_POST['transaction_date'] ?? date('Y-m-d');
        $new_type = $_POST['transaction_type'] ?? 'additional';
        $new_reference_module = $_POST['reference_module'] ?? 'cash';
        $new_reference_id = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
        $new_amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $new_description = trim($_POST['description'] ?? '');
        $new_notes = trim($_POST['notes'] ?? '');
        
        // Validate
        if ($new_amount <= 0) {
            throw new Exception('Please enter a valid amount greater than 0.');
        }
        if (!in_array($new_type, ['opening', 'additional', 'profit_allocation', 'cash_out', 'adjustment'])) {
            throw new Exception('Invalid transaction type.');
        }
        if (!in_array($new_reference_module, ['cash', 'provider'])) {
            throw new Exception('Invalid reference module.');
        }
        if ($new_reference_module === 'provider' && empty($new_reference_id)) {
            throw new Exception('Please select a provider.');
        }
        
        // ====================================================
        // START TRANSACTION
        // ====================================================
        $db->beginTransaction();
        
        // Get old data
        $old_amount = floatval($transaction['amount']);
        $old_type = $transaction['transaction_type'];
        $old_module = $transaction['reference_module'];
        $old_reference_id = !empty($transaction['reference_id']) ? intval($transaction['reference_id']) : null;
        $is_old_out = in_array($old_type, ['cash_out', 'adjustment']);
        $is_new_out = in_array($new_type, ['cash_out', 'adjustment']);
        
        // Get latest daily report
        $stmt = $db->prepare("
            SELECT id, current_cash, current_capital 
            FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$latest_dr) {
            throw new Exception('No daily report found for this branch. Please create a daily report first.');
        }
        
        $dr_id = $latest_dr['id'];
        
        // ====================================================
        // STEP 1: REVERSE OLD TRANSACTION
        // ====================================================
        
        // 1a. Reverse old FLOAT if it was provider
        if ($old_module === 'provider' && $old_reference_id > 0) {
            $stmt = $db->prepare("
                SELECT id, current_float 
                FROM daily_report_providers 
                WHERE daily_report_id = ? AND provider_id = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$dr_id, $old_reference_id]);
            $old_drp = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($old_drp) {
                $old_float = floatval($old_drp['current_float']);
                
                if ($is_old_out) {
                    // Was OUT: reverse by adding back
                    $reversed_float = $old_float + $old_amount;
                } else {
                    // Was IN: reverse by subtracting
                    $reversed_float = max(0, $old_float - $old_amount);
                }
                
                $stmt = $db->prepare("
                    UPDATE daily_report_providers 
                    SET current_float = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$reversed_float, $old_drp['id']]);
            }
        }
        
        // 1b. Reverse old CASH if it was cash
        if ($old_module === 'cash' || empty($old_module)) {
            $stmt = $db->prepare("SELECT current_cash FROM daily_reports WHERE id = ?");
            $stmt->execute([$dr_id]);
            $old_cash = floatval($stmt->fetchColumn());
            
            if ($is_old_out) {
                // Was OUT: reverse by adding back
                $reversed_cash = $old_cash + $old_amount;
            } else {
                // Was IN: reverse by subtracting
                $reversed_cash = max(0, $old_cash - $old_amount);
            }
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$reversed_cash, $dr_id]);
        }
        
        // 1c. Always reverse old CAPITAL based on old direction
        $stmt = $db->prepare("SELECT current_capital FROM daily_reports WHERE id = ?");
        $stmt->execute([$dr_id]);
        $old_capital = floatval($stmt->fetchColumn());
        
        if ($is_old_out) {
            $reversed_capital = $old_capital + $old_amount;
        } else {
            $reversed_capital = max(0, $old_capital - $old_amount);
        }
        
        $stmt = $db->prepare("
            UPDATE daily_reports 
            SET current_capital = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$reversed_capital, $dr_id]);
        
        // ====================================================
        // STEP 2: APPLY NEW TRANSACTION
        // ====================================================
        
        // 2a. Apply new FLOAT if provider
        if ($new_reference_module === 'provider' && $new_reference_id > 0) {
            $stmt = $db->prepare("
                SELECT id, current_float 
                FROM daily_report_providers 
                WHERE daily_report_id = ? AND provider_id = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$dr_id, $new_reference_id]);
            $new_drp = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($new_drp) {
                $new_float_old = floatval($new_drp['current_float']);
                
                if ($is_new_out) {
                    $new_float = max(0, $new_float_old - $new_amount);
                } else {
                    $new_float = $new_float_old + $new_amount;
                }
                
                $stmt = $db->prepare("
                    UPDATE daily_report_providers 
                    SET current_float = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_float, $new_drp['id']]);
            } else {
                // Create new provider row
                $stmt = $db->prepare("
                    SELECT p.provider_name, bp.provider_code 
                    FROM providers p
                    INNER JOIN branch_providers bp ON p.id = bp.provider_id AND bp.branch_id = ?
                    WHERE p.id = ?
                ");
                $stmt->execute([$branch_id, $new_reference_id]);
                $pinfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($pinfo) {
                    $new_float = $is_new_out ? 0 : $new_amount;
                    
                    $stmt = $db->prepare("
                        INSERT INTO daily_report_providers 
                        (daily_report_id, provider_id, provider_code, provider_name,
                         morning_float, morning_cash, current_float, current_cash,
                         total_deposits, total_withdrawals, created_at)
                        VALUES (?, ?, ?, ?, 0, 0, ?, 0, 0, 0, NOW())
                    ");
                    $stmt->execute([
                        $dr_id,
                        $new_reference_id,
                        $pinfo['provider_code'],
                        $pinfo['provider_name'],
                        $new_float
                    ]);
                }
            }
        }
        
        // 2b. Apply new CASH if cash
        if ($new_reference_module === 'cash') {
            $stmt = $db->prepare("SELECT current_cash FROM daily_reports WHERE id = ?");
            $stmt->execute([$dr_id]);
            $new_cash_old = floatval($stmt->fetchColumn());
            
            if ($is_new_out) {
                $new_cash = max(0, $new_cash_old - $new_amount);
            } else {
                $new_cash = $new_cash_old + $new_amount;
            }
            
            $stmt = $db->prepare("
                UPDATE daily_reports 
                SET current_cash = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$new_cash, $dr_id]);
        }
        
        // 2c. Always apply new CAPITAL based on new direction
        $stmt = $db->prepare("SELECT current_capital FROM daily_reports WHERE id = ?");
        $stmt->execute([$dr_id]);
        $new_capital_old = floatval($stmt->fetchColumn());
        
        if ($is_new_out) {
            $new_capital = max(0, $new_capital_old - $new_amount);
        } else {
            $new_capital = $new_capital_old + $new_amount;
        }
        
        $stmt = $db->prepare("
            UPDATE daily_reports 
            SET current_capital = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$new_capital, $dr_id]);
        
        // ====================================================
        // STEP 3: UPDATE THE TRANSACTION RECORD
        // ====================================================
        $stmt = $db->prepare("
            UPDATE capital_management 
            SET transaction_date = ?,
                transaction_type = ?,
                reference_module = ?,
                reference_id = ?,
                amount = ?,
                description = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $new_date,
            $new_type,
            $new_reference_module,
            $new_reference_id,
            $new_amount,
            $new_description,
            $new_notes,
            $transaction_id
        ]);
        
        // ====================================================
        // LOG ACTIVITY
        // ====================================================
        logActivity(
            $user_id,
            'Edit Capital Transaction',
            'Capital Management',
            $transaction_id,
            json_encode([
                'old' => [
                    'amount' => $old_amount,
                    'type' => $old_type,
                    'module' => $old_module
                ],
                'new' => [
                    'amount' => $new_amount,
                    'type' => $new_type,
                    'module' => $new_reference_module
                ]
            ]),
            'Edited capital: ' . $transaction['capital_number'] . ' (' . formatCurrency($old_amount) . ' → ' . formatCurrency($new_amount) . ')'
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = 'Capital transaction updated successfully! Cash and Capital adjusted.';
        header('Location: view.php?id=' . $transaction_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Success/error from session
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
        
        <!-- ============================================================
        BRANCH CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-edit"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Editing Transaction For</span>
                <span class="branch-status-name">
                    <?php echo htmlspecialchars($transaction['branch_name'] ?? 'N/A'); ?>
                </span>
                <?php if (!empty($transaction['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($transaction['branch_code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($transaction['branch_location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($transaction['branch_location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="view.php?id=<?php echo $transaction_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Details</span>
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2>
                    <i class="fas fa-edit" style="color:#F59E0B;"></i>
                    Edit Capital Transaction
                </h2>
                <p class="text-muted">
                    Reference: <strong><?php echo htmlspecialchars($transaction['capital_number']); ?></strong>
                </p>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
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

        <!-- ============================================================
        ORIGINAL INFO BAR
        ============================================================ -->
        <div class="original-info-bar">
            <div class="oib-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="oib-content">
                <span class="oib-label">Original Transaction</span>
                <span class="oib-text">
                    Amount: <strong><?php echo formatCurrency($transaction['amount']); ?></strong>
                    • Type: <strong><?php echo $type_labels[$transaction['transaction_type']]['label'] ?? ucfirst($transaction['transaction_type']); ?></strong>
                    • Date: <strong><?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?></strong>
                </span>
            </div>
        </div>

        <!-- ============================================================
        SUMMARY CARDS (LIVE - zinabadilika kama unabadilisha form)
        ============================================================ -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="sc-icon sc-icon-blue">
                    <i class="fas fa-vault"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Current Capital</span>
                    <span class="sc-value"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="sc-icon sc-icon-green">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Current Cash</span>
                    <span class="sc-value"><?php echo formatCurrency($current_cash); ?></span>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="sc-icon sc-icon-purple">
                    <i class="fas fa-university"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Current Float</span>
                    <span class="sc-value"><?php echo formatCurrency($current_float); ?></span>
                </div>
            </div>
            
            <div class="summary-card">
                <div class="sc-icon sc-icon-orange">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="sc-content">
                    <span class="sc-label">Created By</span>
                    <span class="sc-value"><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        EDIT FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="editForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_transaction">
                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                
                <!-- ===== TRANSACTION TYPE ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-tag"></i> Transaction Type</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <div class="type-options-grid">
                        <?php foreach ($type_labels as $key => $label): ?>
                            <label class="type-option-card type-option-<?php echo $label['color']; ?>">
                                <input type="radio" 
                                       name="transaction_type" 
                                       value="<?php echo $key; ?>" 
                                       <?php echo $transaction['transaction_type'] == $key ? 'checked' : ''; ?>
                                       onchange="updatePreview()">
                                <div class="toc-content">
                                    <div class="toc-icon">
                                        <i class="fas <?php echo $label['icon']; ?>"></i>
                                    </div>
                                    <div class="toc-text">
                                        <span class="toc-title"><?php echo $label['label']; ?></span>
                                        <span class="toc-desc">
                                            <?php 
                                            if (in_array($key, ['cash_out', 'adjustment'])) {
                                                echo 'Reduces capital';
                                            } else {
                                                echo 'Increases capital';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="toc-check">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- ===== REFERENCE SOURCE ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-database"></i> Where to Apply</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <div class="source-options-grid">
                        <label class="source-option-card source-cash">
                            <input type="radio" 
                                   name="reference_module" 
                                   value="cash" 
                                   <?php echo ($transaction['reference_module'] != 'provider') ? 'checked' : ''; ?>
                                   onchange="onSourceChange('cash')">
                            <div class="soc-content">
                                <div class="soc-icon soc-icon-cash">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="soc-text">
                                    <span class="soc-title">Branch Cash</span>
                                    <span class="soc-desc">Apply to branch cash balance</span>
                                </div>
                                <div class="soc-check">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </label>
                        
                        <label class="source-option-card source-provider">
                            <input type="radio" 
                                   name="reference_module" 
                                   value="provider" 
                                   <?php echo ($transaction['reference_module'] == 'provider') ? 'checked' : ''; ?>
                                   onchange="onSourceChange('provider')">
                            <div class="soc-content">
                                <div class="soc-icon soc-icon-provider">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="soc-text">
                                    <span class="soc-title">Provider Float</span>
                                    <span class="soc-desc">Apply to specific provider float</span>
                                </div>
                                <div class="soc-check">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </label>
                    </div>
                    
                    <!-- Provider Selection -->
                    <div class="provider-select-wrapper" id="providerSelectWrapper" style="<?php echo ($transaction['reference_module'] == 'provider') ? 'display:block;' : 'display:none;'; ?>">
                        <label class="form-label">
                            <i class="fas fa-university"></i>
                            Select Provider <span class="required">*</span>
                        </label>
                        
                        <div class="provider-grid">
                            <?php foreach ($providers_list as $p): 
                                $is_selected = ($transaction['reference_module'] == 'provider' && $transaction['reference_id'] == $p['id']);
                            ?>
                                <label class="provider-option-card <?php echo $is_selected ? 'selected' : ''; ?>">
                                    <input type="radio" 
                                           name="reference_id" 
                                           value="<?php echo $p['id']; ?>"
                                           <?php echo $is_selected ? 'checked' : ''; ?>
                                           onchange="onProviderSelect(this)">
                                    <div class="poc-content">
                                        <div class="poc-icon" style="background: <?php echo htmlspecialchars($p['color_code']); ?>;">
                                            <i class="<?php echo htmlspecialchars($p['icon_class']); ?>"></i>
                                        </div>
                                        <div class="poc-info">
                                            <span class="poc-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                            <span class="poc-code"><?php echo htmlspecialchars($p['branch_provider_code'] ?? $p['main_code']); ?></span>
                                        </div>
                                        <div class="poc-float">
                                            <span class="poc-float-label">Float</span>
                                            <span class="poc-float-value"><?php echo formatCurrency($p['current_float']); ?></span>
                                        </div>
                                        <div class="poc-check">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- ===== BASIC INFO ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Transaction Information</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt"></i>
                                Transaction Date <span class="required">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar"></i></span>
                                <input type="date" 
                                       name="transaction_date" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($transaction['transaction_date']); ?>" 
                                       required>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-money-bill-wave"></i>
                                Amount (TSh) <span class="required">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-coins"></i></span>
                                <input type="text" 
                                       name="amount" 
                                       id="amountInput"
                                       class="form-control money-input" 
                                       value="<?php echo number_format($transaction['amount'], 0, '.', ','); ?>"
                                       placeholder="0"
                                       inputmode="numeric"
                                       oninput="formatMoneyInput(this); updatePreview();"
                                       required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i>
                                Description
                            </label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-pen"></i></span>
                                <input type="text" 
                                       name="description" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($transaction['description'] ?? ''); ?>"
                                       placeholder="Brief description of the transaction">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-sticky-note"></i>
                                Notes
                            </label>
                            <textarea name="notes" 
                                      class="form-control textarea-control" 
                                      rows="3" 
                                      placeholder="Additional notes..."><?php echo htmlspecialchars($transaction['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- ===== PREVIEW ===== -->
                <div class="form-section preview-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Change Preview</h3>
                    </div>
                    
                    <div class="preview-grid">
                        <div class="preview-item">
                            <span class="pi-label">Original</span>
                            <span class="pi-value pi-original"><?php echo formatCurrency($transaction['amount']); ?></span>
                        </div>
                        
                        <div class="preview-item">
                            <span class="pi-label">New Amount</span>
                            <span class="pi-value pi-new" id="previewNewAmount"><?php echo formatCurrency($transaction['amount']); ?></span>
                        </div>
                        
                        <div class="preview-item preview-highlight">
                            <span class="pi-label">Difference</span>
                            <span class="pi-value pi-diff" id="previewDiff">TSh 0</span>
                        </div>
                        
                        <div class="preview-item">
                            <span class="pi-label">Direction</span>
                            <span class="pi-value" id="previewDirection">
                                <span class="direction-badge <?php echo in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? 'badge-out' : 'badge-in'; ?>">
                                    <i class="fas fa-arrow-<?php echo in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? 'up' : 'down'; ?>"></i>
                                    <?php echo in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? 'OUTGOING' : 'INCOMING'; ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Transaction
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="view.php?id=<?php echo $transaction_id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
                
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --ce-bg: #F3F4F6;
    --ce-text: #1F2937;
    --ce-text-secondary: #6B7280;
    --ce-text-light: #9CA3AF;
    --ce-border: #E5E7EB;
    --ce-card-bg: #FFFFFF;
    --ce-card-header: #FAFBFC;
    --ce-input-bg: #F9FAFB;
    --ce-hover: #F3F4F6;
    --ce-shadow: rgba(0,0,0,0.06);
    --ce-shadow-md: rgba(0,0,0,0.1);
}

html.dark-mode {
    --ce-bg: #0F172A;
    --ce-text: #F9FAFB;
    --ce-text-secondary: #9CA3AF;
    --ce-text-light: #6B7280;
    --ce-border: #334155;
    --ce-card-bg: #1E293B;
    --ce-card-header: #1E293B;
    --ce-input-bg: #334155;
    --ce-hover: #334155;
    --ce-shadow: rgba(0,0,0,0.3);
    --ce-shadow-md: rgba(0,0,0,0.5);
}

*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}

body {
    background: var(--ce-bg) !important;
    color: var(--ce-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--ce-bg) !important; overflow-x: hidden !important; }
.main-content { 
    background: var(--ce-bg) !important; 
    overflow-x: hidden !important; 
    max-width: 100% !important; 
    padding: 16px 20px !important; 
}

/* ============================================================
   BRANCH CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(245, 158, 11, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}
.branch-status-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name {
    font-size: 18px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FEF3C7;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    letter-spacing: 0.8px;
    font-family: 'Courier New', monospace;
}
.branch-status-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
}
.btn-back-card {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}
.btn-back-card:hover {
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
    transform: translateX(-3px);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 16px;
    flex-wrap: wrap;
}
.header-left h2 {
    font-size: 22px;
    font-weight: 800;
    color: var(--ce-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-left .text-muted {
    font-size: 13px;
    color: var(--ce-text-secondary);
    margin: 4px 0 0 0;
}
.header-left .text-muted strong {
    color: #F59E0B;
    font-family: 'Courier New', monospace;
    font-weight: 800;
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
    font-weight: 500;
    font-size: 13px;
    animation: slideDown 0.4s ease forwards;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
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
}
.alert-close:hover { opacity: 1; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   ORIGINAL INFO BAR
   ============================================================ */
.original-info-bar {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    margin-bottom: 20px;
    color: #92400E;
    flex-wrap: wrap;
}
.oib-icon {
    width: 44px;
    height: 44px;
    background: rgba(217, 119, 6, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #D97706;
    flex-shrink: 0;
}
.oib-content { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.oib-label {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #92400E;
}
.oib-text { font-size: 13px; font-weight: 600; color: #78350F; line-height: 1.5; }
.oib-text strong { font-weight: 800; font-family: 'Courier New', monospace; }
html.dark-mode .original-info-bar { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #F59E0B; color: #FCD34D; }
html.dark-mode .oib-label { color: #FCD34D; }
html.dark-mode .oib-text { color: #FEF3C7; }

/* ============================================================
   SUMMARY GRID
   ============================================================ */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: var(--ce-card-bg);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--ce-border);
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px var(--ce-shadow);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.summary-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
    background: #3B82F6;
}
.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--ce-shadow-md);
}
.sc-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.sc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.sc-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.sc-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }
.sc-icon-orange { background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFFFFF; }
.sc-content { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.sc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--ce-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sc-value {
    font-size: 15px;
    font-weight: 800;
    color: var(--ce-text);
    word-break: break-word;
    line-height: 1.2;
}

/* ============================================================
   FORM CONTAINER
   ============================================================ */
.form-container {
    background: var(--ce-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--ce-border);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--ce-shadow);
}
.form-section {
    padding: 22px 26px;
    border-bottom: 1px solid var(--ce-border);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 8px;
}
.section-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--ce-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-header h3 i { color: #F59E0B; font-size: 15px; }
.section-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--ce-text-secondary);
    background: var(--ce-hover);
    padding: 3px 12px;
    border-radius: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ============================================================
   TYPE OPTIONS GRID
   ============================================================ */
.type-options-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}
.type-option-card {
    position: relative;
    cursor: pointer;
    display: block;
    border-radius: 12px;
    overflow: hidden;
}
.type-option-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.toc-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--ce-input-bg);
    border: 2px solid var(--ce-border);
    border-radius: 12px;
    transition: all 0.3s ease;
    position: relative;
}
.type-option-card:hover .toc-content { border-color: #FCD34D; }
.type-option-card input[type="radio"]:checked + .toc-content {
    border-color: #F59E0B;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
}
html.dark-mode .type-option-card input[type="radio"]:checked + .toc-content {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
}
.toc-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid;
}
.type-option-blue .toc-icon { background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD; }
.type-option-green .toc-icon { background: #D1FAE5; color: #059669; border-color: #6EE7B7; }
.type-option-purple .toc-icon { background: #EDE9FE; color: #7C3AED; border-color: #C4B5FD; }
.type-option-red .toc-icon { background: #FEE2E2; color: #DC2626; border-color: #FCA5A5; }
.type-option-orange .toc-icon { background: #FEF3C7; color: #D97706; border-color: #FCD34D; }
html.dark-mode .type-option-blue .toc-icon { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .type-option-green .toc-icon { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-option-purple .toc-icon { background: #4C1D95; color: #C4B5FD; border-color: #A78BFA; }
html.dark-mode .type-option-red .toc-icon { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .type-option-orange .toc-icon { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
.toc-text { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.toc-title { font-size: 13px; font-weight: 700; color: var(--ce-text); }
.toc-desc { font-size: 10px; color: var(--ce-text-secondary); line-height: 1.3; }
.toc-check {
    opacity: 0;
    color: #F59E0B;
    font-size: 18px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.type-option-card input[type="radio"]:checked + .toc-content .toc-check {
    opacity: 1;
    transform: scale(1.1);
}

/* ============================================================
   SOURCE OPTIONS GRID
   ============================================================ */
.source-options-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}
.source-option-card {
    position: relative;
    cursor: pointer;
    display: block;
    border-radius: 12px;
    overflow: hidden;
}
.source-option-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.soc-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 18px;
    background: var(--ce-input-bg);
    border: 2px solid var(--ce-border);
    border-radius: 12px;
    transition: all 0.3s ease;
    position: relative;
}
.source-option-card:hover .soc-content { border-color: #FCD34D; }
.source-option-card input[type="radio"]:checked + .soc-content {
    border-color: #F59E0B;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
}
html.dark-mode .source-option-card input[type="radio"]:checked + .soc-content {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
}
.soc-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 1.5px solid;
}
.soc-icon-cash { background: #D1FAE5; color: #059669; border-color: #6EE7B7; }
.soc-icon-provider { background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD; }
html.dark-mode .soc-icon-cash { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .soc-icon-provider { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
.soc-text { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.soc-title { font-size: 14px; font-weight: 700; color: var(--ce-text); }
.soc-desc { font-size: 11px; color: var(--ce-text-secondary); line-height: 1.3; }
.soc-check {
    opacity: 0;
    color: #F59E0B;
    font-size: 20px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.source-option-card input[type="radio"]:checked + .soc-content .soc-check {
    opacity: 1;
    transform: scale(1.1);
}

/* ============================================================
   PROVIDER SELECT
   ============================================================ */
.provider-select-wrapper {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px dashed var(--ce-border);
}
.form-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--ce-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
}
.form-label i { color: #F59E0B; font-size: 13px; }
.form-label .required { color: #DC2626; font-weight: 800; }

.provider-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 10px;
    max-height: 400px;
    overflow-y: auto;
    padding: 4px;
}
.provider-option-card {
    position: relative;
    cursor: pointer;
    display: block;
    border-radius: 10px;
    overflow: hidden;
}
.provider-option-card input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.poc-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--ce-input-bg);
    border: 2px solid var(--ce-border);
    border-radius: 10px;
    transition: all 0.25s ease;
}
.provider-option-card:hover .poc-content { 
    border-color: #FCD34D; 
    transform: translateY(-2px);
}
.provider-option-card.selected .poc-content,
.provider-option-card input[type="radio"]:checked + .poc-content {
    border-color: #F59E0B;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
}
html.dark-mode .provider-option-card input[type="radio"]:checked + .poc-content {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
}
.poc-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.poc-info { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
.poc-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--ce-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.poc-code {
    font-size: 10px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 1px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .poc-code { background: #1E3A5F; color: #60A5FA; }
.poc-float {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    flex-shrink: 0;
}
.poc-float-label {
    font-size: 9px;
    font-weight: 700;
    color: var(--ce-text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.poc-float-value {
    font-size: 11px;
    font-weight: 800;
    color: #059669;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .poc-float-value { color: #34D399; }
.poc-check {
    opacity: 0;
    color: #F59E0B;
    font-size: 18px;
    flex-shrink: 0;
    transition: all 0.25s ease;
}
.provider-option-card input[type="radio"]:checked + .poc-content .poc-check {
    opacity: 1;
    transform: scale(1.1);
}

/* ============================================================
   FORM ROWS
   ============================================================ */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.form-row:last-child { margin-bottom: 0; }
.form-row .full-width { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }

.input-group {
    position: relative;
    display: flex;
    align-items: center;
}
.input-icon {
    position: absolute;
    left: 14px;
    color: #F59E0B;
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}
.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border-radius: 10px;
    border: 1.5px solid var(--ce-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--ce-input-bg);
    color: var(--ce-text);
    font-weight: 500;
}
.form-control::placeholder { color: var(--ce-text-light); }
.form-control:focus {
    border-color: #F59E0B;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
    background: var(--ce-card-bg);
}
.form-control.textarea-control {
    padding: 12px 14px;
    min-height: 80px;
    resize: vertical;
    line-height: 1.6;
}
.money-input {
    font-weight: 800;
    font-size: 16px;
    font-family: 'Inter', 'Courier New', monospace;
    text-align: right;
    padding-right: 18px;
    color: #F59E0B;
    letter-spacing: 0.5px;
}
html.dark-mode .money-input { color: #FCD34D; }

/* ============================================================
   PREVIEW SECTION
   ============================================================ */
.preview-section {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
}
html.dark-mode .preview-section {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
}
.preview-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.preview-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 14px 18px;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 10px;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
    min-width: 0;
}
html.dark-mode .preview-item {
    background: rgba(15, 23, 42, 0.4);
    border-color: rgba(245, 158, 11, 0.5);
}
.preview-item.preview-highlight {
    background: linear-gradient(135deg, #F59E0B, #D97706);
    border-color: #F59E0B;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
}
.pi-label {
    font-size: 10px;
    font-weight: 800;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
html.dark-mode .pi-label { color: #FCD34D; }
.preview-highlight .pi-label { color: #FFFFFF; }
.pi-value {
    font-size: 16px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
    line-height: 1.2;
}
.pi-original { color: #6B7280; }
.pi-new { color: #059669; }
.pi-diff { color: #1D4ED8; }
.preview-highlight .pi-value { color: #FFFFFF; font-size: 18px; }
html.dark-mode .pi-original { color: #9CA3AF; }
html.dark-mode .pi-new { color: #34D399; }
html.dark-mode .pi-diff { color: #60A5FA; }

.direction-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.direction-badge.badge-in {
    background: #D1FAE5;
    color: #065F46;
    border: 1.5px solid #10B981;
}
.direction-badge.badge-out {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #DC2626;
}
html.dark-mode .direction-badge.badge-in { background: #065F46; color: #34D399; }
html.dark-mode .direction-badge.badge-out { background: #7F1D1D; color: #FCA5A5; }

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 26px;
    border-top: 1px solid var(--ce-border);
    background: var(--ce-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-submit {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
    color: white;
}
.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
.btn-reset {
    background: var(--ce-card-bg);
    color: var(--ce-text-secondary);
    border: 1.5px solid var(--ce-border);
}
.btn-reset:hover {
    background: var(--ce-border);
    color: var(--ce-text);
}
.btn-cancel {
    background: var(--ce-card-bg);
    color: var(--ce-text-secondary);
    border: 1.5px solid var(--ce-border);
}
.btn-cancel:hover {
    background: #FEE2E2;
    color: #991B1B;
    border-color: #FECACA;
}
html.dark-mode .btn-cancel:hover {
    background: #7F1D1D;
    color: #FEE2E2;
    border-color: #991B1B;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 1024px) {
    .type-options-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 18px;
    }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    
    .page-header { flex-direction: column; align-items: flex-start; }
    
    .summary-grid { grid-template-columns: 1fr; }
    
    .type-options-grid { grid-template-columns: 1fr; }
    .source-options-grid { grid-template-columns: 1fr; }
    .provider-grid { grid-template-columns: 1fr; }
    
    .form-row { grid-template-columns: 1fr; }
    .form-row .full-width { grid-column: span 1; }
    
    .preview-grid { grid-template-columns: 1fr; }
    
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    
    .form-section { padding: 18px 20px; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-name { font-size: 15px; }
    .branch-status-icon { width: 44px; height: 44px; font-size: 18px; }
    .header-left h2 { font-size: 18px; }
    .section-header h3 { font-size: 13px; }
    .form-control { font-size: 13px; padding: 10px 12px 10px 38px; }
    .money-input { font-size: 14px; }
    .pi-value { font-size: 14px; }
    .form-section { padding: 16px 14px; }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
// ============================================================
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
// SOURCE CHANGE
// ============================================================
function onSourceChange(source) {
    var providerWrapper = document.getElementById('providerSelectWrapper');
    if (source === 'provider') {
        providerWrapper.style.display = 'block';
    } else {
        providerWrapper.style.display = 'none';
    }
    updatePreview();
}

// ============================================================
// PROVIDER SELECT
// ============================================================
function onProviderSelect(radio) {
    document.querySelectorAll('.provider-option-card').forEach(function(card) {
        card.classList.remove('selected');
    });
    radio.closest('.provider-option-card').classList.add('selected');
    updatePreview();
}

// ============================================================
// UPDATE PREVIEW
// ============================================================
function updatePreview() {
    var amountInput = document.getElementById('amountInput');
    var newAmount = 0;
    if (amountInput && amountInput.value) {
        newAmount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
    }
    
    var originalAmount = <?php echo floatval($transaction['amount']); ?>;
    var diff = newAmount - originalAmount;
    
    // Update new amount
    var newAmountEl = document.getElementById('previewNewAmount');
    if (newAmountEl) newAmountEl.textContent = 'TSh ' + newAmount.toLocaleString('en-US');
    
    // Update difference
    var diffEl = document.getElementById('previewDiff');
    if (diffEl) {
        var sign = diff >= 0 ? '+' : '';
        var color = diff > 0 ? '#059669' : (diff < 0 ? '#DC2626' : '#6B7280');
        diffEl.textContent = sign + 'TSh ' + diff.toLocaleString('en-US');
        diffEl.style.color = color;
    }
    
    // Update direction
    var type = document.querySelector('input[name="transaction_type"]:checked');
    if (type) {
        var isOut = ['cash_out', 'adjustment'].includes(type.value);
        var directionEl = document.getElementById('previewDirection');
        if (directionEl) {
            if (isOut) {
                directionEl.innerHTML = '<span class="direction-badge badge-out"><i class="fas fa-arrow-up"></i> OUTGOING</span>';
            } else {
                directionEl.innerHTML = '<span class="direction-badge badge-in"><i class="fas fa-arrow-down"></i> INCOMING</span>';
            }
        }
    }
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var type = document.querySelector('input[name="transaction_type"]:checked');
    if (!type) {
        alert('Please select a transaction type.');
        return false;
    }
    
    var source = document.querySelector('input[name="reference_module"]:checked');
    if (!source) {
        alert('Please select where to apply.');
        return false;
    }
    
    if (source.value === 'provider') {
        var provider = document.querySelector('input[name="reference_id"]:checked');
        if (!provider) {
            alert('Please select a provider.');
            return false;
        }
    }
    
    var amountInput = document.getElementById('amountInput');
    var amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
    if (amount <= 0) {
        alert('Please enter a valid amount greater than 0.');
        amountInput.focus();
        return false;
    }
    
    // Confirmation
    var originalAmount = <?php echo floatval($transaction['amount']); ?>;
    var confirmMsg = 'Are you sure you want to update this transaction?\n\n' +
                     'Original: TSh ' + originalAmount.toLocaleString() + '\n' +
                     'New: TSh ' + amount.toLocaleString() + '\n\n' +
                     'This will adjust the branch capital accordingly.';
    if (!confirm(confirmMsg)) {
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAny unsaved changes will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
    
    // Auto-select existing provider if any
    <?php if ($transaction['reference_module'] == 'provider' && $transaction['reference_id']): ?>
    var existingProvider = document.querySelector('input[name="reference_id"][value="<?php echo $transaction['reference_id']; ?>"]');
    if (existingProvider) {
        existingProvider.closest('.provider-option-card').classList.add('selected');
    }
    <?php endif; ?>
    
    // Dark mode
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    // Auto-hide alerts
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
    
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(function() {
            errorAlert.style.transition = 'opacity 0.4s ease';
            errorAlert.style.opacity = '0';
            setTimeout(function() {
                if (errorAlert.parentElement) errorAlert.remove();
            }, 400);
        }, 10000);
    }
});
</script>

</body>
</html>