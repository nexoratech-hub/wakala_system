<?php
// ================================================================
// FILE: modules/capital_management/add.php
// WAKALA FINANCIAL SYSTEM - ADD CAPITAL TRANSACTION
// ✅ FIXED: Updates daily_reports.current_cash + current_capital
// ✅ FIXED: Handles both CASH and PROVIDER float transactions
// ✅ FIXED: Auto-selects branch from URL parameter
// ✅ FIXED: Beautiful modern UI with cards
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
// GET BRANCH FROM URL (support both 'branch' and 'branch_id')
// ============================================================
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
    if ($selected_branch < 0) $selected_branch = 0;
}

// ============================================================
// GET BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$all_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Selected branch name
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
        $reference_module = $_POST['reference_module'] ?? 'cash';
        $reference_id = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        // Validate
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        if ($amount <= 0) {
            throw new Exception('Please enter a valid amount greater than 0.');
        }
        if (!in_array($transaction_type, ['opening', 'additional', 'profit_allocation', 'cash_out', 'adjustment'])) {
            throw new Exception('Invalid transaction type.');
        }
        if (!in_array($reference_module, ['cash', 'provider'])) {
            throw new Exception('Invalid reference module.');
        }
        if ($reference_module === 'provider' && empty($reference_id)) {
            throw new Exception('Please select a provider.');
        }
        
        // Get branch name
        $branch_name = '';
        foreach ($all_branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
        
        // ====================================================
        // START TRANSACTION
        // ====================================================
        $db->beginTransaction();
        
        // Generate capital number
        $prefix = 'CAP';
        $capital_number = $prefix . '-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check for duplicate
        $stmt = $db->prepare("SELECT id FROM capital_management WHERE capital_number = ?");
        $stmt->execute([$capital_number]);
        if ($stmt->fetch()) {
            $capital_number = $prefix . '-' . date('Ymd') . '-' . str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        }
        
        // ====================================================
        // GET LATEST DAILY REPORT FOR THIS BRANCH
        // ====================================================
        $stmt = $db->prepare("
            SELECT id, current_cash, current_capital 
            FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id]);
        $latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no daily report exists, we can still add but cash/capital won't update
        $dr_id = $latest_dr ? $latest_dr['id'] : null;
        
        // ====================================================
        // INSERT TRANSACTION
        // ====================================================
        $stmt = $db->prepare("
            INSERT INTO capital_management 
            (capital_number, branch_id, employee_id, transaction_date, transaction_type,
             reference_module, reference_id, amount, description, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $capital_number,
            $branch_id,
            $user_id,
            $transaction_date,
            $transaction_type,
            $reference_module,
            $reference_id,
            $amount,
            $description,
            $notes
        ]);
        
        $capital_id = $db->lastInsertId();
        
        // ====================================================
        // UPDATE DAILY REPORTS
        // ====================================================
        if ($dr_id) {
            $is_out = in_array($transaction_type, ['cash_out', 'adjustment']);
            
            if ($reference_module === 'provider' && $reference_id > 0) {
                // ============================================
                // PROVIDER FLOAT UPDATE
                // ============================================
                $stmt = $db->prepare("
                    SELECT id, current_float 
                    FROM daily_report_providers 
                    WHERE daily_report_id = ? AND provider_id = ?
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([$dr_id, $reference_id]);
                $drp = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($drp) {
                    // Update existing provider row
                    $old_float = floatval($drp['current_float']);
                    
                    if ($is_out) {
                        $new_float = max(0, $old_float - $amount);
                    } else {
                        $new_float = $old_float + $amount;
                    }
                    
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
                        $is_out ? 0 : $amount,
                        $is_out ? $amount : 0,
                        $drp['id']
                    ]);
                } else {
                    // Insert new provider row
                    $stmt = $db->prepare("
                        SELECT p.provider_name, bp.provider_code 
                        FROM providers p
                        INNER JOIN branch_providers bp ON p.id = bp.provider_id AND bp.branch_id = ?
                        WHERE p.id = ?
                    ");
                    $stmt->execute([$branch_id, $reference_id]);
                    $pinfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($pinfo) {
                        $new_float = $is_out ? 0 : $amount;
                        
                        $stmt = $db->prepare("
                            INSERT INTO daily_report_providers 
                            (daily_report_id, provider_id, provider_code, provider_name,
                             morning_float, morning_cash, current_float, current_cash,
                             total_deposits, total_withdrawals, created_at)
                            VALUES (?, ?, ?, ?, 0, 0, ?, 0, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $dr_id,
                            $reference_id,
                            $pinfo['provider_code'],
                            $pinfo['provider_name'],
                            $new_float,
                            $is_out ? 0 : $amount,
                            $is_out ? $amount : 0
                        ]);
                    }
                }
                
                // Update total capital
                $stmt = $db->prepare("SELECT current_capital FROM daily_reports WHERE id = ?");
                $stmt->execute([$dr_id]);
                $current_cap = floatval($stmt->fetchColumn());
                
                if ($is_out) {
                    $new_capital = max(0, $current_cap - $amount);
                } else {
                    $new_capital = $current_cap + $amount;
                }
                
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET current_capital = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_capital, $dr_id]);
                
            } else {
                // ============================================
                // CASH UPDATE
                // ============================================
                $old_cash = floatval($latest_dr['current_cash']);
                $old_capital = floatval($latest_dr['current_capital']);
                
                if ($is_out) {
                    $new_cash = max(0, $old_cash - $amount);
                    $new_capital = max(0, $old_capital - $amount);
                } else {
                    $new_cash = $old_cash + $amount;
                    $new_capital = $old_capital + $amount;
                }
                
                $stmt = $db->prepare("
                    UPDATE daily_reports 
                    SET current_cash = ?, 
                        current_capital = ?, 
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$new_cash, $new_capital, $dr_id]);
            }
        }
        
        // ====================================================
        // LOG ACTIVITY
        // ====================================================
        logActivity(
            $user_id,
            'Add Capital Transaction',
            'Capital Management',
            $capital_id,
            '',
            'Added ' . $transaction_type . ': ' . formatCurrency($amount) . ' at ' . $branch_name
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = 'Capital transaction of ' . formatCurrency($amount) . ' added successfully! Ref: ' . $capital_number;
        header('Location: view.php?id=' . $capital_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// ============================================================
// GET PROVIDERS FOR SELECTED BRANCH (FOR MODAL/DROPDOWN)
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
    $stmt->execute([$selected_branch, $selected_branch]);
    $all_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// GET CURRENT CAPITAL INFO FOR SELECTED BRANCH
// ============================================================
$current_cash = 0;
$current_float = 0;
$current_capital = 0;

if ($selected_branch > 0) {
    $stmt = $db->prepare("
        SELECT current_cash, current_capital 
        FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$selected_branch]);
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
    $stmt->execute([$selected_branch, $selected_branch]);
    $current_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);
}

// Type labels
$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue', 'desc' => 'Initial capital'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green', 'desc' => 'More capital added'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple', 'desc' => 'Profit to capital'],
    'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red', 'desc' => 'Reduce capital'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange', 'desc' => 'Correction']
];

// Success/error messages
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
        BRANCH CARD (Conditional color)
        ============================================================ -->
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

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#F59E0B;"></i> Add Capital Transaction</h2>
                <p class="text-muted">Record a new capital management transaction</p>
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
        IF NO BRANCH SELECTED - SHOW WARNING
        ============================================================ -->
        <?php if ($selected_branch == 0): ?>
            <div class="no-branch-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Please select a branch first</strong>
                    <p>Capital transactions must be applied to a specific branch. Select a branch from the list below or from the topbar filter.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        CURRENT CAPITAL SUMMARY (if branch selected)
        ============================================================ -->
        <?php if ($selected_branch > 0): ?>
        <div class="summary-cards-row">
            <div class="summary-mini-card mini-cash">
                <div class="smc-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="smc-content">
                    <span class="smc-label">Current Cash</span>
                    <span class="smc-value"><?php echo formatCurrency($current_cash); ?></span>
                </div>
            </div>
            
            <div class="summary-mini-card mini-float">
                <div class="smc-icon">
                    <i class="fas fa-university"></i>
                </div>
                <div class="smc-content">
                    <span class="smc-label">Current Float</span>
                    <span class="smc-value"><?php echo formatCurrency($current_float); ?></span>
                </div>
            </div>
            
            <div class="summary-mini-card mini-capital">
                <div class="smc-icon">
                    <i class="fas fa-vault"></i>
                </div>
                <div class="smc-content">
                    <span class="smc-label">Total Capital</span>
                    <span class="smc-value"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        MAIN FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="capitalForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_transaction">
                
                <!-- ===== BRANCH SELECTION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-store-alt"></i> Select Branch</h3>
                        <span class="section-badge">Required *</span>
                    </div>
                    
                    <?php if ($selected_branch > 0): ?>
                        <!-- Branch pre-selected, show as locked -->
                        <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                        <div class="selected-branch-locked">
                            <div class="sbl-icon">
                                <i class="fas fa-store-alt"></i>
                            </div>
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
                        <!-- Branch selection grid -->
                        <div class="branch-selection-grid">
                            <?php foreach ($all_branches as $b): ?>
                                <label class="branch-option">
                                    <input type="radio" name="branch_id" value="<?php echo $b['id']; ?>" required onchange="onBranchChange(<?php echo $b['id']; ?>)">
                                    <div class="bo-content">
                                        <div class="bo-icon">
                                            <i class="fas fa-store-alt"></i>
                                        </div>
                                        <div class="bo-info">
                                            <span class="bo-name"><?php echo htmlspecialchars($b['branch_name']); ?></span>
                                            <?php if (!empty($b['branch_code'])): ?>
                                                <span class="bo-code"><?php echo htmlspecialchars($b['branch_code']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($b['location'])): ?>
                                                <span class="bo-location">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?php echo htmlspecialchars($b['location']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="bo-check">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($selected_branch > 0): ?>
                
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
                                       <?php echo $key == 'additional' ? 'checked' : ''; ?>
                                       onchange="updatePreview()">
                                <div class="toc-content">
                                    <div class="toc-icon">
                                        <i class="fas <?php echo $label['icon']; ?>"></i>
                                    </div>
                                    <div class="toc-text">
                                        <span class="toc-title"><?php echo $label['label']; ?></span>
                                        <span class="toc-desc"><?php echo $label['desc']; ?></span>
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
                                   checked
                                   onchange="onSourceChange('cash')">
                            <div class="soc-content">
                                <div class="soc-icon soc-icon-cash">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="soc-text">
                                    <span class="soc-title">Branch Cash</span>
                                    <span class="soc-desc">Update branch cash balance</span>
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
                                   onchange="onSourceChange('provider')">
                            <div class="soc-content">
                                <div class="soc-icon soc-icon-provider">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="soc-text">
                                    <span class="soc-title">Provider Float</span>
                                    <span class="soc-desc">Update specific provider float</span>
                                </div>
                                <div class="soc-check">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </label>
                    </div>
                    
                    <!-- Provider Selection (Hidden by default) -->
                    <div class="provider-select-wrapper" id="providerSelectWrapper" style="display:none;">
                        <label class="form-label">
                            <i class="fas fa-university"></i>
                            Select Provider <span class="required">*</span>
                        </label>
                        
                        <?php if (count($all_providers) > 0): ?>
                            <div class="provider-grid">
                                <?php foreach ($all_providers as $p): ?>
                                    <label class="provider-option-card">
                                        <input type="radio" 
                                               name="reference_id" 
                                               value="<?php echo $p['id']; ?>"
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
                        <?php else: ?>
                            <div class="empty-providers">
                                <i class="fas fa-info-circle"></i>
                                <p>No providers found for this branch.</p>
                            </div>
                        <?php endif; ?>
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
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       max="<?php echo date('Y-m-d'); ?>"
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
                                      placeholder="Additional notes..."></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- ===== PREVIEW ===== -->
                <div class="form-section preview-section">
                    <div class="section-header">
                        <h3><i class="fas fa-calculator"></i> Preview</h3>
                    </div>
                    
                    <div class="preview-grid">
                        <div class="preview-item">
                            <span class="pi-label">Direction</span>
                            <span class="pi-value" id="previewDirection">
                                <span class="direction-badge badge-in">
                                    <i class="fas fa-arrow-down"></i>
                                    INCOMING
                                </span>
                            </span>
                        </div>
                        
                        <div class="preview-item">
                            <span class="pi-label">Amount</span>
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
                
                <!-- ===== ACTIONS ===== -->
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
                    <!-- No branch selected, show continue button -->
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

<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --ca-bg: #F3F4F6;
    --ca-text: #1F2937;
    --ca-text-secondary: #6B7280;
    --ca-text-light: #9CA3AF;
    --ca-border: #E5E7EB;
    --ca-card-bg: #FFFFFF;
    --ca-card-header: #FAFBFC;
    --ca-input-bg: #F9FAFB;
    --ca-hover: #F3F4F6;
    --ca-shadow: rgba(0,0,0,0.06);
    --ca-shadow-md: rgba(0,0,0,0.1);
}

html.dark-mode {
    --ca-bg: #0F172A;
    --ca-text: #F9FAFB;
    --ca-text-secondary: #9CA3AF;
    --ca-text-light: #6B7280;
    --ca-border: #334155;
    --ca-card-bg: #1E293B;
    --ca-card-header: #1E293B;
    --ca-input-bg: #334155;
    --ca-hover: #334155;
    --ca-shadow: rgba(0,0,0,0.3);
    --ca-shadow-md: rgba(0,0,0,0.5);
}

*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}

body {
    background: var(--ca-bg) !important;
    color: var(--ca-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--ca-bg) !important; overflow-x: hidden !important; }
.main-content { 
    background: var(--ca-bg) !important; 
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
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
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
.branch-status-card.branch-all {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
}
.branch-status-card.branch-selected {
    background: linear-gradient(135deg, #059669 0%, #10B981 100%);
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
    color: #FCD34D;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
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
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    font-family: 'Courier New', monospace;
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
    color: var(--ca-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-left .text-muted {
    font-size: 13px;
    color: var(--ca-text-secondary);
    margin: 4px 0 0 0;
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
   NO BRANCH WARNING
   ============================================================ */
.no-branch-warning {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px 20px;
    background: #FEF3C7;
    border: 2px solid #FDE68A;
    border-radius: 12px;
    margin-bottom: 16px;
    color: #92400E;
}
.no-branch-warning i { font-size: 24px; flex-shrink: 0; margin-top: 2px; color: #D97706; }
.no-branch-warning strong { font-weight: 800; font-size: 14px; display: block; margin-bottom: 4px; }
.no-branch-warning p { font-size: 13px; margin: 0; line-height: 1.5; }
html.dark-mode .no-branch-warning { background: #5F3A1E; border-color: #92400E; color: #FBBF24; }

/* ============================================================
   SUMMARY MINI CARDS
   ============================================================ */
.summary-cards-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-mini-card {
    background: var(--ca-card-bg);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--ca-border);
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 2px 8px var(--ca-shadow);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    min-width: 0;
}
.summary-mini-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}
.summary-mini-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--ca-shadow-md);
}
.mini-cash::before { background: #10B981; }
.mini-float::before { background: #3B82F6; }
.mini-capital::before { background: #7C3AED; }

.smc-icon {
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
.mini-cash .smc-icon { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.mini-float .smc-icon { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.mini-capital .smc-icon { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }

.smc-content { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.smc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--ca-text-light);
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.smc-value {
    font-size: 18px;
    font-weight: 900;
    color: var(--ca-text);
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
    line-height: 1.15;
}

/* ============================================================
   FORM CONTAINER
   ============================================================ */
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
    color: var(--ca-text);
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
    color: var(--ca-text-secondary);
    background: var(--ca-hover);
    padding: 3px 12px;
    border-radius: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ============================================================
   SELECTED BRANCH LOCKED
   ============================================================ */
.selected-branch-locked {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
    border: 2px solid #10B981;
    border-radius: 12px;
    flex-wrap: wrap;
}
html.dark-mode .selected-branch-locked {
    background: linear-gradient(135deg, #064E3B 0%, #065F46 100%);
    border-color: #10B981;
}
.sbl-icon {
    width: 52px;
    height: 52px;
    background: #10B981;
    color: #FFFFFF;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.sbl-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.sbl-name {
    font-size: 16px;
    font-weight: 800;
    color: #065F46;
    letter-spacing: 0.3px;
}
html.dark-mode .sbl-name { color: #D1FAE5; }
.sbl-code {
    font-size: 11px;
    font-weight: 700;
    color: #059669;
    background: rgba(16, 185, 129, 0.15);
    padding: 3px 10px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .sbl-code { color: #34D399; background: rgba(52, 211, 153, 0.15); }
.sbl-change {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #FFFFFF;
    color: #059669;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    transition: all 0.25s ease;
    border: 1.5px solid #10B981;
}
.sbl-change:hover {
    background: #10B981;
    color: #FFFFFF;
    transform: translateX(3px);
}
html.dark-mode .sbl-change {
    background: rgba(15, 23, 42, 0.5);
    color: #34D399;
}
html.dark-mode .sbl-change:hover {
    background: #10B981;
    color: #FFFFFF;
}

/* ============================================================
   BRANCH SELECTION GRID
   ============================================================ */
.branch-selection-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
}
.branch-option {
    position: relative;
    cursor: pointer;
    display: block;
    border-radius: 12px;
    overflow: hidden;
}
.branch-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.bo-content {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--ca-input-bg);
    border: 2px solid var(--ca-border);
    border-radius: 12px;
    transition: all 0.3s ease;
}
.branch-option:hover .bo-content {
    border-color: #F59E0B;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(245, 158, 11, 0.2);
}
.branch-option input[type="radio"]:checked + .bo-content {
    border-color: #F59E0B;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    box-shadow: 0 4px 16px rgba(245, 158, 11, 0.3);
}
html.dark-mode .branch-option input[type="radio"]:checked + .bo-content {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
}
.bo-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #F59E0B, #D97706);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.bo-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.bo-name {
    font-size: 14px;
    font-weight: 800;
    color: var(--ca-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.bo-code {
    font-size: 10px;
    font-weight: 700;
    color: #D97706;
    background: #FEF3C7;
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .bo-code { background: #5F3A1E; color: #FBBF24; }
.bo-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: var(--ca-text-secondary);
    font-weight: 500;
}
.bo-location i { font-size: 9px; color: #F59E0B; }
.bo-check {
    opacity: 0;
    color: #F59E0B;
    font-size: 20px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.branch-option input[type="radio"]:checked + .bo-content .bo-check {
    opacity: 1;
    transform: scale(1.1);
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
    background: var(--ca-input-bg);
    border: 2px solid var(--ca-border);
    border-radius: 12px;
    transition: all 0.3s ease;
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
.toc-title { font-size: 13px; font-weight: 700; color: var(--ca-text); }
.toc-desc { font-size: 10px; color: var(--ca-text-secondary); line-height: 1.3; }
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
    background: var(--ca-input-bg);
    border: 2px solid var(--ca-border);
    border-radius: 12px;
    transition: all 0.3s ease;
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
.soc-title { font-size: 14px; font-weight: 700; color: var(--ca-text); }
.soc-desc { font-size: 11px; color: var(--ca-text-secondary); line-height: 1.3; }
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
    border-top: 1px dashed var(--ca-border);
}
.form-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--ca-text-secondary);
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
    background: var(--ca-input-bg);
    border: 2px solid var(--ca-border);
    border-radius: 10px;
    transition: all 0.25s ease;
}
.provider-option-card:hover .poc-content { 
    border-color: #FCD34D; 
    transform: translateY(-2px);
}
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
    color: var(--ca-text);
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
    color: var(--ca-text-light);
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
.empty-providers {
    text-align: center;
    padding: 30px 20px;
    background: var(--ca-input-bg);
    border-radius: 10px;
    border: 1px dashed var(--ca-border);
    color: var(--ca-text-secondary);
}
.empty-providers i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

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
    border: 1.5px solid var(--ca-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--ca-input-bg);
    color: var(--ca-text);
    font-weight: 500;
}
.form-control::placeholder { color: var(--ca-text-light); }
.form-control:focus {
    border-color: #F59E0B;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
    background: var(--ca-card-bg);
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
    grid-template-columns: repeat(3, 1fr);
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
.pi-amount { color: #1D4ED8; }
.preview-highlight .pi-value { color: #FFFFFF; font-size: 18px; }

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
    border-top: 1px solid var(--ca-border);
    background: var(--ca-hover);
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
    background: var(--ca-card-bg);
    color: var(--ca-text-secondary);
    border: 1.5px solid var(--ca-border);
}
.btn-reset:hover {
    background: var(--ca-border);
    color: var(--ca-text);
}
.btn-cancel {
    background: var(--ca-card-bg);
    color: var(--ca-text-secondary);
    border: 1.5px solid var(--ca-border);
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

.select-branch-message {
    text-align: center;
    padding: 40px 20px;
    color: var(--ca-text-secondary);
}
.select-branch-message i {
    font-size: 48px;
    color: #F59E0B;
    display: block;
    margin-bottom: 14px;
    animation: bounceUpDown 2s ease-in-out infinite;
}
@keyframes bounceUpDown {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
.select-branch-message p {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .summary-cards-row { grid-template-columns: repeat(3, 1fr); }
    .type-options-grid { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: repeat(3, 1fr); }
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
    
    .summary-cards-row { grid-template-columns: 1fr; }
    
    .branch-selection-grid { grid-template-columns: 1fr; }
    .type-options-grid { grid-template-columns: 1fr; }
    .source-options-grid { grid-template-columns: 1fr; }
    .provider-grid { grid-template-columns: 1fr; }
    
    .form-row { grid-template-columns: 1fr; }
    .form-row .full-width { grid-column: span 1; }
    
    .preview-grid { grid-template-columns: 1fr; }
    
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    
    .form-section { padding: 18px 20px; }
    
    .selected-branch-locked { flex-direction: column; text-align: center; }
    .sbl-change { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-name { font-size: 15px; }
    .branch-status-icon { width: 44px; height: 44px; font-size: 18px; }
    .header-left h2 { font-size: 18px; }
    .section-header h3 { font-size: 13px; }
    .form-control { font-size: 13px; padding: 10px 12px 10px 38px; }
    .money-input { font-size: 14px; }
    .smc-value { font-size: 16px; }
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
    var amount = 0;
    if (amountInput && amountInput.value) {
        amount = parseFloat(amountInput.value.replace(/,/g, '')) || 0;
    }
    
    var type = document.querySelector('input[name="transaction_type"]:checked');
    var isOut = type && ['cash_out', 'adjustment'].includes(type.value);
    
    // Update direction badge
    var directionEl = document.getElementById('previewDirection');
    if (directionEl) {
        if (isOut) {
            directionEl.innerHTML = '<span class="direction-badge badge-out"><i class="fas fa-arrow-up"></i> OUTGOING</span>';
        } else {
            directionEl.innerHTML = '<span class="direction-badge badge-in"><i class="fas fa-arrow-down"></i> INCOMING</span>';
        }
    }
    
    // Update amount
    var amountEl = document.getElementById('previewAmount');
    if (amountEl) amountEl.textContent = 'TSh ' + amount.toLocaleString('en-US');
    
    // Update new capital
    var currentCapital = <?php echo floatval($current_capital); ?>;
    var newCapital = isOut ? Math.max(0, currentCapital - amount) : currentCapital + amount;
    var newCapitalEl = document.getElementById('previewNewCapital');
    if (newCapitalEl) newCapitalEl.textContent = 'TSh ' + newCapital.toLocaleString('en-US');
}

// ============================================================
// BRANCH CHANGE (redirect to reload with branch info)
// ============================================================
function onBranchChange(branchId) {
    window.location.href = 'add.php?branch=' + branchId;
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var branch = document.querySelector('input[name="branch_id"]:checked');
    var branchHidden = document.querySelector('input[name="branch_id"][type="hidden"]');
    
    if (!branch && !branchHidden) {
        alert('Please select a branch.');
        return false;
    }
    
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
    
    var isOut = ['cash_out', 'adjustment'].includes(type.value);
    var confirmMsg = 'Confirm Transaction:\n\n' +
                     'Type: ' + type.parentElement.querySelector('.toc-title').textContent + '\n' +
                     'Amount: TSh ' + amount.toLocaleString() + '\n' +
                     'Direction: ' + (isOut ? 'OUTGOING' : 'INCOMING') + '\n\n' +
                     'Continue?';
    if (!confirm(confirmMsg)) {
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    return true;
}

function confirmReset() {
    return confirm('Are you sure you want to reset the form?\n\nAny unsaved data will be lost.');
}

// ============================================================
// INITIALIZE
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
    
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