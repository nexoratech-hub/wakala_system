<?php
// ================================================================
// FILE: modules/transfers/edit.php
// WAKALA FINANCIAL SYSTEM - ADMIN EDIT TRANSFER
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
// GET TRANSFER
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid transfer.';
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("
    SELECT t.*,
           p.icon_class, p.color_code, p.provider_type,
           b.branch_name AS branch_display_name,
           b.branch_code AS branch_display_code,
           e.full_name AS employee_name
    FROM transfers t
    LEFT JOIN providers p ON t.provider_id = p.id
    LEFT JOIN branches b ON t.branch_id = b.id
    LEFT JOIN employees e ON t.employee_id = e.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transfer) {
    $_SESSION['error_message'] = 'Transfer not found.';
    header('Location: index.php');
    exit();
}

$branch_id       = intval($transfer['branch_id']);
$branch_name     = $transfer['branch_display_name'] ?? 'N/A';
$branch_code     = $transfer['branch_display_code'] ?? '';
$old_provider_id = intval($transfer['provider_id']);
$old_type        = $transfer['transfer_type'];
$old_amount      = floatval($transfer['amount']);

// ============================================================
// LOAD BRANCH LOCATION
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);
$branch_location = $branch['location'] ?? '';

// ============================================================
// LATEST DAILY REPORT FOR THIS BRANCH
// ============================================================
$stmt = $db->prepare("
    SELECT * FROM daily_reports
    WHERE branch_id = ?
    ORDER BY report_date DESC, id DESC
    LIMIT 1
");
$stmt->execute([$branch_id]);
$latest_dr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$latest_dr) {
    $_SESSION['error_message'] = 'No daily report found for this branch.';
    header('Location: index.php?branch_id=' . $branch_id);
    exit();
}

$latest_dr_id = intval($latest_dr['id']);

// Current totals (before any edit)
$stmt = $db->prepare("
    SELECT COALESCE(SUM(current_float), 0)
    FROM daily_report_providers
    WHERE daily_report_id = ?
");
$stmt->execute([$latest_dr_id]);
$current_float_total = floatval($stmt->fetchColumn());
$current_cash        = floatval($latest_dr['current_cash'] ?? 0);
$current_capital     = $current_float_total + $current_cash;

// ============================================================
// PROVIDERS FOR THIS BRANCH (with current floats)
// ============================================================
$stmt = $db->prepare("
    SELECT p.id, p.provider_name, p.provider_code AS main_code,
           p.provider_type, p.icon_class, p.color_code,
           bp.provider_code AS branch_provider_code,
           COALESCE(drp.current_float, 0) AS current_float
    FROM providers p
    INNER JOIN branch_providers bp ON p.id = bp.provider_id
    LEFT JOIN daily_report_providers drp
           ON drp.provider_id = p.id AND drp.daily_report_id = ?
    WHERE bp.branch_id = ? AND bp.is_active = 1 AND p.is_active = 1
    ORDER BY p.display_order, p.provider_name
");
$stmt->execute([$latest_dr_id, $branch_id]);
$providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Find currently selected provider
$selected_provider = null;
foreach ($providers_list as $p) {
    if (intval($p['id']) === $old_provider_id) {
        $selected_provider = $p;
        break;
    }
}

// ============================================================
// HANDLE UPDATE
// ============================================================
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_transfer') {
    try {
        $db->beginTransaction();

        $new_type        = $_POST['transfer_type'] ?? 'cash_to_float';
        $new_provider_id = intval($_POST['provider_id'] ?? 0);
        $new_amount      = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
        $new_reference   = trim($_POST['reference_number'] ?? '');
        $new_description = trim($_POST['description'] ?? '');
        $new_date        = $_POST['transfer_date'] ?? date('Y-m-d');

        if (!in_array($new_type, ['cash_to_float', 'float_to_cash'])) {
            throw new Exception('Invalid transfer type.');
        }
        if ($new_provider_id <= 0) {
            throw new Exception('Please select a provider.');
        }
        if ($new_amount <= 0) {
            throw new Exception('Amount must be greater than 0.');
        }

        // ---- Load NEW provider (must belong to this branch) ----
        $stmt = $db->prepare("SELECT * FROM providers WHERE id = ? AND is_active = 1");
        $stmt->execute([$new_provider_id]);
        $new_provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$new_provider) throw new Exception('Provider not found.');

        $stmt = $db->prepare("SELECT * FROM branch_providers WHERE branch_id = ? AND provider_id = ? AND is_active = 1");
        $stmt->execute([$branch_id, $new_provider_id]);
        $new_bp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$new_bp) throw new Exception('Provider not assigned to this branch.');

        // ============================================================
        // STEP 1: REVERSE the OLD transfer effect on OLD provider
        // ============================================================
        $stmt = $db->prepare("
            SELECT * FROM daily_report_providers
            WHERE daily_report_id = ? AND provider_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$latest_dr_id, $old_provider_id]);
        $old_drp = $stmt->fetch(PDO::FETCH_ASSOC);

        $reversed_cash = $current_cash; // branch cash before this transfer existed
        $old_float = $old_drp ? floatval($old_drp['current_float']) : 0;

        if ($old_type === 'cash_to_float') {
            // We had moved cash -> float. Reverse: float -= amount, cash += amount
            $reversed_old_float = $old_float - $old_amount;
            $reversed_cash      = $current_cash + $old_amount;
        } else {
            // We had moved float -> cash. Reverse: float += amount, cash -= amount
            $reversed_old_float = $old_float + $old_amount;
            $reversed_cash      = $current_cash - $old_amount;
        }

        if ($reversed_old_float < 0) {
            throw new Exception(
                'Cannot reverse old transfer — old provider float would go negative. ' .
                'Current float: ' . number_format($old_float) .
                ', Old amount: ' . number_format($old_amount)
            );
        }
        if ($reversed_cash < 0) {
            throw new Exception(
                'Cannot reverse old transfer — branch cash would go negative. ' .
                'Current cash: ' . number_format($current_cash) .
                ', Old amount: ' . number_format($old_amount)
            );
        }

        // Write reversed float back to old provider
        if ($old_drp) {
            $stmt = $db->prepare("
                UPDATE daily_report_providers
                SET current_float = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reversed_old_float, $old_drp['id']]);
        }

        // If new provider == old provider, use reversed value as starting float
        if ($new_provider_id === $old_provider_id) {
            $new_provider_float = $reversed_old_float;
            $new_drp = $old_drp;
        } else {
            $stmt = $db->prepare("
                SELECT * FROM daily_report_providers
                WHERE daily_report_id = ? AND provider_id = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$latest_dr_id, $new_provider_id]);
            $new_drp = $stmt->fetch(PDO::FETCH_ASSOC);
            $new_provider_float = $new_drp ? floatval($new_drp['current_float']) : 0;
        }

        // ============================================================
        // STEP 2: APPLY the NEW transfer effect on NEW provider
        // ============================================================
        if ($new_type === 'cash_to_float') {
            if ($reversed_cash < $new_amount) {
                throw new Exception(
                    'Insufficient cash after reversing the old transfer. ' .
                    'Available: TSh ' . number_format($reversed_cash)
                );
            }
            $final_float = $new_provider_float + $new_amount;
            $final_cash  = $reversed_cash - $new_amount;
        } else {
            if ($new_provider_float < $new_amount) {
                throw new Exception(
                    'Insufficient provider float after reversing the old transfer. ' .
                    'Available: TSh ' . number_format($new_provider_float)
                );
            }
            $final_float = $new_provider_float - $new_amount;
            $final_cash  = $reversed_cash + $new_amount;
        }

        // Write new float back to new provider
        if ($new_drp) {
            $stmt = $db->prepare("
                UPDATE daily_report_providers
                SET current_float = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$final_float, $new_drp['id']]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO daily_report_providers
                (daily_report_id, provider_id, provider_code, provider_name,
                 morning_float, morning_cash, current_float, current_cash,
                 total_deposits, total_withdrawals, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, 0, NOW())
            ");
            $stmt->execute([
                $latest_dr_id, $new_provider_id,
                $new_bp['provider_code'], $new_provider['provider_name'],
                $new_provider_float, $final_float
            ]);
        }

        // ============================================================
        // STEP 3: Recompute total float from DB (multi-provider safe)
        // ============================================================
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(current_float), 0)
            FROM daily_report_providers
            WHERE daily_report_id = ?
        ");
        $stmt->execute([$latest_dr_id]);
        $final_total_float = floatval($stmt->fetchColumn());
        $final_capital     = $final_total_float + $final_cash;

        // ============================================================
        // STEP 4: Update transfer row
        // ============================================================
        $stmt = $db->prepare("
            UPDATE transfers SET
                transfer_type = ?,
                provider_id = ?,
                provider_code = ?,
                provider_name = ?,
                amount = ?,
                before_float = ?,
                after_float = ?,
                before_cash = ?,
                after_cash = ?,
                reference_number = ?,
                description = ?,
                transfer_date = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $new_type,
            $new_provider_id,
            $new_bp['provider_code'],
            $new_provider['provider_name'],
            $new_amount,
            $new_provider_float,   // before (on the new provider)
            $final_float,          // after
            $reversed_cash,        // before (branch cash)
            $final_cash,           // after
            $new_reference,
            $new_description,
            $new_date,
            $id
        ]);

        // ============================================================
        // STEP 5: Update daily_reports cash + capital
        // ============================================================
        $stmt = $db->prepare("
            UPDATE daily_reports
            SET current_cash = ?, current_capital = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$final_cash, $final_capital, $latest_dr_id]);

        // Log
        logActivity(
            $user_id,
            'Edit Transfer',
            'Transfers',
            $id,
            $transfer['transfer_number'],
            'Updated transfer ' . $transfer['transfer_number'] .
            ' to ' . ucfirst(str_replace('_', ' ', $new_type)) .
            ' of TSh ' . number_format($new_amount) .
            ' on ' . $new_provider['provider_name']
        );

        $db->commit();

        $_SESSION['success_message'] = 'Transfer ' . $transfer['transfer_number'] . ' updated successfully!';
        header('Location: view.php?id=' . $id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// ============================================================
// DEFAULTS FOR THE FORM
// ============================================================
$form_provider_id = $old_provider_id;
$form_type        = $old_type;
$form_amount      = $old_amount;
$form_reference   = $transfer['reference_number'] ?? '';
$form_description = $transfer['description'] ?? '';
$form_date        = $transfer['transfer_date'] ?? date('Y-m-d');

// If POST failed, keep user's submitted values
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($error_message)) {
    $form_provider_id = intval($_POST['provider_id'] ?? 0);
    $form_type        = $_POST['transfer_type'] ?? $old_type;
    $form_amount      = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
    $form_reference   = trim($_POST['reference_number'] ?? '');
    $form_description = trim($_POST['description'] ?? '');
    $form_date        = $_POST['transfer_date'] ?? date('Y-m-d');
}

$form_selected_provider = null;
foreach ($providers_list as $p) {
    if (intval($p['id']) === $form_provider_id) {
        $form_selected_provider = $p;
        break;
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
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
                <a href="view.php?id=<?php echo $id; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Transfer</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#7C3AED;"></i> Edit Transfer</h2>
                <p class="text-muted">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($transfer['transfer_number']); ?>
                </p>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- WARNING BANNER -->
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i>
            <span>
                Editing this transfer will reverse the previous effect and apply the new values.
                The branch daily report will be updated automatically.
            </span>
        </div>

        <!-- ORIGINAL VALUES -->
        <div class="original-card">
            <div class="original-header">
                <i class="fas fa-history"></i>
                <span>Original Transfer Values</span>
            </div>
            <div class="original-grid">
                <div class="original-item">
                    <span class="original-label">Type</span>
                    <span class="original-value">
                        <?php echo $old_type === 'cash_to_float' ? 'Cash → Float' : 'Float → Cash'; ?>
                    </span>
                </div>
                <div class="original-item">
                    <span class="original-label">Provider</span>
                    <span class="original-value"><?php echo htmlspecialchars($transfer['provider_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="original-item">
                    <span class="original-label">Amount</span>
                    <span class="original-value mono"><?php echo formatCurrency($old_amount); ?></span>
                </div>
                <div class="original-item">
                    <span class="original-label">Date</span>
                    <span class="original-value"><?php echo date('d M Y', strtotime($transfer['transfer_date'])); ?></span>
                </div>
            </div>
        </div>

        <!-- EDIT FORM -->
        <div class="transfer-form-container">
            <div class="transfer-form-header">
                <div class="transfer-form-header-left">
                    <i class="fas fa-pen-to-square"></i>
                    <div>
                        <h3>Update Transfer</h3>
                        <p>Modify the values below and save</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="" class="transfer-form" onsubmit="return validateEdit()">
                <input type="hidden" name="action" value="update_transfer">
                <input type="hidden" name="provider_id" id="hiddenProviderId" value="<?php echo $form_provider_id; ?>">

                <!-- Transfer Type -->
                <div class="transfer-type-selector">
                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="cash_to_float"
                               <?php echo $form_type === 'cash_to_float' ? 'checked' : ''; ?>
                               onchange="updateTransferUI()">
                        <div class="transfer-type-card type-cash-to-float">
                            <div class="type-icon"><i class="fas fa-arrow-right"></i></div>
                            <div class="type-info">
                                <span class="type-title">Cash → Float</span>
                                <span class="type-desc">Move from Cash to Provider Float</span>
                            </div>
                        </div>
                    </label>

                    <label class="transfer-type-option">
                        <input type="radio" name="transfer_type" value="float_to_cash"
                               <?php echo $form_type === 'float_to_cash' ? 'checked' : ''; ?>
                               onchange="updateTransferUI()">
                        <div class="transfer-type-card type-float-to-cash">
                            <div class="type-icon"><i class="fas fa-arrow-left"></i></div>
                            <div class="type-info">
                                <span class="type-title">Float → Cash</span>
                                <span class="type-desc">Move from Provider Float to Cash</span>
                            </div>
                        </div>
                    </label>
                </div>

                <!-- Provider -->
                <div class="provider-select-section">
                    <label class="provider-select-label">
                        <i class="fas fa-university"></i>
                        Select Provider <span class="required">*</span>
                    </label>

                    <div class="custom-provider-dropdown" id="customProviderDropdown">
                        <div class="provider-dropdown-trigger" onclick="toggleProviderDropdown()">
                            <div class="provider-dropdown-trigger-content" id="providerTriggerContent">
                                <?php if ($form_selected_provider): ?>
                                    <div class="selected-provider-display">
                                        <div class="selected-provider-icon"
                                             style="background: <?php echo htmlspecialchars($form_selected_provider['color_code'] ?? '#0B5ED7'); ?>;">
                                            <i class="<?php echo htmlspecialchars($form_selected_provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
                                        </div>
                                        <div class="selected-provider-info">
                                            <span class="selected-provider-name">
                                                <?php echo htmlspecialchars($form_selected_provider['provider_name']); ?>
                                            </span>
                                            <div class="selected-provider-meta">
                                                <span class="selected-provider-code">
                                                    <?php echo htmlspecialchars($form_selected_provider['branch_provider_code'] ?? $form_selected_provider['main_code']); ?>
                                                </span>
                                                <span class="selected-provider-float">
                                                    <i class="fas fa-coins"></i>
                                                    Float: <?php echo formatCurrency($form_selected_provider['current_float']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="provider-placeholder">
                                        <i class="fas fa-hand-pointer"></i>
                                        <span>Click to select a provider...</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-chevron-down provider-dropdown-arrow" id="providerDropdownArrow"></i>
                        </div>

                        <div class="provider-dropdown-menu" id="providerDropdownMenu">
                            <div class="provider-dropdown-search">
                                <i class="fas fa-search"></i>
                                <input type="text" id="providerSearchInput"
                                       placeholder="Search provider..."
                                       oninput="filterProviders(this.value)"
                                       onclick="event.stopPropagation()">
                            </div>

                            <div class="provider-dropdown-list" id="providerDropdownList">
                                <div class="provider-cards-grid">
                                    <?php foreach ($providers_list as $p):
                                        $color = $p['color_code'] ?? '#0B5ED7';
                                        $icon  = $p['icon_class'] ?? 'fas fa-university';
                                        $pfloat = floatval($p['current_float']);
                                        $pcode = $p['branch_provider_code'] ?? $p['main_code'];
                                    ?>
                                        <div class="provider-card-item"
                                             data-provider-id="<?php echo $p['id']; ?>"
                                             data-provider-name="<?php echo htmlspecialchars(strtolower($p['provider_name'])); ?>"
                                             data-provider-code="<?php echo htmlspecialchars(strtolower($pcode)); ?>"
                                             data-float="<?php echo $pfloat; ?>"
                                             data-name="<?php echo htmlspecialchars($p['provider_name']); ?>"
                                             data-code="<?php echo htmlspecialchars($pcode); ?>"
                                             data-color="<?php echo htmlspecialchars($color); ?>"
                                             data-icon="<?php echo htmlspecialchars($icon); ?>"
                                             onclick="selectProvider(this)">
                                            <div class="provider-card-check"><i class="fas fa-check-circle"></i></div>
                                            <div class="provider-card-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                            </div>
                                            <div class="provider-card-name"><?php echo htmlspecialchars($p['provider_name']); ?></div>
                                            <div class="provider-card-code"><?php echo htmlspecialchars($pcode); ?></div>
                                            <div class="provider-card-float">
                                                <span class="provider-float-label"><i class="fas fa-coins"></i> Float</span>
                                                <span class="provider-float-value"><?php echo formatCurrency($pfloat); ?></span>
                                            </div>
                                            <div class="provider-card-type">
                                                <i class="fas fa-<?php echo $p['provider_type'] === 'mobile_money' ? 'mobile-alt' : 'university'; ?>"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $p['provider_type'] ?? 'Bank')); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
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
                        <input type="text" name="amount" id="transferAmount"
                               class="transfer-form-control transfer-amount-input"
                               placeholder="1,000,000" inputmode="numeric" autocomplete="off" required
                               value="<?php echo number_format($form_amount); ?>"
                               oninput="formatMoneyInput(this); updatePreview();">
                    </div>
                    <div class="transfer-form-group">
                        <label>Transfer Date <span class="required">*</span></label>
                        <input type="date" name="transfer_date" class="transfer-form-control"
                               value="<?php echo htmlspecialchars($form_date); ?>" required>
                    </div>
                </div>

                <!-- Live Preview -->
                <div class="transfer-preview" id="transferPreview">
                    <div class="preview-header"><i class="fas fa-eye"></i> Transfer Preview (After Save)</div>
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
                               value="<?php echo htmlspecialchars($form_reference); ?>"
                               placeholder="Optional reference">
                    </div>
                    <div class="transfer-form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="transfer-form-control"
                               value="<?php echo htmlspecialchars($form_description); ?>"
                               placeholder="Optional description">
                    </div>
                </div>

                <div class="transfer-form-actions">
                    <button type="submit" class="btn btn-transfer" id="transferSubmitBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-reset">
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

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper {
    width: 42px; height: 42px; background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: 18px; color: #FFFFFF; flex-shrink: 0;
}
.branch-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.75;
    text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF;
}
.branch-indicator-name {
    font-weight: 700; font-size: 16px; color: #FFFFFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px;
}
.branch-indicator-code {
    font-size: 11px; font-weight: 600; color: #FFFFFF;
    padding: 3px 12px; background: rgba(255, 255, 255, 0.18);
    border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.1);
}
.branch-location {
    display: flex; align-items: center; gap: 5px; font-size: 12px;
    color: rgba(255,255,255,0.85); padding: 4px 12px;
    background: rgba(255, 255, 255, 0.08); border-radius: 12px; white-space: nowrap;
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255, 255, 255, 0.12);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.22); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 700; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 6px 0 0 0; display: flex; align-items: center; gap: 6px;
    font-family: 'Courier New', monospace; font-weight: 600;
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.alert-warning { background: #FEF3C7; color: #78350F; border: 1px solid #FDE68A; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-warning { background: #5F3A1E; color: #FDE68A; border-color: #92400E; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }
.alert-close:hover { opacity: 1; }

/* ORIGINAL VALUES */
.original-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
}
.original-header {
    background: linear-gradient(135deg, #F3F4F6 0%, #E5E7EB 100%);
    padding: 12px 20px;
    border-bottom: 1.5px solid var(--border-color);
    display: flex; align-items: center; gap: 10px;
    font-size: 12px; font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 1px;
}
html.dark-mode .original-header {
    background: linear-gradient(135deg, #1a2332 0%, #2d3a4f 100%);
}
.original-header i { color: #7C3AED; }
.original-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 12px; padding: 16px 20px;
}
.original-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 10px 14px;
    background: var(--bg-input);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    min-width: 0;
}
.original-label {
    font-size: 10px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
}
.original-value {
    font-size: 13px; font-weight: 800;
    color: var(--text-primary);
    word-break: break-word;
}
.original-value.mono { font-family: 'Courier New', monospace; color: #7C3AED; }

/* FORM CONTAINER */
.transfer-form-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 4px 16px var(--shadow-color);
}
.transfer-form-header {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    padding: 16px 24px;
    display: flex; justify-content: space-between; align-items: center;
    color: #FFFFFF; gap: 12px; flex-wrap: wrap;
}
.transfer-form-header-left { display: flex; align-items: center; gap: 14px; }
.transfer-form-header-left > i {
    font-size: 24px; width: 46px; height: 46px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid rgba(255,255,255,0.2);
}
.transfer-form-header h3 { font-size: 16px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.transfer-form-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.8); font-weight: 500; }
.transfer-form { padding: 24px; }

/* TRANSFER TYPE SELECTOR */
.transfer-type-selector {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 12px; margin-bottom: 20px;
}
.transfer-type-option { cursor: pointer; position: relative; }
.transfer-type-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.transfer-type-card {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px; border-radius: 12px;
    border: 2px solid var(--border-color);
    background: var(--bg-input);
    transition: all 0.3s ease; min-width: 0;
}
.transfer-type-card:hover { border-color: #7C3AED; transform: translateY(-2px); }
.transfer-type-option input[type="radio"]:checked + .transfer-type-card {
    border-color: #7C3AED;
    background: linear-gradient(135deg, #EDE9FE 0%, #DDD6FE 100%);
    box-shadow: 0 4px 16px rgba(124, 58, 237, 0.25);
}
html.dark-mode .transfer-type-option input[type="radio"]:checked + .transfer-type-card {
    background: linear-gradient(135deg, #4C1D95 0%, #6D28D9 100%);
}
.type-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.type-cash-to-float .type-icon {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669;
}
.type-float-to-cash .type-icon {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    color: #D97706;
}
html.dark-mode .type-cash-to-float .type-icon { background: #065F46; color: #34D399; }
html.dark-mode .type-float-to-cash .type-icon { background: #5F3A1E; color: #FBBF24; }
.type-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.type-title { font-size: 14px; font-weight: 800; color: var(--text-primary); }
.type-desc { font-size: 11px; font-weight: 500; color: var(--text-muted); }

/* PROVIDER SELECTION */
.provider-select-section { margin-bottom: 20px; }
.provider-select-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 8px;
}
.provider-select-label i { color: #7C3AED; font-size: 13px; }
.provider-select-label .required { color: #DC2626; }

.custom-provider-dropdown { position: relative; width: 100%; }
.provider-dropdown-trigger {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 14px 18px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    cursor: pointer; transition: all 0.3s ease;
    min-height: 60px;
}
.provider-dropdown-trigger:hover { border-color: #7C3AED; background: var(--bg-card); }
.custom-provider-dropdown.open .provider-dropdown-trigger {
    border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
    background: var(--bg-card);
}
.provider-dropdown-trigger-content { flex: 1; min-width: 0; }
.provider-placeholder {
    display: flex; align-items: center; gap: 10px;
    color: var(--text-muted); font-size: 14px; font-weight: 500;
}
.provider-placeholder i { font-size: 18px; color: #7C3AED; }

.selected-provider-display { display: flex; align-items: center; gap: 14px; min-width: 0; }
.selected-provider-icon {
    width: 42px; height: 42px; border-radius: 50%;
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
    color: #7C3AED; background: #EDE9FE;
    padding: 2px 10px; border-radius: 8px;
    font-family: 'Courier New', monospace;
}
html.dark-mode .selected-provider-code { background: #4C1D95; color: #DDD6FE; }
.selected-provider-float {
    font-size: 11px; font-weight: 700;
    color: #059669; display: flex; align-items: center; gap: 4px;
}
html.dark-mode .selected-provider-float { color: #34D399; }

.provider-dropdown-arrow {
    font-size: 14px; color: var(--text-muted);
    transition: transform 0.3s ease; flex-shrink: 0;
}
.custom-provider-dropdown.open .provider-dropdown-arrow {
    transform: rotate(180deg); color: #7C3AED;
}
.provider-dropdown-menu {
    position: absolute; top: calc(100% + 6px);
    left: 0; right: 0;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.15);
    z-index: 100; max-height: 480px;
    overflow: hidden; display: none;
}
.custom-provider-dropdown.open .provider-dropdown-menu { display: block; }
.provider-dropdown-search {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 16px;
    border-bottom: 1.5px solid var(--border-color);
    background: var(--bg-input);
    position: sticky; top: 0; z-index: 2;
}
.provider-dropdown-search i { font-size: 13px; color: #7C3AED; flex-shrink: 0; }
.provider-dropdown-search input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 13px;
    color: var(--text-primary); outline: none;
    font-family: 'Inter', sans-serif; min-width: 0;
}
.provider-dropdown-list {
    max-height: 400px; overflow-y: auto; padding: 12px;
}
.provider-dropdown-list::-webkit-scrollbar { width: 6px; }
.provider-dropdown-list::-webkit-scrollbar-thumb { background: #C4B5FD; border-radius: 3px; }
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
    border-color: #7C3AED; background: var(--bg-card);
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.15);
}
.provider-card-item.selected {
    border-color: #7C3AED;
    background: linear-gradient(135deg, #EDE9FE 0%, #DDD6FE 100%);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.25);
}
html.dark-mode .provider-card-item.selected {
    background: linear-gradient(135deg, #4C1D95 0%, #6D28D9 100%);
}
.provider-card-item.hidden-by-filter { display: none !important; }
.provider-card-check {
    position: absolute; top: 6px; right: 6px;
    width: 22px; height: 22px;
    background: #7C3AED; color: #FFFFFF;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    opacity: 0; transform: scale(0.5);
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(124, 58, 237, 0.4);
}
.provider-card-item.selected .provider-card-check { opacity: 1; transform: scale(1); }
.provider-card-icon {
    width: 48px; height: 48px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 20px; flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.provider-card-name {
    font-size: 12px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    width: 100%; line-height: 1.2;
}
.provider-card-code {
    font-size: 9px; font-weight: 700;
    color: #7C3AED; background: #EDE9FE;
    padding: 2px 8px; border-radius: 6px;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}
html.dark-mode .provider-card-code { background: #4C1D95; color: #DDD6FE; }
.provider-card-float {
    display: flex; flex-direction: column;
    align-items: center; gap: 2px;
    width: 100%; padding: 6px 8px;
    background: #ECFDF5;
    border: 1px solid #A7F3D0;
    border-radius: 8px; margin-top: 2px;
}
html.dark-mode .provider-card-float { background: #065F46; border-color: #10B981; }
.provider-float-label {
    font-size: 8px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    color: #059669;
    display: flex; align-items: center; gap: 3px; white-space: nowrap;
}
html.dark-mode .provider-float-label { color: #34D399; }
.provider-float-value {
    font-size: 12px; font-weight: 900;
    color: #047857;
    font-family: 'Inter', 'Courier New', monospace;
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; max-width: 100%;
}
html.dark-mode .provider-float-value { color: #6EE7B7; }
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

/* FORM ROWS */
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
.transfer-form-group label .required { color: #DC2626; }
.transfer-form-control {
    padding: 11px 14px;
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
    outline: none; border-color: #7C3AED;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
    background: var(--bg-card);
}
.transfer-amount-input {
    font-size: 20px !important;
    font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: 1px;
    text-align: right;
}

/* PREVIEW */
.transfer-preview {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border: 2px solid #C4B5FD;
    border-radius: 12px;
    padding: 16px 20px; margin-bottom: 16px;
}
html.dark-mode .transfer-preview {
    background: linear-gradient(135deg, #4C1D95 0%, #6D28D9 100%);
    border-color: #8B5CF6;
}
.preview-header {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 800;
    color: #7C3AED;
    text-transform: uppercase; letter-spacing: 1px;
    margin-bottom: 14px;
}
html.dark-mode .preview-header { color: #DDD6FE; }
.preview-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
}
.preview-item {
    display: flex; flex-direction: column; gap: 4px;
    padding: 10px 12px;
    background: rgba(255,255,255,0.7);
    border-radius: 8px;
    border: 1px solid rgba(196, 181, 253, 0.4);
    min-width: 0;
}
html.dark-mode .preview-item {
    background: rgba(15, 23, 42, 0.4);
    border-color: rgba(139, 92, 246, 0.3);
}
.preview-label {
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: #6D28D9;
}
html.dark-mode .preview-label { color: #C4B5FD; }
.preview-value {
    font-size: clamp(12px, 1.1vw, 15px);
    font-weight: 900;
    color: #1E293B;
    font-family: 'Inter', 'Courier New', monospace;
    word-break: break-all; overflow-wrap: anywhere;
    line-height: 1.2;
}
html.dark-mode .preview-value { color: #F1F5F9; }
.preview-float { color: #1D4ED8; }
.preview-cash { color: #059669; }
.preview-after { color: #7C3AED; }
html.dark-mode .preview-float { color: #60A5FA; }
html.dark-mode .preview-cash { color: #34D399; }
html.dark-mode .preview-after { color: #A78BFA; }

/* ACTIONS */
.transfer-form-actions {
    display: flex; gap: 12px; padding-top: 8px; flex-wrap: wrap;
}
.btn {
    padding: 11px 22px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    text-decoration: none; white-space: nowrap;
}
.btn-transfer {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.35);
}
.btn-transfer:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.5);
    color: #FFFFFF;
}
.btn-transfer:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-reset {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset:hover {
    background: var(--bg-table-hover);
    color: var(--text-primary);
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .original-grid { grid-template-columns: repeat(2, 1fr); }
    .preview-grid { grid-template-columns: repeat(2, 1fr); }
    .transfer-type-selector { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .original-grid { grid-template-columns: 1fr; }
    .transfer-form-row { grid-template-columns: 1fr; }
    .preview-grid { grid-template-columns: 1fr; }
    .transfer-form-actions { flex-direction: column; }
    .transfer-form-actions .btn { width: 100%; justify-content: center; }
    .transfer-form { padding: 16px; }
    .provider-cards-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .provider-cards-grid { grid-template-columns: 1fr; }
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
    if (value.length > 15) value = value.substring(0, 15);

    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    const newCursorPos = cursorPos + (formatted.length - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) {}
}
function formatMoney(num) {
    return 'TSh ' + Number(num).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0});
}
function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

// ============================================================
// ORIGINAL VALUES (injected from PHP)
// ============================================================
const ORIGINAL_PROVIDER_ID = <?php echo intval($old_provider_id); ?>;
const ORIGINAL_TYPE        = <?php echo json_encode($old_type); ?>;
const ORIGINAL_AMOUNT      = <?php echo floatval($old_amount); ?>;
const BRANCH_CASH_NOW      = <?php echo floatval($current_cash); ?>;

let currentProviderData = {
    id: <?php echo intval($form_selected_provider['id'] ?? 0); ?>,
    float: <?php echo floatval($form_selected_provider['current_float'] ?? 0); ?>,
    name: <?php echo json_encode($form_selected_provider['provider_name'] ?? ''); ?>,
    code: <?php echo json_encode($form_selected_provider['branch_provider_code'] ?? ($form_selected_provider['main_code'] ?? '')); ?>,
    color: <?php echo json_encode($form_selected_provider['color_code'] ?? ''); ?>,
    icon: <?php echo json_encode($form_selected_provider['icon_class'] ?? ''); ?>
};

// ============================================================
// PROVIDER DROPDOWN
// ============================================================
function toggleProviderDropdown() {
    const dropdown = document.getElementById('customProviderDropdown');
    dropdown.classList.toggle('open');
    if (dropdown.classList.contains('open')) {
        setTimeout(() => document.getElementById('providerSearchInput').focus(), 100);
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

    document.getElementById('providerTriggerContent').innerHTML = `
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
    if (dropdown && !dropdown.contains(e.target)) dropdown.classList.remove('open');
});

// ============================================================
// LIVE PREVIEW — reverse old, apply new
// ============================================================
function updatePreview() {
    const amount = parseMoney(document.getElementById('transferAmount').value);
    const type = document.querySelector('input[name="transfer_type"]:checked').value;

    // 1) Reverse the OLD transfer's effect on the float
    let floatForNew = currentProviderData.float;
    if (currentProviderData.id == ORIGINAL_PROVIDER_ID) {
        if (ORIGINAL_TYPE === 'cash_to_float') {
            floatForNew = currentProviderData.float - ORIGINAL_AMOUNT;
        } else {
            floatForNew = currentProviderData.float + ORIGINAL_AMOUNT;
        }
    }

    // 2) Reverse the OLD transfer's effect on branch cash
    let cashForNew = BRANCH_CASH_NOW;
    if (ORIGINAL_TYPE === 'cash_to_float') {
        cashForNew = BRANCH_CASH_NOW + ORIGINAL_AMOUNT;
    } else {
        cashForNew = BRANCH_CASH_NOW - ORIGINAL_AMOUNT;
    }

    // 3) Apply the NEW transfer
    let afterFloat = floatForNew;
    if (type === 'cash_to_float') {
        afterFloat = floatForNew + amount;
    } else {
        afterFloat = floatForNew - amount;
    }

    document.getElementById('previewCurrentFloat').textContent = formatMoney(floatForNew);
    document.getElementById('previewCurrentCash').textContent = formatMoney(cashForNew);
    const afterEl = document.getElementById('previewAfter');
    afterEl.textContent = formatMoney(afterFloat);
    afterEl.style.color = (afterFloat < 0) ? '#DC2626' : '';
}

function updateTransferUI() { updatePreview(); }

// ============================================================
// VALIDATION
// ============================================================
function validateEdit() {
    const providerId = document.getElementById('hiddenProviderId').value;
    const amount = parseMoney(document.getElementById('transferAmount').value);
    const type = document.querySelector('input[name="transfer_type"]:checked').value;

    if (!providerId || providerId === '0') {
        alert('Please select a provider.');
        return false;
    }
    if (amount <= 0) {
        alert('Please enter a valid amount.');
        return false;
    }

    // Branch cash after reversing the old transfer
    let cashAfterReverse = BRANCH_CASH_NOW;
    if (ORIGINAL_TYPE === 'cash_to_float') cashAfterReverse += ORIGINAL_AMOUNT;
    else cashAfterReverse -= ORIGINAL_AMOUNT;

    if (type === 'cash_to_float' && amount > cashAfterReverse) {
        alert('Insufficient cash after reversing the old transfer!\n\nAvailable: ' + formatMoney(cashAfterReverse) + '\nTrying: ' + formatMoney(amount));
        return false;
    }

    // Provider float after reversing the old transfer (only if same provider)
    let floatAfterReverse = currentProviderData.float;
    if (currentProviderData.id == ORIGINAL_PROVIDER_ID) {
        if (ORIGINAL_TYPE === 'cash_to_float') floatAfterReverse -= ORIGINAL_AMOUNT;
        else floatAfterReverse += ORIGINAL_AMOUNT;
    }

    if (type === 'float_to_cash' && amount > floatAfterReverse) {
        alert('Insufficient provider float after reversing the old transfer!\n\nAvailable: ' + formatMoney(floatAfterReverse) + '\nTrying: ' + formatMoney(amount));
        return false;
    }

    const btn = document.getElementById('transferSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    return true;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Mark currently selected card
    document.querySelectorAll('.provider-card-item').forEach(item => {
        if (parseInt(item.getAttribute('data-provider-id')) === currentProviderData.id) {
            item.classList.add('selected');
        }
    });

    // Show initial preview if a provider is preselected
    if (currentProviderData.id > 0) {
        document.getElementById('previewProvider').textContent =
            currentProviderData.name + ' (' + currentProviderData.code + ')';
        document.getElementById('transferPreview').style.display = 'block';
        updatePreview();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('customProviderDropdown');
        if (dropdown && dropdown.classList.contains('open')) dropdown.classList.remove('open');
    }
});
</script>

</body>
</html>