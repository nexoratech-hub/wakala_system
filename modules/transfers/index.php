<?php
// ================================================================
// FILE: modules/transfers/index.php
// WAKALA FINANCIAL SYSTEM - ADMIN TRANSFERS
// BLUE THEME + COLLAPSIBLE FORM + RED HEADER TABLE
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

// BRANCH FILTER
$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== '0') {
    $selected_branch = intval($_GET['branch_id']);
}

$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($selected_branch == 0 && count($branches) === 1) {
    $selected_branch = intval($branches[0]['id']);
}

// BRANCH INFO
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

// LATEST DAILY REPORT
$latest_dr = null;
$current_cash = 0;
$current_float = 0;
$current_capital = 0;
$latest_dr_id = 0;

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
        $latest_dr_id = $latest_dr['id'];
        $current_cash = floatval($latest_dr['current_cash'] ?? 0);
        
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(current_float), 0) as total_float
            FROM daily_report_providers 
            WHERE daily_report_id = ?
        ");
        $stmt->execute([$latest_dr_id]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_float = floatval($f['total_float'] ?? 0);
        
        $current_capital = $current_float + $current_cash;
    }
}

// HANDLE TRANSFER
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transfer') {
    try {
        $db->beginTransaction();
        
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $transfer_type = $_POST['transfer_type'] ?? 'cash_to_float';
        $provider_id = intval($_POST['provider_id'] ?? 0);
        $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $reference_number = trim($_POST['reference_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d');
        
        if ($branch_id <= 0) throw new Exception('Please select a branch.');
        if (!in_array($transfer_type, ['cash_to_float', 'float_to_cash'])) {
            throw new Exception('Invalid transfer type.');
        }
        if ($provider_id <= 0) throw new Exception('Please select a provider.');
        if ($amount <= 0) throw new Exception('Amount must be greater than 0.');
        
        $stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
        $stmt->execute([$branch_id]);
        $branch_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch_data) throw new Exception('Branch not found.');
        $branch_name = $branch_data['branch_name'];
        
        $stmt = $db->prepare("
            SELECT * FROM daily_reports 
            WHERE branch_id = ? 
            ORDER BY report_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$branch_id]);
        $dr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dr) throw new Exception('No daily report found. Please create a daily report first.');
        
        $dr_id = $dr['id'];
        $dr_cash = floatval($dr['current_cash'] ?? 0);
        
        $stmt = $db->prepare("SELECT * FROM providers WHERE id = ? AND is_active = 1");
        $stmt->execute([$provider_id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$provider) throw new Exception('Provider not found.');
        
        $stmt = $db->prepare("SELECT * FROM branch_providers WHERE branch_id = ? AND provider_id = ? AND is_active = 1");
        $stmt->execute([$branch_id, $provider_id]);
        $branch_provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$branch_provider) throw new Exception('Provider not assigned to this branch.');
        
        $stmt = $db->prepare("
            SELECT * FROM daily_report_providers 
            WHERE daily_report_id = ? AND provider_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$dr_id, $provider_id]);
        $dr_provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $provider_float = $dr_provider ? floatval($dr_provider['current_float']) : 0;
        
        if ($transfer_type === 'cash_to_float') {
            if ($dr_cash < $amount) {
                throw new Exception('Insufficient cash. Available TSh ' . number_format($dr_cash, 0));
            }
            $new_float = $provider_float + $amount;
            $new_cash = $dr_cash - $amount;
        } else {
            if ($provider_float < $amount) {
                throw new Exception('Insufficient provider float. Available TSh ' . number_format($provider_float, 0));
            }
            $new_float = $provider_float - $amount;
            $new_cash = $dr_cash + $amount;
        }
        
        // Recompute total float from DB (multi-provider safe)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(current_float), 0)
            FROM daily_report_providers
            WHERE daily_report_id = ? AND provider_id != ?
        ");
        $stmt->execute([$dr_id, $provider_id]);
        $other_float_total = floatval($stmt->fetchColumn());
        $new_total_float = $other_float_total + $new_float;
        $new_capital = $new_total_float + $new_cash;
        
        $prefix = $transfer_type === 'cash_to_float' ? 'CTF' : 'FTC';
        $transfer_number = $prefix . '-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO transfers 
            (transfer_number, transfer_type, employee_id, branch_id, branch,
             provider_id, provider_code, provider_name, amount,
             before_float, after_float, before_cash, after_cash,
             reference_number, description, transfer_date, transfer_time, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
        ");
        $stmt->execute([
            $transfer_number, $transfer_type, $user_id, $branch_id, $branch_name,
            $provider_id, $branch_provider['provider_code'], $provider['provider_name'], $amount,
            $provider_float, $new_float, $dr_cash, $new_cash,
            $reference_number, $description, $transfer_date, date('H:i:s')
        ]);
        
        $transfer_id = $db->lastInsertId();
        
        if ($dr_provider) {
            $stmt = $db->prepare("UPDATE daily_report_providers SET current_float = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_float, $dr_provider['id']]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO daily_report_providers 
                (daily_report_id, provider_id, provider_code, provider_name,
                 morning_float, morning_cash, current_float, current_cash,
                 total_deposits, total_withdrawals, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, 0, NOW())
            ");
            $stmt->execute([
                $dr_id, $provider_id, $branch_provider['provider_code'],
                $provider['provider_name'], $provider_float, $new_float
            ]);
        }
        
        $stmt = $db->prepare("
            UPDATE daily_reports 
            SET current_cash = ?, current_capital = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_cash, $new_capital, $dr_id]);
        
        logActivity(
            $user_id,
            'Add Transfer',
            'Transfers',
            $transfer_id,
            '',
            ucfirst(str_replace('_', ' ', $transfer_type)) . ' of TSh ' . number_format($amount) .
            ' - ' . $provider['provider_name'] . ' (' . $branch_name . ')'
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = 'Transfer of TSh ' . number_format($amount) . ' completed successfully!';
        header('Location: index.php' . ($branch_id > 0 ? '?branch_id=' . $branch_id : ''));
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// PROVIDERS WITH FLOATS
$providers_list = [];
if ($selected_branch > 0 && $latest_dr_id > 0) {
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.provider_name,
            p.provider_code as main_code,
            p.provider_type,
            p.icon_class,
            p.color_code,
            bp.provider_code as branch_provider_code,
            COALESCE(drp.current_float, 0) as current_float,
            COALESCE(drp.morning_float, 0) as morning_float
        FROM providers p
        INNER JOIN branch_providers bp ON p.id = bp.provider_id
        LEFT JOIN daily_report_providers drp ON (
            drp.provider_id = p.id 
            AND drp.daily_report_id = ?
        )
        WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
        ORDER BY p.display_order, p.provider_name
    ");
    $stmt->execute([$latest_dr_id, $selected_branch]);
    $providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// TRANSFER HISTORY
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

$transfers = [];
$total_transfers = 0;
$total_amount = 0;
$total_cash_to_float = 0;
$total_float_to_cash = 0;

try {
    $sql = "
        SELECT 
            t.*, 
            p.icon_class, 
            p.color_code, 
            e.full_name as employee_name,
            e.employee_id as employee_code,
            e.profile_pic as employee_avatar,
            b.branch_name as branch_display_name,
            b.branch_code as branch_display_code
        FROM transfers t
        LEFT JOIN providers p ON t.provider_id = p.id
        LEFT JOIN employees e ON t.employee_id = e.id
        LEFT JOIN branches b ON t.branch_id = b.id
        WHERE t.transfer_date BETWEEN ? AND ?
    ";
    $params = [$from_date, $to_date];
    
    if ($selected_branch > 0) {
        $sql .= " AND t.branch_id = ?";
        $params[] = $selected_branch;
    }
    
    $sql .= " ORDER BY t.id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_transfers = count($transfers);
    foreach ($transfers as $t) {
        $total_amount += floatval($t['amount']);
        if ($t['transfer_type'] === 'cash_to_float') {
            $total_cash_to_float += floatval($t['amount']);
        } else {
            $total_float_to_cash += floatval($t['amount']);
        }
    }
} catch (Exception $e) {
    $transfers = [];
}

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
        BRANCH INDICATOR - BLUE THEME
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

        <!-- ============================================================
        CAPITAL SUMMARY
        ============================================================ -->
        <?php if ($selected_branch > 0): ?>
        <div class="capital-summary-compact">
            <div class="capital-item-compact capital-float">
                <div class="capital-icon-compact">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="capital-info-compact">
                    <span class="capital-label-compact">Total Float</span>
                    <span class="capital-value-compact"><?php echo formatCurrency($current_float); ?></span>
                </div>
            </div>
            
            <div class="capital-item-compact capital-cash">
                <div class="capital-icon-compact">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="capital-info-compact">
                    <span class="capital-label-compact">Cash Balance</span>
                    <span class="capital-value-compact"><?php echo formatCurrency($current_cash); ?></span>
                </div>
            </div>
            
            <div class="capital-item-compact capital-total">
                <div class="capital-icon-compact">
                    <i class="fas fa-building"></i>
                </div>
                <div class="capital-info-compact">
                    <span class="capital-label-compact">Total Capital</span>
                    <span class="capital-value-compact"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        PAGE HEADER - WITH NEW TRANSFER BUTTON
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-exchange-alt"></i> Transfers</h2>
                <p class="text-muted">Move money between Cash and Provider Float</p>
            </div>
            <div class="header-right">
                <?php if ($selected_branch > 0 && $latest_dr): ?>
                    <button type="button" class="btn-new-transfer" id="newTransferBtn" onclick="toggleTransferForm()">
                        <i class="fas fa-plus-circle" id="newTransferIcon"></i>
                        <span id="newTransferLabel">New Transfer</span>
                    </button>
                <?php endif; ?>
                <a href="../daily_report/index.php?branch_id=<?php echo $selected_branch; ?>" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
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
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        TRANSFER FORM - COLLAPSIBLE (Hidden by default)
        ============================================================ -->
        <?php if ($selected_branch > 0 && $latest_dr): ?>
        <div class="transfer-form-container hidden" id="transferFormContainer">
            <div class="transfer-form-header">
                <div class="transfer-form-header-left">
                    <i class="fas fa-exchange-alt"></i>
                    <div>
                        <h3>New Transfer</h3>
                        <p>Transfer between Cash and Provider Float</p>
                    </div>
                </div>
                <button type="button" class="btn-toggle-transfer" onclick="toggleTransferForm()">
                    <i class="fas fa-times" id="transferToggleIcon"></i>
                </button>
            </div>
            
            <form method="POST" action="" class="transfer-form" id="transferForm" onsubmit="return validateTransfer()">
                <input type="hidden" name="action" value="add_transfer">
                <input type="hidden" name="branch_id" value="<?php echo $selected_branch; ?>">
                <input type="hidden" name="provider_id" id="hiddenProviderId" value="">
                
                <!-- Transfer Type -->
                <div class="transfer-type-selector">
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="cash_to_float" checked onchange="updateTransferUI()">
                        <div class="transfer-type-card type-cash-to-float">
                            <div class="type-icon">
                                <i class="fas fa-arrow-right"></i>
                            </div>
                            <div class="type-info">
                                <span class="type-title">Cash → Float</span>
                                <span class="type-desc">Move from Cash to Provider Float</span>
                            </div>
                        </div>
                    </label>
                    
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="float_to_cash" onchange="updateTransferUI()">
                        <div class="transfer-type-card type-float-to-cash">
                            <div class="type-icon">
                                <i class="fas fa-arrow-left"></i>
                            </div>
                            <div class="type-info">
                                <span class="type-title">Float → Cash</span>
                                <span class="type-desc">Move from Provider Float to Cash</span>
                            </div>
                        </div>
                    </label>
                </div>
                
                <!-- Provider Selection -->
                <div class="provider-select-section">
                    <label class="provider-select-label">
                        <i class="fas fa-university"></i>
                        Select Provider <span class="required">*</span>
                    </label>
                    
                    <div class="custom-provider-dropdown" id="customProviderDropdown">
                        <div class="provider-dropdown-trigger" onclick="toggleProviderDropdown()">
                            <div class="provider-dropdown-trigger-content" id="providerTriggerContent">
                                <div class="provider-placeholder">
                                    <i class="fas fa-hand-pointer"></i>
                                    <span>Click to select a provider...</span>
                                </div>
                            </div>
                            <i class="fas fa-chevron-down provider-dropdown-arrow" id="providerDropdownArrow"></i>
                        </div>
                        
                        <div class="provider-dropdown-menu" id="providerDropdownMenu">
                            <div class="provider-dropdown-search">
                                <i class="fas fa-search"></i>
                                <input type="text" 
                                       id="providerSearchInput"
                                       placeholder="Search provider..." 
                                       oninput="filterProviders(this.value)"
                                       onclick="event.stopPropagation()">
                            </div>
                            
                            <div class="provider-dropdown-list" id="providerDropdownList">
                                <div class="provider-cards-grid">
                                    <?php if (count($providers_list) > 0): ?>
                                        <?php foreach ($providers_list as $p): 
                                            $color = $p['color_code'] ?? '#0B5ED7';
                                            $icon = $p['icon_class'] ?? 'fas fa-university';
                                            $provider_float = floatval($p['current_float']);
                                            $provider_code = $p['branch_provider_code'] ?? $p['main_code'];
                                        ?>
                                            <div class="provider-card-item" 
                                                 data-provider-id="<?php echo $p['id']; ?>"
                                                 data-provider-name="<?php echo htmlspecialchars(strtolower($p['provider_name'])); ?>"
                                                 data-provider-code="<?php echo htmlspecialchars(strtolower($provider_code)); ?>"
                                                 data-float="<?php echo $provider_float; ?>"
                                                 data-name="<?php echo htmlspecialchars($p['provider_name']); ?>"
                                                 data-code="<?php echo htmlspecialchars($provider_code); ?>"
                                                 data-color="<?php echo $color; ?>"
                                                 data-icon="<?php echo $icon; ?>"
                                                 onclick="selectProvider(this)">
                                                
                                                <div class="provider-card-check">
                                                    <i class="fas fa-check-circle"></i>
                                                </div>
                                                
                                                <div class="provider-card-icon" style="background: <?php echo $color; ?>;">
                                                    <i class="<?php echo $icon; ?>"></i>
                                                </div>
                                                
                                                <div class="provider-card-name">
                                                    <?php echo htmlspecialchars($p['provider_name']); ?>
                                                </div>
                                                
                                                <div class="provider-card-code">
                                                    <?php echo htmlspecialchars($provider_code); ?>
                                                </div>
                                                
                                                <div class="provider-card-float">
                                                    <span class="provider-float-label">
                                                        <i class="fas fa-coins"></i> Float
                                                    </span>
                                                    <span class="provider-float-value">
                                                        <?php echo formatCurrency($provider_float); ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="provider-card-type">
                                                    <i class="fas fa-<?php echo $p['provider_type'] == 'mobile_money' ? 'mobile-alt' : 'university'; ?>"></i>
                                                    <?php echo ucfirst(str_replace('_', ' ', $p['provider_type'] ?? 'Bank')); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="grid-column: 1 / -1; padding: 30px; text-align: center; color: var(--text-muted);">
                                            <i class="fas fa-exclamation-circle" style="font-size: 32px; opacity: 0.4; display: block; margin-bottom: 10px;"></i>
                                            <p style="margin: 0; font-size: 13px;">No providers assigned to this branch.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="provider-dropdown-empty" id="providerDropdownEmpty" style="display:none;">
                                <i class="fas fa-search-minus"></i>
                                <p>No providers found</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Amount + Date -->
                <div class="transfer-form-row">
                    <div class="transfer-form-group">
                        <label>Amount (TSh) <span class="required">*</span></label>
                        <input type="text" 
                               name="amount" 
                               id="transferAmount" 
                               class="transfer-form-control transfer-amount-input" 
                               placeholder="1,000,000" 
                               inputmode="numeric"
                               autocomplete="off"
                               required
                               oninput="formatMoneyInput(this); updatePreview();">
                    </div>
                    
                    <div class="transfer-form-group">
                        <label>Transfer Date <span class="required">*</span></label>
                        <input type="date" name="transfer_date" class="transfer-form-control" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <!-- Live Preview -->
                <div class="transfer-preview" id="transferPreview" style="display:none;">
                    <div class="preview-header">
                        <i class="fas fa-eye"></i> Transfer Preview
                    </div>
                    <div class="preview-grid">
                        <div class="preview-item">
                            <span class="preview-label">Provider</span>
                            <span class="preview-value" id="previewProvider">-</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">Current Float</span>
                            <span class="preview-value preview-float" id="previewCurrentFloat">TSh 0</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">Current Cash</span>
                            <span class="preview-value preview-cash" id="previewCurrentCash">TSh 0</span>
                        </div>
                        <div class="preview-item">
                            <span class="preview-label">After Transfer</span>
                            <span class="preview-value preview-after" id="previewAfter">TSh 0</span>
                        </div>
                    </div>
                </div>
                
                <div class="transfer-form-row">
                    <div class="transfer-form-group">
                        <label>Reference Number</label>
                        <input type="text" name="reference_number" class="transfer-form-control" 
                               placeholder="Optional reference">
                    </div>
                    <div class="transfer-form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="transfer-form-control" 
                               placeholder="Optional description">
                    </div>
                </div>
                
                <div class="transfer-form-actions">
                    <button type="submit" class="btn-transfer" id="transferSubmitBtn">
                        <i class="fas fa-exchange-alt"></i> Execute Transfer
                    </button>
                    <button type="button" class="btn-reset" onclick="resetTransfer()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
        <?php elseif ($selected_branch > 0): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>No daily report found for this branch. <a href="../daily_report/generate.php?branch_id=<?php echo $selected_branch; ?>">Generate daily report</a> first.</span>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        SUMMARY CARDS
        ============================================================ -->
        <div class="transfer-summary-cards">
            <div class="transfer-summary-card">
                <div class="ts-icon ts-icon-blue">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="ts-info">
                    <span class="ts-label">Total Transfers</span>
                    <span class="ts-value"><?php echo number_format($total_transfers); ?></span>
                </div>
            </div>
            
            <div class="transfer-summary-card">
                <div class="ts-icon ts-icon-green">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="ts-info">
                    <span class="ts-label">Total Amount</span>
                    <span class="ts-value"><?php echo formatCurrency($total_amount); ?></span>
                </div>
            </div>
            
            <div class="transfer-summary-card">
                <div class="ts-icon ts-icon-cyan">
                    <i class="fas fa-arrow-right"></i>
                </div>
                <div class="ts-info">
                    <span class="ts-label">Cash → Float</span>
                    <span class="ts-value"><?php echo formatCurrency($total_cash_to_float); ?></span>
                </div>
            </div>
            
            <div class="transfer-summary-card">
                <div class="ts-icon ts-icon-orange">
                    <i class="fas fa-arrow-left"></i>
                </div>
                <div class="ts-info">
                    <span class="ts-label">Float → Cash</span>
                    <span class="ts-value"><?php echo formatCurrency($total_float_to_cash); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FILTERS
        ============================================================ -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To Date</label>
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
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="index.php" class="btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        TRANSFER HISTORY TABLE
        ============================================================ -->
        <div class="table-container">
            
            <!-- RED HEADER -->
            <div class="table-red-header">
                <div class="table-red-header-left">
                    <i class="fas fa-history"></i>
                    <h3>Transfer History</h3>
                    <span class="count-badge"><?php echo count($transfers); ?></span>
                </div>
            </div>
            
            <?php if (count($transfers) > 0): ?>
                <div class="table-wrapper" id="transferTableWrapper">
                    <table class="data-table" id="transferTable">
                        <thead>
                            <tr class="table-search-row">
                                <th colspan="11" class="table-search-cell">
                                    <div class="table-search-row-content">
                                        <div class="table-search-wrapper">
                                            <i class="fas fa-search table-search-icon"></i>
                                            <input type="text" 
                                                   class="table-search-input" 
                                                   id="tableSearchInput"
                                                   placeholder="Search transfer #, provider, employee..."
                                                   oninput="onTableSearch(this)">
                                            <button type="button" class="table-search-clear" 
                                                    id="tableSearchClear"
                                                    onclick="clearTableSearch()" 
                                                    style="display:none;">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <span class="table-search-count" 
                                                  id="tableSearchCount" 
                                                  style="display:none;">0</span>
                                        </div>
                                        
                                        <div class="table-scroll-center">
                                            <button type="button" class="scroll-btn scroll-left" 
                                                    onclick="scrollTable('left')" 
                                                    title="Scroll Left">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                            <span class="scroll-label">
                                                <i class="fas fa-arrows-alt-h"></i> SCROLL
                                            </span>
                                            <button type="button" class="scroll-btn scroll-right" 
                                                    onclick="scrollTable('right')" 
                                                    title="Scroll Right">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="table-search-spacer"></div>
                                    </div>
                                </th>
                            </tr>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Transfer #</th>
                                <th>Type</th>
                                <th>Date & Time</th>
                                <th>Provider</th>
                                <th>Code</th>
                                <th class="text-right">Amount</th>
                                <th>Float Change</th>
                                <th>Employee</th>
                                <th>Reference</th>
                                <th style="width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="transferTableBody">
                            <?php 
                            $i = 1;
                            foreach ($transfers as $t): 
                                $is_cash_to_float = $t['transfer_type'] === 'cash_to_float';
                                $type_label = $is_cash_to_float ? 'Cash → Float' : 'Float → Cash';
                                $type_class = $is_cash_to_float ? 'type-cash-to-float' : 'type-float-to-cash';
                                $color = $t['color_code'] ?? '#0B5ED7';
                                $icon = $t['icon_class'] ?? 'fas fa-university';
                                
                                $employee_avatar = $t['employee_avatar'] ?? '';
                                $employee_initial = strtoupper(substr($t['employee_name'] ?? 'N', 0, 1));
                                
                                $search_data = strtolower(
                                    ($t['transfer_number'] ?? '') . ' ' .
                                    ($t['provider_name'] ?? '') . ' ' .
                                    ($t['provider_code'] ?? '') . ' ' .
                                    ($t['employee_name'] ?? '') . ' ' .
                                    ($t['reference_number'] ?? '')
                                );
                            ?>
                                <tr class="transfer-row" data-search="<?php echo htmlspecialchars($search_data); ?>">
                                    <td class="row-number"><?php echo $i++; ?></td>
                                    <td>
                                        <span class="transfer-number"><?php echo htmlspecialchars($t['transfer_number']); ?></span>
                                    </td>
                                    <td>
                                        <span class="type-badge-txn <?php echo $type_class; ?>">
                                            <i class="fas fa-arrow-<?php echo $is_cash_to_float ? 'right' : 'left'; ?>"></i>
                                            <?php echo $type_label; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="date-cell">
                                            <i class="far fa-calendar"></i>
                                            <?php echo date('d M Y', strtotime($t['transfer_date'])); ?>
                                            <span class="time-cell">
                                                <?php echo date('H:i', strtotime($t['transfer_time'] ?? $t['created_at'])); ?>
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="provider-cell">
                                            <div class="provider-icon" style="background:<?php echo $color; ?>;">
                                                <i class="<?php echo $icon; ?>"></i>
                                            </div>
                                            <span><?php echo htmlspecialchars($t['provider_name'] ?? 'N/A'); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="code-badge"><?php echo htmlspecialchars($t['provider_code'] ?? '-'); ?></span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-transfer <?php echo $type_class; ?>">
                                            <?php echo formatCurrency($t['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="float-change">
                                            <span class="float-before"><?php echo formatCurrency($t['before_float']); ?></span>
                                            <i class="fas fa-arrow-right"></i>
                                            <span class="float-after"><?php echo formatCurrency($t['after_float']); ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="employee-cell-full">
                                            <?php if ($employee_avatar && file_exists('../../' . $employee_avatar)): ?>
                                                <img src="../../<?php echo htmlspecialchars($employee_avatar); ?>" 
                                                     alt="<?php echo htmlspecialchars($t['employee_name']); ?>"
                                                     class="employee-avatar-img"
                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="employee-avatar" style="display:none;">
                                                    <?php echo $employee_initial; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="employee-avatar">
                                                    <?php echo $employee_initial; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="employee-name-details">
                                                <span class="employee-name-full">
                                                    <?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?>
                                                </span>
                                                <?php if (!empty($t['employee_code'])): ?>
                                                    <span class="employee-code">
                                                        <?php echo htmlspecialchars($t['employee_code']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="reference-cell">
                                            <?php echo htmlspecialchars($t['reference_number'] ?: '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="transfer-actions">
                                            <a href="view.php?id=<?php echo $t['id']; ?>" 
                                               class="btn-action-txn btn-view-txn" 
                                               title="View Transfer">
                                                <i class="fas fa-eye"></i>
                                                <span>View</span>
                                            </a>
                                            <a href="edit.php?id=<?php echo $t['id']; ?>" 
                                               class="btn-action-txn btn-edit-txn" 
                                               title="Edit Transfer">
                                                <i class="fas fa-edit"></i>
                                                <span>Edit</span>
                                            </a>
                                            <button onclick="deleteTransfer(<?php echo $t['id']; ?>, '<?php echo addslashes($t['transfer_number']); ?>')" 
                                                    class="btn-action-txn btn-delete-txn" 
                                                    title="Delete Transfer">
                                                <i class="fas fa-trash"></i>
                                                <span>Delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="no-results" id="noResults" style="display:none;">
                        <i class="fas fa-search-minus"></i>
                        <p>No transfers match your search</p>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="clearTableSearch()">
                            <i class="fas fa-times"></i> Clear Search
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exchange-alt"></i>
                    <h3>No Transfers Found</h3>
                    <p>No transfers in this period.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   BLUE THEME VARIABLES
   ============================================================ */
:root {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-table-even: #f8fafc;
    --bg-table-hover: #eff6ff;
    --bg-input: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(30, 64, 175, 0.08);
    --shadow-hover: rgba(30, 64, 175, 0.15);
    
    --blue-primary: #1e40af;
    --blue-dark: #1e3a8a;
    --blue-mid: #2563eb;
    --blue-light: #3b82f6;
    --blue-lighter: #dbeafe;
    --blue-lightest: #eff6ff;
    --blue-accent: #60a5fa;
    
    --green-primary: #059669;
    --green-light: #10b981;
    --orange-primary: #d97706;
    --orange-light: #f59e0b;
    --red-primary: #bb0404;
    --red-dark: #8a0303;
    --cyan-primary: #0891b2;
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
    
    --blue-lighter: #1e3a5f;
    --blue-lightest: #1e293b;
}

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

body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   BRANCH INDICATOR - BLUE GRADIENT
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 14px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
    flex-wrap: wrap; gap: 12px;
    position: relative;
    overflow: hidden;
}
.branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.branch-indicator-left {
    display: flex; align-items: center; gap: 14px;
    flex-wrap: wrap; min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex; align-items: center;
    justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
    backdrop-filter: blur(8px);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700; color: #FFFFFF;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 12px;
    color: rgba(255,255,255,0.9); padding: 4px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px; white-space: nowrap;
}
.branch-indicator-right {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; position: relative; z-index: 1;
}
.branch-indicator-right .date-display {
    font-size: 12px; color: rgba(255,255,255,0.95);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 16px;
    display: flex; align-items: center; gap: 6px;
    white-space: nowrap; font-weight: 600;
}

/* ============================================================
   CAPITAL SUMMARY
   ============================================================ */
.capital-summary-compact {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px; margin-bottom: 18px;
}
.capital-item-compact {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px; border-radius: 12px; color: #FFFFFF;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.15);
    position: relative; overflow: hidden; min-width: 0;
}
.capital-item-compact::before {
    content: ''; position: absolute;
    top: -50%; right: -20%;
    width: 140px; height: 140px;
    background: rgba(255,255,255,0.1); border-radius: 50%;
}
.capital-float { background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%); }
.capital-cash { background: linear-gradient(135deg, #059669 0%, #10b981 100%); }
.capital-total { background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%); }
.capital-icon-compact {
    width: 48px; height: 48px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    position: relative; z-index: 1;
    backdrop-filter: blur(8px);
    border: 1.5px solid rgba(255,255,255,0.25);
}
.capital-info-compact {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; flex: 1; position: relative; z-index: 1;
}
.capital-label-compact {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    opacity: 0.9;
}
.capital-value-compact {
    font-size: clamp(15px, 1.4vw, 20px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 0.3px; line-height: 1.15;
    word-break: break-all; overflow-wrap: anywhere;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px; font-weight: 800; margin: 0;
    color: var(--text-primary);
}
.page-header .header-left h2 i {
    color: var(--blue-primary);
    margin-right: 10px;
}
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0; font-weight: 500;
}
.page-header .header-right {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn-new-transfer {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF;
    padding: 11px 24px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.35);
    white-space: nowrap;
    letter-spacing: 0.3px;
}
.btn-new-transfer:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(30, 64, 175, 0.5);
}
.btn-new-transfer.active {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-new-transfer.active:hover {
    box-shadow: 0 6px 22px rgba(220, 38, 38, 0.5);
}
.btn-new-transfer i {
    transition: transform 0.3s ease;
    font-size: 15px;
}
.btn-new-transfer.active i {
    transform: rotate(135deg);
}

.btn-back {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-back:hover {
    background: var(--blue-lightest);
    color: var(--blue-primary);
    border-color: var(--blue-light);
    transform: translateY(-2px);
}

.btn-filter {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF;
    padding: 9px 20px;
    border: none; border-radius: 8px;
    font-weight: 700; font-size: 12px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 3px 10px rgba(30, 64, 175, 0.25);
    white-space: nowrap;
}
.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(30, 64, 175, 0.4);
}

.btn-reset {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    padding: 9px 18px;
    border-radius: 8px;
    font-weight: 600; font-size: 12px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-reset:hover {
    background: var(--blue-lightest);
    color: var(--blue-primary);
    border-color: var(--blue-light);
}

.btn-transfer {
    padding: 12px 26px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 4px 14px rgba(30, 64, 175, 0.35);
    letter-spacing: 0.3px;
}
.btn-transfer:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(30, 64, 175, 0.5);
}
.btn-transfer:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.alert-warning { background: #fef3c7; color: #78350f; border: 1px solid #fde68a; }
html.dark-mode .alert-success { background: #065f46; color: #d1fae5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7f1d1d; color: #fee2e2; border-color: #991b1b; }
html.dark-mode .alert-warning { background: #5f3a1e; color: #fde68a; border-color: #92400e; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert a { color: inherit; text-decoration: underline; font-weight: 700; }
.alert-close {
    background: transparent; border: none;
    font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6;
}
.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   TRANSFER FORM CONTAINER - COLLAPSIBLE
   ============================================================ */
.transfer-form-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 4px 16px var(--shadow-color);
    animation: slideDown 0.3s ease forwards;
}
.transfer-form-container.hidden {
    display: none !important;
}
.transfer-form-header {
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
    padding: 18px 26px;
    display: flex; justify-content: space-between; align-items: center;
    color: #FFFFFF; gap: 12px; flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.transfer-form-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.transfer-form-header-left {
    display: flex; align-items: center; gap: 14px;
    position: relative; z-index: 1;
}
.transfer-form-header-left > i {
    font-size: 24px; width: 48px; height: 48px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    border: 1.5px solid rgba(255,255,255,0.3);
    backdrop-filter: blur(8px);
    color: #FFFFFF;
}
.transfer-form-header h3 {
    font-size: 17px; font-weight: 800;
    margin: 0 0 2px 0; color: #FFFFFF;
    letter-spacing: 0.3px;
}
.transfer-form-header p {
    font-size: 12px; margin: 0;
    color: rgba(255,255,255,0.9); font-weight: 500;
}
.btn-toggle-transfer {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: rgba(255,255,255,0.2);
    color: #FFFFFF;
    border: 1.5px solid rgba(255,255,255,0.3);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; transition: all 0.2s ease;
    position: relative; z-index: 1;
}
.btn-toggle-transfer:hover {
    background: rgba(255,255,255,0.35);
    transform: scale(1.05);
}
.transfer-form { padding: 26px; }

/* ============================================================
   TRANSFER TYPE SELECTOR
   ============================================================ */
.transfer-type-selector {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 14px; margin-bottom: 22px;
}
.transfer-type-option { cursor: pointer; position: relative; }
.transfer-type-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.transfer-type-card {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 20px; border-radius: 12px;
    border: 2px solid var(--border-color);
    background: var(--bg-input);
    transition: all 0.3s ease; min-width: 0;
}
.transfer-type-card:hover {
    border-color: var(--blue-light);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-color);
}
.transfer-type-option input[type="radio"]:checked + .transfer-type-card {
    border-color: var(--blue-primary);
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.25);
}
html.dark-mode .transfer-type-option input[type="radio"]:checked + .transfer-type-card {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
}
.type-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.type-cash-to-float .type-icon {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #059669;
}
.type-float-to-cash .type-icon {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #d97706;
}
html.dark-mode .type-cash-to-float .type-icon { background: #065f46; color: #34d399; }
html.dark-mode .type-float-to-cash .type-icon { background: #5f3a1e; color: #fbbf24; }
.type-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.type-title { font-size: 15px; font-weight: 800; color: var(--text-primary); }
.type-desc { font-size: 11px; font-weight: 500; color: var(--text-muted); }

/* ============================================================
   PROVIDER SELECTION
   ============================================================ */
.provider-select-section { margin-bottom: 22px; }
.provider-select-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 10px;
}
.provider-select-label i { color: var(--blue-primary); font-size: 14px; }
.provider-select-label .required { color: #dc2626; }

.custom-provider-dropdown { position: relative; width: 100%; }
.provider-dropdown-trigger {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 16px 20px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    cursor: pointer; transition: all 0.3s ease;
    min-height: 64px;
}
.provider-dropdown-trigger:hover {
    border-color: var(--blue-light);
    background: var(--bg-card);
}
.custom-provider-dropdown.open .provider-dropdown-trigger {
    border-color: var(--blue-primary);
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.15);
    background: var(--bg-card);
}
.provider-dropdown-trigger-content { flex: 1; min-width: 0; }
.provider-placeholder {
    display: flex; align-items: center; gap: 10px;
    color: var(--text-muted); font-size: 14px; font-weight: 500;
}
.provider-placeholder i { font-size: 18px; color: var(--blue-primary); }

.selected-provider-display { display: flex; align-items: center; gap: 14px; min-width: 0; }
.selected-provider-icon {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 18px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.selected-provider-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.selected-provider-name {
    font-size: 15px; font-weight: 800; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.selected-provider-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.selected-provider-code {
    font-size: 11px; font-weight: 700;
    color: var(--blue-primary); background: #dbeafe;
    padding: 3px 10px; border-radius: 8px;
    font-family: 'Courier New', monospace;
}
html.dark-mode .selected-provider-code { background: #1e3a5f; color: #93c5fd; }
.selected-provider-float {
    font-size: 11px; font-weight: 700;
    color: #059669; display: flex; align-items: center; gap: 4px;
}
html.dark-mode .selected-provider-float { color: #34d399; }

.provider-dropdown-arrow {
    font-size: 14px; color: var(--text-muted);
    transition: transform 0.3s ease; flex-shrink: 0;
}
.custom-provider-dropdown.open .provider-dropdown-arrow {
    transform: rotate(180deg); color: var(--blue-primary);
}
.provider-dropdown-menu {
    position: absolute; top: calc(100% + 6px);
    left: 0; right: 0;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(30, 64, 175, 0.15);
    z-index: 100; max-height: 480px;
    overflow: hidden; display: none;
    animation: dropIn 0.2s ease forwards;
}
.custom-provider-dropdown.open .provider-dropdown-menu { display: block; }
@keyframes dropIn {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.provider-dropdown-search {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 18px;
    border-bottom: 1.5px solid var(--border-color);
    background: var(--bg-input);
    position: sticky; top: 0; z-index: 2;
}
.provider-dropdown-search i { font-size: 13px; color: var(--blue-primary); flex-shrink: 0; }
.provider-dropdown-search input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 13px;
    color: var(--text-primary); outline: none;
    font-family: 'Inter', sans-serif; min-width: 0;
}
.provider-dropdown-search input::placeholder { color: var(--text-light); font-size: 12px; }
.provider-dropdown-list { max-height: 400px; overflow-y: auto; padding: 14px; }
.provider-dropdown-list::-webkit-scrollbar { width: 6px; }
.provider-dropdown-list::-webkit-scrollbar-thumb {
    background: var(--blue-accent); border-radius: 3px;
}
.provider-dropdown-list::-webkit-scrollbar-thumb:hover { background: var(--blue-primary); }
.provider-cards-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
}
.provider-card-item {
    position: relative;
    display: flex; flex-direction: column;
    align-items: center; gap: 6px;
    padding: 14px 10px 12px 10px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    cursor: pointer; transition: all 0.25s ease;
    text-align: center; min-width: 0;
}
.provider-card-item:hover {
    border-color: var(--blue-primary);
    background: var(--bg-card);
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.15);
}
.provider-card-item.selected {
    border-color: var(--blue-primary);
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    box-shadow: 0 6px 20px rgba(30, 64, 175, 0.25);
}
html.dark-mode .provider-card-item.selected {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
}
.provider-card-item.hidden-by-filter { display: none !important; }
.provider-card-check {
    position: absolute; top: 6px; right: 6px;
    width: 22px; height: 22px;
    background: var(--blue-primary); color: #FFFFFF;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 11px; opacity: 0; transform: scale(0.5);
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(30, 64, 175, 0.4);
}
.provider-card-item.selected .provider-card-check { opacity: 1; transform: scale(1); }
.provider-card-icon {
    width: 48px; height: 48px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 20px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
    transition: all 0.25s ease;
}
.provider-card-item:hover .provider-card-icon { transform: scale(1.08); }
.provider-card-name {
    font-size: 12px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    width: 100%; line-height: 1.2;
}
.provider-card-code {
    font-size: 9px; font-weight: 700;
    color: var(--blue-primary); background: #dbeafe;
    padding: 2px 8px; border-radius: 6px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .provider-card-code { background: #1e3a5f; color: #93c5fd; }
.provider-card-float {
    display: flex; flex-direction: column;
    align-items: center; gap: 2px;
    width: 100%; padding: 6px 8px;
    background: #ecfdf5; border: 1px solid #a7f3d0;
    border-radius: 8px; margin-top: 2px;
}
html.dark-mode .provider-card-float { background: #065f46; border-color: #10b981; }
.provider-float-label {
    font-size: 8px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    color: #059669;
    display: flex; align-items: center; gap: 3px; white-space: nowrap;
}
html.dark-mode .provider-float-label { color: #34d399; }
.provider-float-value {
    font-size: 12px; font-weight: 900;
    color: #047857;
    font-family: 'Inter', 'Courier New', monospace;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 100%;
}
html.dark-mode .provider-float-value { color: #6ee7b7; }
.provider-card-type {
    font-size: 8px; font-weight: 600;
    color: var(--text-muted);
    display: flex; align-items: center; gap: 3px;
    text-transform: uppercase; letter-spacing: 0.3px;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 100%;
}
.provider-dropdown-empty {
    padding: 30px 20px; text-align: center;
    color: var(--text-muted);
}
.provider-dropdown-empty i {
    font-size: 32px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 10px;
}
.provider-dropdown-empty p { margin: 0; font-size: 13px; }

/* ============================================================
   FORM ROWS
   ============================================================ */
.transfer-form-row {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 16px; margin-bottom: 16px;
}
.transfer-form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.transfer-form-group label {
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.transfer-form-group label .required { color: #dc2626; }
.transfer-form-control {
    padding: 12px 16px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%;
}
.transfer-form-control:focus {
    outline: none;
    border-color: var(--blue-primary);
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.15);
    background: var(--bg-card);
}
.transfer-amount-input {
    font-size: 20px !important;
    font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 1px;
    text-align: right;
}

/* ============================================================
   TRANSFER PREVIEW - BLUE THEME
   ============================================================ */
.transfer-preview {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 2px solid #93c5fd;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 18px;
    animation: slideDown 0.3s ease forwards;
}
html.dark-mode .transfer-preview {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
    border-color: #3b82f6;
}
.preview-header {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 800;
    color: var(--blue-primary);
    text-transform: uppercase; letter-spacing: 1px;
    margin-bottom: 14px;
}
html.dark-mode .preview-header { color: #93c5fd; }
.preview-header i { color: var(--blue-primary); }
.preview-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}
.preview-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 12px 14px;
    background: rgba(255,255,255,0.85);
    border-radius: 10px;
    border: 1px solid rgba(147, 197, 253, 0.5);
    min-width: 0;
}
html.dark-mode .preview-item {
    background: rgba(15, 23, 42, 0.5);
    border-color: rgba(59, 130, 246, 0.4);
}
.preview-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: var(--blue-primary);
}
html.dark-mode .preview-label { color: #93c5fd; }
.preview-value {
    font-size: clamp(12px, 1.1vw, 15px);
    font-weight: 900;
    color: #1e293b;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all; overflow-wrap: anywhere;
    line-height: 1.2;
}
html.dark-mode .preview-value { color: #f1f5f9; }
.preview-float { color: #1d4ed8; }
.preview-cash { color: #059669; }
.preview-after { color: #7c3aed; }
html.dark-mode .preview-float { color: #60a5fa; }
html.dark-mode .preview-cash { color: #34d399; }
html.dark-mode .preview-after { color: #a78bfa; }

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.transfer-form-actions {
    display: flex; gap: 12px; padding-top: 10px; flex-wrap: wrap;
}

/* ============================================================
   SUMMARY CARDS
   ============================================================ */
.transfer-summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 18px;
}
.transfer-summary-card {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 22px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease; min-width: 0;
}
.transfer-summary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px var(--shadow-hover);
    border-color: var(--blue-light);
}
.ts-icon {
    width: 50px; height: 50px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.ts-icon-blue {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    border: 1.5px solid #93c5fd;
}
.ts-icon-green {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #059669;
    border: 1.5px solid #6ee7b7;
}
.ts-icon-cyan {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
    color: #0891b2;
    border: 1.5px solid #67e8f9;
}
.ts-icon-orange {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #d97706;
    border: 1.5px solid #fcd34d;
}
html.dark-mode .ts-icon-blue { background: #1e3a5f; color: #60a5fa; border-color: #3b82f6; }
html.dark-mode .ts-icon-green { background: #065f46; color: #34d399; border-color: #10b981; }
html.dark-mode .ts-icon-cyan { background: #164e63; color: #67e8f9; border-color: #06b6d4; }
html.dark-mode .ts-icon-orange { background: #5f3a1e; color: #fbbf24; border-color: #d97706; }
.ts-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.ts-label {
    font-size: 11px; text-transform: uppercase;
    letter-spacing: 0.7px; font-weight: 700;
    color: var(--text-muted);
}
.ts-value {
    font-size: 19px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all;
    overflow-wrap: anywhere;
    line-height: 1.2;
}

/* ============================================================
   FILTERS
   ============================================================ */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px; max-width: 100%;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.filters-form { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label {
    font-size: 11px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-control {
    padding: 9px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    min-width: 150px;
    font-family: 'Inter', sans-serif;
}
.form-control:focus {
    outline: none;
    border-color: var(--blue-primary);
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    background: var(--bg-card);
}

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
    width: 100%;
}

/* RED TABLE HEADER */
.table-red-header {
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    padding: 18px 24px;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 10px; color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.table-red-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.table-red-header-left {
    display: flex; align-items: center; gap: 12px;
    position: relative; z-index: 1;
}
.table-red-header-left i {
    font-size: 20px; color: #FFFFFF;
    background: rgba(255,255,255,0.18);
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255,255,255,0.25);
}
.table-red-header h3 {
    font-size: 17px; font-weight: 800;
    margin: 0; color: #FFFFFF;
    letter-spacing: 0.3px;
}
.count-badge {
    background: rgba(255,255,255,0.22);
    color: #FFFFFF;
    padding: 5px 16px;
    border-radius: 12px;
    font-size: 12px; font-weight: 800;
    border: 1px solid rgba(255,255,255,0.3);
}

.table-wrapper {
    overflow-x: auto;
    overflow-y: hidden;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
}
.table-wrapper::-webkit-scrollbar { height: 8px; }
.table-wrapper::-webkit-scrollbar-track { background: var(--bg-table-even); border-radius: 4px; }
.table-wrapper::-webkit-scrollbar-thumb { background: #bb0404; border-radius: 4px; }
.table-wrapper::-webkit-scrollbar-thumb:hover { background: #8a0303; }

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 1300px;
}

.table-search-row { background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%) !important; }
.table-search-cell {
    padding: 12px 14px !important;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%) !important;
    border-bottom: none !important;
}
.table-search-row-content {
    display: flex; align-items: center; gap: 12px;
    width: 100%; padding: 2px 0; flex-wrap: nowrap;
}
.table-search-wrapper {
    position: relative;
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 8px;
    padding: 6px 12px;
    width: 300px; flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
html.dark-mode .table-search-wrapper { background: rgba(30, 41, 59, 0.95); }
.table-search-icon { color: #bb0404; font-size: 12px; flex-shrink: 0; }
.table-search-input {
    flex: 1; border: none; background: transparent;
    padding: 4px 2px; font-size: 12px;
    font-family: 'Inter', sans-serif; color: #1f2937;
    outline: none; min-width: 0;
}
html.dark-mode .table-search-input { color: #f9fafb; }
.table-search-input::placeholder { color: #9ca3af; font-size: 11px; }
.table-search-clear {
    width: 18px; height: 18px; border-radius: 50%;
    background: #fee2e2; color: #dc2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px; transition: all 0.2s ease;
    flex-shrink: 0;
}
.table-search-clear:hover { background: #dc2626; color: #FFFFFF; }
.table-search-count {
    font-size: 10px; font-weight: 700;
    padding: 2px 8px;
    background: #f59e0b; color: #FFFFFF;
    border-radius: 8px; white-space: nowrap;
    flex-shrink: 0;
}
.table-scroll-center {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; flex: 1; min-width: 0; padding: 0 8px;
}
.table-search-spacer { width: 300px; flex-shrink: 0; }
.scroll-label {
    font-size: 11px; font-weight: 800;
    color: #fcd34d;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.scroll-label i { font-size: 11px; color: #fcd34d; }
.scroll-btn {
    width: 38px; height: 38px;
    border-radius: 8px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #bb0404;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 15px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0; padding: 0; line-height: 1;
}
.scroll-btn:hover {
    background: #fcd34d; color: #78350f;
    border-color: #fcd34d;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.scroll-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}
.scroll-btn i { font-size: 14px; display: block; line-height: 1; }

.data-table thead tr:not(.table-search-row) { background: var(--bg-table-even); }
.data-table thead th:not(.table-search-cell) {
    padding: 12px 14px; text-align: left;
    font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
}
.data-table thead th.text-right { text-align: right; }

.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.data-table tbody tr.hidden-by-search { display: none !important; }
.data-table tbody td {
    padding: 12px 14px; color: var(--text-primary);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--bg-table-hover);
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.transfer-number {
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 700;
    color: var(--blue-primary); background: #dbeafe;
    padding: 4px 10px; border-radius: 8px;
    white-space: nowrap;
}
html.dark-mode .transfer-number { background: #1e3a5f; color: #93c5fd; }

.type-badge-txn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px; border-radius: 8px;
    font-size: 11px; font-weight: 700;
    white-space: nowrap;
}
.type-badge-txn.type-cash-to-float {
    background: #d1fae5; color: #059669; border: 1px solid #a7f3d0;
}
.type-badge-txn.type-float-to-cash {
    background: #fef3c7; color: #d97706; border: 1px solid #fde68a;
}
html.dark-mode .type-badge-txn.type-cash-to-float {
    background: #065f46; color: #34d399; border-color: #10b981;
}
html.dark-mode .type-badge-txn.type-float-to-cash {
    background: #5f3a1e; color: #fbbf24; border-color: #d97706;
}

.date-cell {
    font-size: 11px; font-weight: 600;
    color: var(--text-secondary);
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.time-cell {
    font-size: 10px; color: var(--text-light);
    margin-left: 4px; font-weight: 600;
}
.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 12px; flex-shrink: 0;
}
.code-badge {
    display: inline-block; padding: 3px 10px;
    background: #dbeafe; color: #1d4ed8;
    border-radius: 8px; font-size: 10px;
    font-weight: 700; font-family: 'Courier New', monospace;
    letter-spacing: 0.5px; white-space: nowrap;
}
html.dark-mode .code-badge { background: #1e3a5f; color: #60a5fa; }

.amount-transfer {
    display: inline-block; padding: 5px 14px;
    border-radius: 8px; font-weight: 800;
    font-size: 13px; font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.amount-transfer.type-cash-to-float {
    background: #d1fae5; color: #059669; border: 1px solid #a7f3d0;
}
.amount-transfer.type-float-to-cash {
    background: #fef3c7; color: #d97706; border: 1px solid #fde68a;
}
html.dark-mode .amount-transfer.type-cash-to-float {
    background: #14532d; color: #4ade80; border-color: #16a34a;
}
html.dark-mode .amount-transfer.type-float-to-cash {
    background: #5f3a1e; color: #fbbf24; border-color: #d97706;
}

.float-change {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-family: 'Courier New', monospace;
    white-space: nowrap;
}
.float-before { color: #64748b; font-weight: 600; }
.float-after { color: var(--blue-primary); font-weight: 800; }
html.dark-mode .float-before { color: #94a3b8; }
html.dark-mode .float-after { color: #60a5fa; }
.float-change i { color: var(--blue-primary); font-size: 9px; }

.employee-cell-full {
    display: flex; align-items: center; gap: 10px;
    min-width: 0;
}
.employee-avatar-img {
    width: 36px; height: 36px;
    border-radius: 50%; object-fit: cover;
    border: 2px solid var(--blue-primary);
    flex-shrink: 0; background: #f3f4f6;
}
.employee-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
    color: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 14px;
    flex-shrink: 0;
    border: 2px solid #93c5fd;
    box-shadow: 0 2px 8px rgba(30, 64, 175, 0.25);
}
.employee-name-details {
    display: flex; flex-direction: column; gap: 2px;
    min-width: 0;
}
.employee-name-full {
    font-size: 12px; font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 160px;
}
.employee-code {
    font-size: 10px; font-weight: 600;
    color: var(--blue-primary); font-family: 'Courier New', monospace;
    background: #dbeafe;
    padding: 1px 8px; border-radius: 6px;
    align-self: flex-start;
}
html.dark-mode .employee-code { background: #1e3a5f; color: #93c5fd; }

.reference-cell {
    font-family: 'Courier New', monospace;
    font-size: 11px; color: var(--text-secondary);
    white-space: nowrap;
}

.transfer-actions {
    display: flex; gap: 4px;
    justify-content: center; flex-wrap: nowrap;
}
.btn-action-txn {
    padding: 6px 10px; border-radius: 6px; border: none;
    display: inline-flex; align-items: center; justify-content: center;
    gap: 4px; cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 11px; font-weight: 700;
    white-space: nowrap;
    font-family: 'Inter', sans-serif;
}
.btn-action-txn span { font-size: 11px; font-weight: 700; display: inline-block; }

.btn-view-txn { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
.btn-view-txn:hover { background: #1d4ed8; color: #FFFFFF; border-color: #1d4ed8; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3); }
.btn-edit-txn { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.btn-edit-txn:hover { background: #d97706; color: #FFFFFF; border-color: #d97706; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3); }
.btn-delete-txn { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.btn-delete-txn:hover { background: #991b1b; color: #FFFFFF; border-color: #991b1b; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(153, 27, 27, 0.3); }

html.dark-mode .btn-view-txn { background: #1e3a5f; color: #60a5fa; border-color: #3b82f6; }
html.dark-mode .btn-view-txn:hover { background: #2563eb; color: #FFFFFF; }
html.dark-mode .btn-edit-txn { background: #5f3a1e; color: #fbbf24; border-color: #92400e; }
html.dark-mode .btn-edit-txn:hover { background: #d97706; color: #FFFFFF; }
html.dark-mode .btn-delete-txn { background: #7f1d1d; color: #fca5a5; border-color: #dc2626; }
html.dark-mode .btn-delete-txn:hover { background: #991b1b; color: #FFFFFF; }

.empty-state {
    text-align: center; padding: 60px 20px;
}
.empty-state i {
    font-size: 56px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 18px; color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p {
    color: var(--text-muted); font-size: 14px; margin: 0;
}
.no-results {
    text-align: center; padding: 40px 20px;
    background: var(--bg-table-even);
    border-top: 1px solid var(--border-color);
}
.no-results i {
    font-size: 42px; color: var(--text-light);
    opacity: 0.4; display: block; margin-bottom: 10px;
}
.no-results p {
    font-size: 13px; color: var(--text-muted);
    margin: 0 0 12px 0;
}

.btn-sm { padding: 6px 14px; font-size: 11px; }
.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    padding: 6px 14px; border-radius: 6px;
    font-weight: 600; cursor: pointer;
    font-size: 11px;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 5px;
    font-family: 'Inter', sans-serif;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1400px) {
    .table-search-wrapper { width: 260px; }
    .table-search-spacer { width: 260px; }
}
@media (max-width: 1200px) {
    .table-search-wrapper { width: 220px; }
    .table-search-spacer { width: 220px; }
    .table-search-input { font-size: 11px; }
    .provider-cards-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1024px) {
    .transfer-summary-cards { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
    .transfer-type-selector { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
    .table-search-row-content {
        flex-wrap: wrap;
        justify-content: center;
    }
    .table-search-wrapper { 
        width: 100%; max-width: 300px; order: 1;
    }
    .table-search-spacer { display: none; }
    .table-scroll-center {
        order: 2; width: 100%; justify-content: center;
    }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-right { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn-new-transfer,
    .page-header .header-right .btn-back {
        flex: 1; justify-content: center;
    }
    .capital-summary-compact { grid-template-columns: 1fr; gap: 10px; }
    .transfer-form-row { grid-template-columns: 1fr; }
    .preview-grid { grid-template-columns: 1fr; }
    .transfer-summary-cards { grid-template-columns: 1fr; }
    .transfer-form-actions { flex-direction: column; }
    .transfer-form-actions .btn-transfer,
    .transfer-form-actions .btn-reset {
        width: 100%; justify-content: center;
    }
    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .transfer-form { padding: 18px; }
    .table-search-row-content {
        flex-direction: column; align-items: stretch; gap: 10px;
    }
    .table-search-wrapper { width: 100%; max-width: 100%; }
    .table-search-spacer { display: none; }
    .table-scroll-center { width: 100%; }
    .transfer-actions { flex-direction: column; gap: 3px; }
    .btn-action-txn { padding: 5px 8px; font-size: 10px; }
    .btn-action-txn span { font-size: 10px; }
    .provider-cards-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .capital-item-compact { padding: 12px 14px; gap: 10px; }
    .capital-icon-compact { width: 40px; height: 40px; font-size: 16px; }
    .capital-value-compact { font-size: 14px; }
    .transfer-type-card { padding: 14px 16px; gap: 12px; }
    .type-icon { width: 40px; height: 40px; font-size: 17px; }
    .type-title { font-size: 13px; }
    .type-desc { font-size: 10px; }
    .scroll-btn { width: 34px; height: 34px; font-size: 13px; }
    .scroll-label { font-size: 9px; }
    .employee-name-full { max-width: 100px; }
    .provider-cards-grid { grid-template-columns: 1fr; }
    .provider-card-item { padding: 12px 10px; }
}
</style>

<script>
// ============================================================
// MONEY FORMAT
// ============================================================
function formatMoneyInput(input) {
    const cursorPos = input.selectionStart;
    const oldLength = input.value.length;
    let value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) { value = value.substring(0, 15); }
    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) { formatted = ',' + formatted; }
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    const newCursorPos = cursorPos + (formatted.length - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) {}
}
function formatMoney(num) {
    return 'TSh ' + Number(num).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}
function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

// ============================================================
// FORM TOGGLE
// ============================================================
function toggleTransferForm() {
    var container = document.getElementById('transferFormContainer');
    var icon = document.getElementById('newTransferIcon');
    var label = document.getElementById('newTransferLabel');
    var btn = document.getElementById('newTransferBtn');
    var toggleIcon = document.getElementById('transferToggleIcon');
    
    if (!container) return;
    
    var isHidden = container.classList.contains('hidden');
    
    if (isHidden) {
        container.classList.remove('hidden');
        if (btn) btn.classList.add('active');
        if (icon) {
            icon.classList.remove('fa-plus-circle');
            icon.classList.add('fa-times-circle');
        }
        if (label) label.textContent = 'Close Form';
        if (toggleIcon) {
            toggleIcon.classList.remove('fa-chevron-up');
            toggleIcon.classList.add('fa-times');
        }
        setTimeout(function() {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    } else {
        container.classList.add('hidden');
        if (btn) btn.classList.remove('active');
        if (icon) {
            icon.classList.remove('fa-times-circle');
            icon.classList.add('fa-plus-circle');
        }
        if (label) label.textContent = 'New Transfer';
        if (typeof resetTransfer === 'function') resetTransfer();
    }
}

// ============================================================
// PROVIDER DROPDOWN
// ============================================================
let currentProviderData = { id: 0, float: 0, name: '', code: '', color: '', icon: '' };
let currentBranchCash = <?php echo floatval($current_cash); ?>;

function toggleProviderDropdown() {
    const dropdown = document.getElementById('customProviderDropdown');
    dropdown.classList.toggle('open');
    if (dropdown.classList.contains('open')) {
        setTimeout(() => {
            document.getElementById('providerSearchInput').focus();
        }, 100);
    }
}

function selectProvider(element) {
    const providerId = element.getAttribute('data-provider-id');
    const providerName = element.getAttribute('data-name');
    const providerCode = element.getAttribute('data-code');
    const providerFloat = parseFloat(element.getAttribute('data-float')) || 0;
    const color = element.getAttribute('data-color');
    const icon = element.getAttribute('data-icon');
    
    currentProviderData = {
        id: providerId, float: providerFloat, name: providerName,
        code: providerCode, color: color, icon: icon
    };
    
    document.getElementById('hiddenProviderId').value = providerId;
    
    const triggerContent = document.getElementById('providerTriggerContent');
    triggerContent.innerHTML = `
        <div class="selected-provider-display">
            <div class="selected-provider-icon" style="background: ${color};">
                <i class="${icon}"></i>
            </div>
            <div class="selected-provider-info">
                <span class="selected-provider-name">${providerName}</span>
                <div class="selected-provider-meta">
                    <span class="selected-provider-code">${providerCode}</span>
                    <span class="selected-provider-float">
                        <i class="fas fa-coins"></i>
                        Float: ${formatMoney(providerFloat)}
                    </span>
                </div>
            </div>
        </div>
    `;
    
    document.querySelectorAll('.provider-card-item').forEach(item => item.classList.remove('selected'));
    element.classList.add('selected');
    document.getElementById('customProviderDropdown').classList.remove('open');
    
    document.getElementById('previewProvider').textContent = providerName + ' (' + providerCode + ')';
    document.getElementById('previewCurrentFloat').textContent = formatMoney(providerFloat);
    document.getElementById('previewCurrentCash').textContent = formatMoney(currentBranchCash);
    document.getElementById('transferPreview').style.display = 'block';
    
    updatePreview();
}

function filterProviders(searchTerm) {
    const term = searchTerm.toLowerCase().trim();
    const items = document.querySelectorAll('.provider-card-item');
    const empty = document.getElementById('providerDropdownEmpty');
    let visibleCount = 0;
    items.forEach(item => {
        const name = item.getAttribute('data-provider-name') || '';
        const code = item.getAttribute('data-provider-code') || '';
        if (term === '' || name.includes(term) || code.includes(term)) {
            item.classList.remove('hidden-by-filter');
            visibleCount++;
        } else {
            item.classList.add('hidden-by-filter');
        }
    });
    if (empty) empty.style.display = visibleCount === 0 ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('customProviderDropdown');
    if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.remove('open');
    }
});

// ============================================================
// LIVE PREVIEW
// ============================================================
function updatePreview() {
    const amount = parseMoney(document.getElementById('transferAmount').value);
    const transferType = document.querySelector('input[name="transfer_type"]:checked').value;
    let afterFloat = currentProviderData.float;
    if (transferType === 'cash_to_float') {
        afterFloat = currentProviderData.float + amount;
    } else {
        afterFloat = currentProviderData.float - amount;
    }
    const afterEl = document.getElementById('previewAfter');
    afterEl.textContent = formatMoney(afterFloat);
    afterEl.style.color = (transferType === 'float_to_cash' && afterFloat < 0) ? '#dc2626' : '';
}
function updateTransferUI() { updatePreview(); }

// ============================================================
// VALIDATION
// ============================================================
function validateTransfer() {
    const providerId = document.getElementById('hiddenProviderId').value;
    const amount = parseMoney(document.getElementById('transferAmount').value);
    const transferType = document.querySelector('input[name="transfer_type"]:checked').value;
    
    if (!providerId || providerId === '0') {
        alert('Please select a provider.');
        return false;
    }
    if (amount <= 0) {
        alert('Please enter a valid amount.');
        return false;
    }
    if (transferType === 'cash_to_float' && amount > currentBranchCash) {
        alert('Insufficient cash!\n\nCash: ' + formatMoney(currentBranchCash) + '\nTrying: ' + formatMoney(amount));
        return false;
    }
    if (transferType === 'float_to_cash' && amount > currentProviderData.float) {
        alert('Insufficient provider float!\n\nFloat: ' + formatMoney(currentProviderData.float) + '\nTrying: ' + formatMoney(amount));
        return false;
    }
    
    const btn = document.getElementById('transferSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    return true;
}

function resetTransfer() {
    const preview = document.getElementById('transferPreview');
    if (preview) preview.style.display = 'none';
    const hidden = document.getElementById('hiddenProviderId');
    if (hidden) hidden.value = '';
    currentProviderData = { id: 0, float: 0, name: '', code: '', color: '', icon: '' };
    const triggerContent = document.getElementById('providerTriggerContent');
    if (triggerContent) {
        triggerContent.innerHTML = `
            <div class="provider-placeholder">
                <i class="fas fa-hand-pointer"></i>
                <span>Click to select a provider...</span>
            </div>
        `;
    }
    const searchInput = document.getElementById('providerSearchInput');
    if (searchInput) { searchInput.value = ''; filterProviders(''); }
    const amountInput = document.getElementById('transferAmount');
    if (amountInput) amountInput.value = '';
    document.querySelectorAll('.provider-card-item').forEach(item => item.classList.remove('selected'));
}

// ============================================================
// TABLE SEARCH
// ============================================================
function onTableSearch(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#transferTableBody .transfer-row');
    const clearBtn = document.getElementById('tableSearchClear');
    const countBadge = document.getElementById('tableSearchCount');
    const noResults = document.getElementById('noResults');
    
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

function clearTableSearch() {
    const input = document.getElementById('tableSearchInput');
    if (input) {
        input.value = '';
        onTableSearch(input);
        input.focus();
    }
}

// ============================================================
// TABLE SCROLL
// ============================================================
function scrollTable(direction) {
    const wrapper = document.getElementById('transferTableWrapper');
    if (!wrapper) return;
    const scrollAmount = 350;
    wrapper.scrollBy({ 
        left: direction === 'left' ? -scrollAmount : scrollAmount, 
        behavior: 'smooth' 
    });
}

// ============================================================
// DELETE
// ============================================================
function deleteTransfer(id, number) {
    if (confirm('Delete transfer "' + number + '"?\n\nThis action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}

// ============================================================
// KEYBOARD
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('customProviderDropdown');
        if (dropdown && dropdown.classList.contains('open')) {
            dropdown.classList.remove('open');
            return;
        }
        const focused = document.activeElement;
        if (focused && focused.classList.contains('table-search-input')) {
            clearTableSearch();
        }
    }
});

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 8000);
    }
    
    // Auto-open form if there was an error
    <?php if (!empty($error_message)): ?>
    var container = document.getElementById('transferFormContainer');
    if (container && container.classList.contains('hidden')) {
        toggleTransferForm();
    }
    <?php endif; ?>
    
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