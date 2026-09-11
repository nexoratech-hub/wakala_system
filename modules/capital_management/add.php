<?php
// ================================================================
// FILE: modules/capital_management/add.php
// ADD CAPITAL TRANSACTION
// ✅ NEW: Can add Float AND Cash at the same time
// ✅ NEW: Checkbox to enable/disable each source
// ✅ NEW: Providers displayed like Morning Report
// ✅ FIXED: Description is now OPTIONAL
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

$error = '';
$success = '';

// ============================================================
// GET SELECTED BRANCH FROM URL
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

try {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
}

$branch_name = 'Select Branch';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            break;
        }
    }
}

// ============================================================
// GET BRANCH PROVIDERS
// ============================================================
$branch_providers = [];
if ($selected_branch > 0) {
    try {
        $stmt = $db->prepare("
            SELECT 
                bp.id as branch_provider_id,
                bp.provider_code,
                bp.provider_id,
                p.provider_name,
                p.provider_type,
                p.icon_class,
                p.color_code
            FROM branch_providers bp
            JOIN providers p ON bp.provider_id = p.id
            WHERE bp.branch_id = ? AND bp.is_active = 1
            ORDER BY p.display_order, p.provider_name
        ");
        $stmt->execute([$selected_branch]);
        $branch_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching branch providers: " . $e->getMessage());
    }
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_type = $_POST['transaction_type'] ?? '';
    $description_base = trim($_POST['description'] ?? ''); // ✅ Now optional
    $notes = trim($_POST['notes'] ?? '');
    $branch_id = intval($_POST['branch_id'] ?? 0);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    
    // Source toggles
    $include_float = isset($_POST['include_float']) ? 1 : 0;
    $include_cash = isset($_POST['include_cash']) ? 1 : 0;
    
    // Provider amounts
    $provider_amounts = [];
    if (isset($_POST['provider_amount']) && is_array($_POST['provider_amount'])) {
        foreach ($_POST['provider_amount'] as $key => $val) {
            $amount = floatval(str_replace(',', '', $val));
            if ($amount > 0) {
                $provider_amounts[$key] = $amount;
            }
        }
    }
    
    // Cash amount
    $cash_amount = floatval(str_replace(',', '', $_POST['cash_amount'] ?? 0));
    
    // Validate
    $errors = [];
    
    if (empty($transaction_type)) {
        $errors[] = 'Please select transaction type';
    }
    if ($branch_id <= 0) {
        $errors[] = 'Please select a branch';
    }
    // ✅ Description is NO LONGER required
    if (!$include_float && !$include_cash) {
        $errors[] = 'Please enable at least one source (Float or Cash)';
    }
    if ($include_float && empty($provider_amounts)) {
        $errors[] = 'Please enter at least one provider amount for Float';
    }
    if ($include_cash && $cash_amount <= 0) {
        $errors[] = 'Please enter a Cash amount';
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    } else {
        try {
            // Get branch name
            $branch_name_selected = 'Main';
            $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $branch = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($branch) {
                $branch_name_selected = $branch['branch_name'];
            }
            
            $inserted_count = 0;
            $total_amount = 0;
            
            // ✅ Default description if empty
            $default_description = !empty($description_base) ? $description_base : 'Capital transaction';
            
            // ============================================================
            // INSERT FLOAT CAPITAL (multiple providers)
            // ============================================================
            if ($include_float && !empty($provider_amounts)) {
                foreach ($provider_amounts as $branch_provider_id => $amount) {
                    $stmt = $db->prepare("
                        SELECT bp.provider_code, bp.provider_id, p.provider_name
                        FROM branch_providers bp
                        JOIN providers p ON bp.provider_id = p.id
                        WHERE bp.id = ?
                    ");
                    $stmt->execute([$branch_provider_id]);
                    $bp_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($bp_data) {
                        $capital_number = generateNumber('CAP');
                        // ✅ Description with provider name
                        $description = $default_description . ' [' . $bp_data['provider_name'] . ' - ' . $bp_data['provider_code'] . ']';
                        
                        $sql = "INSERT INTO capital_management (
                            capital_number, employee_id, branch_id, branch, transaction_date,
                            transaction_type, amount, description, reference_id, reference_module, notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $db->prepare($sql);
                        $stmt->execute([
                            $capital_number,
                            $user_id,
                            $branch_id,
                            $branch_name_selected,
                            $transaction_date,
                            $transaction_type,
                            $amount,
                            $description,
                            $bp_data['provider_id'],
                            'provider',
                            $notes
                        ]);
                        
                        $inserted_count++;
                        $total_amount += $amount;
                    }
                }
            }
            
            // ============================================================
            // INSERT CASH CAPITAL (single)
            // ============================================================
            if ($include_cash && $cash_amount > 0) {
                $capital_number = generateNumber('CAP');
                // ✅ Description with cash marker
                $description = $default_description . ' [Cash - Manual]';
                
                $sql = "INSERT INTO capital_management (
                    capital_number, employee_id, branch_id, branch, transaction_date,
                    transaction_type, amount, description, reference_id, reference_module, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $capital_number,
                    $user_id,
                    $branch_id,
                    $branch_name_selected,
                    $transaction_date,
                    $transaction_type,
                    $cash_amount,
                    $description,
                    null,
                    'cash_manual',
                    $notes
                ]);
                
                $inserted_count++;
                $total_amount += $cash_amount;
            }
            
            if ($inserted_count > 0) {
                logActivity($user_id, 'Add Capital', 'Capital Management', null, null, json_encode([
                    'type' => $transaction_type,
                    'branch_id' => $branch_id,
                    'records' => $inserted_count,
                    'total' => $total_amount
                ]));
                
                $_SESSION['success_message'] = $inserted_count . ' capital transaction(s) added successfully! Total: ' . formatCurrency($total_amount);
                header('Location: index.php?branch=' . $branch_id);
                exit();
            } else {
                $error = 'No valid transactions to add.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log("Error adding capital: " . $e->getMessage());
        }
    }
}

$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Capital Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH CARD ===== -->
        <div class="branch-card">
            <i class="fas fa-store-alt"></i>
            <span class="branch-label">Current Branch:</span>
            <span class="branch-name"><?php echo htmlspecialchars($branch_name); ?></span>
            <?php if ($selected_branch > 0): ?>
                <span class="provider-count-badge">
                    <i class="fas fa-university"></i> <?php echo count($branch_providers); ?> Providers
                </span>
                <a href="add.php" class="branch-clear">
                    <i class="fas fa-times-circle"></i> Clear
                </a>
            <?php endif; ?>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add Capital Transaction</h2>
                <p class="text-muted">You can add Float, Cash, or both at the same time</p>
            </div>
            <div class="header-right">
                <a href="index.php?branch=<?php echo $selected_branch; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" class="capital-form" id="capitalForm">
                
                <!-- ============================================================
                STEP 1: BRANCH SELECTION
                ============================================================ -->
                <div class="form-section">
                    <div class="section-title">
                        <span class="step-number">1</span>
                        <span>Select Branch</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Branch <span class="required">*</span></label>
                            <select name="branch_id" id="branchSelect" class="form-control" required onchange="onBranchChange()">
                                <option value="">-- Select Branch --</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" 
                                        <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                        (<?php echo htmlspecialchars($b['branch_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Choose the branch for this capital transaction</small>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                STEP 2: TRANSACTION DETAILS
                ============================================================ -->
                <div class="form-section">
                    <div class="section-title">
                        <span class="step-number">2</span>
                        <span>Transaction Details</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Transaction Type <span class="required">*</span></label>
                            <select name="transaction_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <?php foreach ($type_labels as $key => $type): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $type['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Transaction Date <span class="required">*</span></label>
                            <input type="date" name="transaction_date" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                STEP 3: CAPITAL SOURCE (Float AND/OR Cash)
                ============================================================ -->
                <div class="form-section">
                    <div class="section-title">
                        <span class="step-number">3</span>
                        <span>Capital Source</span>
                    </div>

                    <!-- ============================================================
                    ENABLE/DISABLE TOGGLES
                    ============================================================ -->
                    <div class="source-toggles">
                        <label class="source-toggle" id="floatToggleCard">
                            <input type="checkbox" name="include_float" id="includeFloat" 
                                   value="1" onchange="onFloatToggle(this.checked)">
                            <div class="source-toggle-content">
                                <div class="toggle-icon float-icon">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div class="toggle-info">
                                    <h4>Float (Providers)</h4>
                                    <p>Add capital from providers</p>
                                </div>
                                <div class="toggle-switch">
                                    <span class="switch-slider"></span>
                                </div>
                            </div>
                        </label>

                        <label class="source-toggle" id="cashToggleCard">
                            <input type="checkbox" name="include_cash" id="includeCash" 
                                   value="1" onchange="onCashToggle(this.checked)">
                            <div class="source-toggle-content">
                                <div class="toggle-icon cash-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="toggle-info">
                                    <h4>Cash (Manual)</h4>
                                    <p>Add capital in cash</p>
                                </div>
                                <div class="toggle-switch">
                                    <span class="switch-slider"></span>
                                </div>
                            </div>
                        </label>
                    </div>

                    <!-- ============================================================
                    FLOAT PROVIDERS SECTION
                    ============================================================ -->
                    <div class="provider-section" id="providerSection" style="display:none;">
                        <div class="provider-header">
                            <div class="provider-header-left">
                                <i class="fas fa-university" style="color:#3B82F6;"></i>
                                <span>Provider Capital Amounts</span>
                            </div>
                            <div class="provider-header-right">
                                <span class="provider-count-badge-inline">
                                    <?php echo count($branch_providers); ?> providers available
                                </span>
                                <button type="button" class="btn-select-all" onclick="selectAllProviders()">
                                    <i class="fas fa-check-double"></i> Select All
                                </button>
                                <button type="button" class="btn-deselect-all" onclick="deselectAllProviders()">
                                    <i class="fas fa-times"></i> Deselect
                                </button>
                            </div>
                        </div>
                        
                        <?php if (empty($branch_providers)): ?>
                            <div class="no-providers">
                                <i class="fas fa-info-circle"></i>
                                <p>No providers assigned to this branch.</p>
                                <a href="../branches/providers_add.php?branch_id=<?php echo $selected_branch; ?>" class="btn-link">
                                    <i class="fas fa-plus-circle"></i> Add Providers
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="providers-grid">
                                <?php foreach ($branch_providers as $bp): 
                                    $bp_id = $bp['branch_provider_id'];
                                    $provider_color = $bp['color_code'] ?? '#0B5ED7';
                                    $provider_icon = $bp['icon_class'] ?? 'fas fa-university';
                                    $provider_type_label = ($bp['provider_type'] === 'mobile_money') ? 'Mobile Money' : 'Bank';
                                ?>
                                    <div class="provider-card" data-provider-id="<?php echo $bp_id; ?>">
                                        <div class="provider-checkbox-wrapper">
                                            <input type="checkbox" 
                                                   id="provider_check_<?php echo $bp_id; ?>"
                                                   class="provider-checkbox"
                                                   onchange="onProviderCheck(this, <?php echo $bp_id; ?>)">
                                            <label for="provider_check_<?php echo $bp_id; ?>" class="provider-checkbox-label">
                                                <span class="custom-checkbox">
                                                    <i class="fas fa-check"></i>
                                                </span>
                                            </label>
                                        </div>
                                        
                                        <div class="provider-icon" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                            <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                        </div>
                                        
                                        <div class="provider-info">
                                            <span class="provider-name"><?php echo htmlspecialchars($bp['provider_name']); ?></span>
                                            <span class="provider-code">
                                                <i class="fas fa-tag"></i>
                                                <?php echo htmlspecialchars($bp['provider_code']); ?>
                                            </span>
                                            <span class="provider-type-badge"><?php echo $provider_type_label; ?></span>
                                        </div>
                                        
                                        <div class="provider-input-wrapper" id="input_wrapper_<?php echo $bp_id; ?>" style="display:none;">
                                            <span class="currency-symbol">TSh</span>
                                            <input type="text" 
                                                   name="provider_amount[<?php echo $bp_id; ?>]"
                                                   id="provider_amount_<?php echo $bp_id; ?>"
                                                   class="provider-amount money-input"
                                                   placeholder="0.00"
                                                   oninput="formatMoneyInput(this); calculateTotals();"
                                                   data-provider-id="<?php echo $bp_id; ?>">
                                        </div>
                                        
                                        <div class="provider-locked-badge" id="locked_badge_<?php echo $bp_id; ?>">
                                            <i class="fas fa-lock"></i>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="providers-summary">
                                <div class="summary-row">
                                    <span class="summary-label">Selected:</span>
                                    <span class="summary-value" id="selectedProvidersCount">0</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Float Total:</span>
                                    <span class="summary-value text-blue" id="totalFloatDisplay">TSh 0</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ============================================================
                    CASH SECTION
                    ============================================================ -->
                    <div class="cash-section" id="cashSection" style="display:none;">
                        <div class="cash-header">
                            <i class="fas fa-money-bill-wave" style="color:#10B981;"></i>
                            <span>Cash Amount</span>
                        </div>
                        <div class="cash-input-wrapper">
                            <span class="currency-symbol-large">TSh</span>
                            <input type="text" 
                                   name="cash_amount"
                                   id="cashAmount"
                                   class="cash-amount money-input"
                                   placeholder="0.00"
                                   oninput="formatMoneyInput(this); calculateTotals();">
                        </div>
                        <small>Enter the cash amount manually</small>
                    </div>
                    
                    <!-- Message when no source enabled -->
                    <div class="no-source-message" id="noSourceMessage">
                        <i class="fas fa-hand-pointer"></i>
                        <p>Enable <strong>Float</strong> or <strong>Cash</strong> (or both) above to enter amounts</p>
                    </div>
                </div>

                <!-- ============================================================
                STEP 4: DESCRIPTION (OPTIONAL NOW)
                ============================================================ -->
                <div class="form-section">
                    <div class="section-title">
                        <span class="step-number">4</span>
                        <span>Description <span class="optional-tag">Optional</span></span>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>
                                Description 
                                <span class="optional-badge">OPTIONAL</span>
                            </label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Describe the transaction (e.g., 'Initial capital'). Leave empty if not needed."></textarea>
                            <small>
                                <i class="fas fa-info-circle"></i>
                                This field is optional. Provider name will be auto-appended for Float transactions.
                            </small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" 
                                      placeholder="Additional notes"></textarea>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                GRAND TOTAL SUMMARY
                ============================================================ -->
                <div class="grand-total-bar">
                    <div class="grand-total-split">
                        <div class="split-item">
                            <span class="split-label">Float:</span>
                            <span class="split-value" id="floatSummaryDisplay">TSh 0</span>
                        </div>
                        <div class="split-divider">+</div>
                        <div class="split-item">
                            <span class="split-label">Cash:</span>
                            <span class="split-value" id="cashSummaryDisplay">TSh 0</span>
                        </div>
                    </div>
                    <div class="grand-total-right">
                        <span class="grand-label">GRAND TOTAL:</span>
                        <span class="grand-value" id="grandTotalDisplay">TSh 0</span>
                    </div>
                </div>

                <!-- ============================================================
                FORM ACTIONS
                ============================================================ -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Transaction(s)
                    </button>
                    <a href="index.php?branch=<?php echo $selected_branch; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- Type Info -->
        <div class="type-info-card">
            <h4><i class="fas fa-info-circle" style="color:#bb0404;"></i> Transaction Types</h4>
            <div class="type-info-grid">
                <div class="type-info-item type-blue"><span class="dot"></span> <strong>Opening Capital</strong> - Initial capital</div>
                <div class="type-info-item type-green"><span class="dot"></span> <strong>Additional Capital</strong> - Adding more</div>
                <div class="type-info-item type-purple"><span class="dot"></span> <strong>Profit Allocation</strong> - Profit to capital</div>
                <div class="type-info-item type-red"><span class="dot"></span> <strong>Capital Cash Out</strong> - Withdrawing</div>
                <div class="type-info-item type-orange"><span class="dot"></span> <strong>Adjustment</strong> - Correcting balance</div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --cm-bg: #F3F4F6;
    --cm-text: #1F2937;
    --cm-text-secondary: #6B7280;
    --cm-text-light: #9CA3AF;
    --cm-border: #E5E7EB;
    --cm-card-bg: #FFFFFF;
    --cm-card-header: #FAFBFC;
    --cm-input-bg: #F9FAFB;
    --cm-hover: #F3F4F6;
    --cm-shadow: rgba(0,0,0,0.06);
}

html.dark-mode {
    --cm-bg: #0F172A;
    --cm-text: #F9FAFB;
    --cm-text-secondary: #9CA3AF;
    --cm-text-light: #6B7280;
    --cm-border: #334155;
    --cm-card-bg: #1E293B;
    --cm-card-header: #1E293B;
    --cm-input-bg: #334155;
    --cm-hover: #334155;
    --cm-shadow: rgba(0,0,0,0.3);
}

body {
    background: var(--cm-bg) !important;
    color: var(--cm-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--cm-bg) !important; }
.main-content { background: var(--cm-bg) !important; }

/* ============================================================
   BRANCH CARD
   ============================================================ */
.branch-card {
    background: #bb0404;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 2px 8px rgba(187, 4, 4, 0.3);
    flex-wrap: wrap;
}
.branch-card i { font-size: 18px; }
.branch-card .branch-label { font-weight: 500; font-size: 13px; opacity: 0.9; }
.branch-card .branch-name { font-weight: 700; font-size: 15px; }
.branch-card .provider-count-badge {
    background: rgba(255,255,255,0.15);
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 12px;
}
.branch-card .branch-clear {
    margin-left: auto;
    color: #ffffff;
    text-decoration: none;
    font-size: 12px;
    padding: 4px 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    transition: all 0.3s ease;
}
.branch-card .branch-clear:hover { background: rgba(255,255,255,0.25); }

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--cm-text);
    margin: 0;
}
.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--cm-text-secondary);
    margin: 4px 0 0 0;
}
.header-right { display: flex; gap: 10px; flex-wrap: wrap; }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }

/* ============================================================
   FORM CARD & SECTIONS
   ============================================================ */
.form-card {
    background: var(--cm-card-bg);
    border-radius: 12px;
    border: 1px solid var(--cm-border);
    margin-bottom: 20px;
    box-shadow: 0 1px 3px var(--cm-shadow);
    overflow: hidden;
}
.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--cm-border);
}
.form-section:last-of-type { border-bottom: none; }

.section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    font-size: 15px;
    font-weight: 700;
    color: var(--cm-text);
}
.step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #bb0404;
    color: #FFFFFF;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

/* ✅ NEW: Optional tag styles */
.optional-tag {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 10px;
    background: #FEF3C7;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 4px;
}
html.dark-mode .optional-tag {
    background: #5F3A1E;
    color: #FBBF24;
}

.optional-badge {
    display: inline-block;
    font-size: 9px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 8px;
    background: #FEF3C7;
    color: #92400E;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-left: 6px;
    vertical-align: middle;
}
html.dark-mode .optional-badge {
    background: #5F3A1E;
    color: #FBBF24;
}

/* ============================================================
   FORM ROWS
   ============================================================ */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 12px;
}
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--cm-text);
}
.form-group label .required { color: #DC2626; }

.form-control {
    padding: 10px 14px;
    border: 1.5px solid var(--cm-border);
    border-radius: 8px;
    font-size: 13px;
    color: var(--cm-text);
    background: var(--cm-input-bg);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    width: 100%;
}
.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
    background: var(--cm-card-bg);
}
textarea.form-control { resize: vertical; min-height: 60px; }
.form-group small { font-size: 11px; color: var(--cm-text-secondary); margin-top: 2px; }

/* ============================================================
   SOURCE TOGGLES (Float / Cash)
   ============================================================ */
.source-toggles {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}
.source-toggle {
    position: relative;
    cursor: pointer;
    border-radius: 12px;
    border: 2px solid var(--cm-border);
    background: var(--cm-card-bg);
    transition: all 0.3s ease;
    overflow: hidden;
    display: block;
}
.source-toggle:hover {
    border-color: #3B82F6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}
.source-toggle input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.source-toggle-content {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    transition: background 0.3s ease;
}
.toggle-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #FFFFFF;
    flex-shrink: 0;
    opacity: 0.5;
    transition: opacity 0.3s ease;
}
.float-icon { background: linear-gradient(135deg, #3B82F6, #2563EB); }
.cash-icon { background: linear-gradient(135deg, #10B981, #059669); }

.toggle-info { flex: 1; min-width: 0; }
.toggle-info h4 {
    font-size: 14px;
    font-weight: 700;
    color: var(--cm-text);
    margin: 0 0 2px 0;
}
.toggle-info p {
    font-size: 11px;
    color: var(--cm-text-secondary);
    margin: 0;
}

/* Toggle Switch */
.toggle-switch {
    position: relative;
    width: 46px;
    height: 26px;
    background: var(--cm-border);
    border-radius: 13px;
    transition: background 0.3s ease;
    flex-shrink: 0;
}
.switch-slider {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 20px;
    height: 20px;
    background: #FFFFFF;
    border-radius: 50%;
    transition: transform 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.source-toggle input:checked ~ .source-toggle-content .toggle-switch {
    background: #3B82F6;
}
.source-toggle input:checked ~ .source-toggle-content .switch-slider {
    transform: translateX(20px);
}
.source-toggle input:checked ~ .source-toggle-content .toggle-icon {
    opacity: 1;
}

.source-toggle:has(input:checked) {
    border-color: #3B82F6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.01));
}

/* ============================================================
   FLOAT PROVIDER SECTION
   ============================================================ */
.provider-section {
    background: var(--cm-card-header);
    border-radius: 10px;
    padding: 16px;
    border: 1px solid var(--cm-border);
    margin-bottom: 16px;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.provider-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--cm-border);
    flex-wrap: wrap;
}
.provider-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--cm-text);
}
.provider-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.provider-count-badge-inline {
    font-size: 11px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59, 130, 246, 0.1);
    padding: 3px 10px;
    border-radius: 12px;
}
.btn-select-all, .btn-deselect-all {
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-select-all { background: #3B82F6; color: #FFFFFF; }
.btn-select-all:hover { background: #2563EB; }
.btn-deselect-all {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-deselect-all:hover { background: var(--cm-border); }

/* ============================================================
   PROVIDERS GRID
   ============================================================ */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.provider-card {
    position: relative;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    background: var(--cm-card-bg);
    border: 2px solid var(--cm-border);
    border-radius: 10px;
    transition: all 0.3s ease;
    flex-wrap: wrap;
}
.provider-card:hover {
    border-color: #3B82F6;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}
.provider-card.selected {
    border-color: #3B82F6;
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(59, 130, 246, 0.02));
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.provider-checkbox-wrapper { position: relative; flex-shrink: 0; }
.provider-checkbox { position: absolute; opacity: 0; pointer-events: none; }
.provider-checkbox-label { display: block; cursor: pointer; }
.custom-checkbox {
    width: 24px;
    height: 24px;
    border: 2px solid var(--cm-border);
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    background: var(--cm-card-bg);
}
.custom-checkbox i {
    color: transparent;
    font-size: 13px;
    transition: color 0.2s ease;
}
.provider-checkbox:checked + .provider-checkbox-label .custom-checkbox {
    background: #3B82F6;
    border-color: #3B82F6;
}
.provider-checkbox:checked + .provider-checkbox-label .custom-checkbox i {
    color: #FFFFFF;
}

.provider-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
}

.provider-info { flex: 1; min-width: 100px; }
.provider-name {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--cm-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.provider-code {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    color: #3B82F6;
    font-weight: 600;
    margin-top: 2px;
}
.provider-type-badge {
    display: inline-block;
    font-size: 9px;
    font-weight: 600;
    color: var(--cm-text-secondary);
    background: var(--cm-hover);
    padding: 1px 8px;
    border-radius: 8px;
    margin-top: 2px;
}

.provider-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
    margin-top: 8px;
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
.provider-input-wrapper .currency-symbol {
    position: absolute;
    left: 10px;
    font-size: 12px;
    font-weight: 700;
    color: #3B82F6;
    z-index: 1;
    pointer-events: none;
}
.provider-amount {
    width: 100%;
    padding: 8px 12px 8px 40px;
    border: 2px solid #3B82F6;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    color: var(--cm-text);
    background: var(--cm-card-bg);
    outline: none;
    transition: all 0.3s ease;
    text-align: right;
}
.provider-amount:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
.provider-amount::placeholder { color: var(--cm-text-light); font-weight: 400; }

.provider-locked-badge {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--cm-text-light);
    font-size: 12px;
    opacity: 0.4;
    flex-shrink: 0;
}

.no-providers {
    text-align: center;
    padding: 24px;
    color: var(--cm-text-secondary);
}
.no-providers i {
    font-size: 32px;
    color: var(--cm-text-light);
    display: block;
    margin-bottom: 8px;
}
.no-providers p { font-size: 13px; margin: 0 0 10px 0; }
.btn-link {
    color: #3B82F6;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    background: rgba(59, 130, 246, 0.1);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
}
.btn-link:hover { background: #3B82F6; color: #FFFFFF; }

/* ============================================================
   PROVIDERS SUMMARY
   ============================================================ */
.providers-summary {
    background: var(--cm-card-bg);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 12px;
    border: 1px solid var(--cm-border);
}
.summary-row { display: flex; align-items: center; gap: 8px; }
.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--cm-text-secondary);
    font-weight: 600;
}
.summary-value {
    font-size: 16px;
    font-weight: 700;
    color: var(--cm-text);
}
.summary-value.text-blue { color: #3B82F6; }

/* ============================================================
   CASH SECTION
   ============================================================ */
.cash-section {
    background: var(--cm-card-header);
    border-radius: 10px;
    padding: 16px;
    border: 1px solid var(--cm-border);
    margin-bottom: 16px;
    animation: slideDown 0.3s ease;
}
.cash-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    color: var(--cm-text);
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--cm-border);
}
.cash-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 6px;
}
.currency-symbol-large {
    position: absolute;
    left: 16px;
    font-size: 16px;
    font-weight: 700;
    color: #10B981;
    z-index: 1;
    pointer-events: none;
}
.cash-amount {
    width: 100%;
    padding: 14px 18px 14px 65px;
    border: 2px solid #10B981;
    border-radius: 10px;
    font-size: 20px;
    font-weight: 700;
    color: var(--cm-text);
    background: var(--cm-card-bg);
    outline: none;
    transition: all 0.3s ease;
}
.cash-amount:focus { box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
.cash-amount::placeholder { color: var(--cm-text-light); font-weight: 400; }
.cash-section small { font-size: 11px; color: var(--cm-text-secondary); }

/* ============================================================
   NO SOURCE MESSAGE
   ============================================================ */
.no-source-message {
    text-align: center;
    padding: 24px;
    color: var(--cm-text-secondary);
    background: var(--cm-hover);
    border-radius: 10px;
    border: 1px dashed var(--cm-border);
}
.no-source-message i {
    font-size: 28px;
    color: var(--cm-text-light);
    display: block;
    margin-bottom: 8px;
}
.no-source-message p {
    font-size: 13px;
    margin: 0;
}
.no-source-message strong { color: var(--cm-text); }

/* ============================================================
   GRAND TOTAL BAR
   ============================================================ */
.grand-total-bar {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.grand-total-split {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.split-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.15);
}
.split-label {
    font-size: 11px;
    color: rgba(255,255,255,0.7);
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}
.split-value {
    font-size: 15px;
    font-weight: 700;
    color: #FFFFFF;
}
.split-divider {
    font-size: 18px;
    color: rgba(255,255,255,0.5);
    font-weight: 700;
}
.grand-total-right {
    display: flex;
    align-items: center;
    gap: 12px;
}
.grand-label {
    font-size: 12px;
    color: rgba(255,255,255,0.7);
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 1px;
}
.grand-value {
    font-size: 24px;
    font-weight: 800;
    color: #FCD34D;
    letter-spacing: 0.5px;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 10px 22px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover {
    background: #8a0303;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
}
.btn-secondary {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-secondary:hover { background: var(--cm-border); color: var(--cm-text); }

.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    border-top: 1px solid var(--cm-border);
    background: var(--cm-card-header);
    flex-wrap: wrap;
}
.form-actions .btn-primary { flex: 1; justify-content: center; min-width: 180px; }

/* ============================================================
   TYPE INFO
   ============================================================ */
.type-info-card {
    background: var(--cm-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--cm-border);
}
.type-info-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--cm-text);
    margin: 0 0 12px 0;
}
.type-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.type-info-item {
    font-size: 13px;
    color: var(--cm-text);
    padding: 6px 10px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--cm-hover);
}
.type-info-item .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.type-blue .dot { background: #3B82F6; }
.type-green .dot { background: #10B981; }
.type-purple .dot { background: #8B5CF6; }
.type-red .dot { background: #DC2626; }
.type-orange .dot { background: #F59E0B; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .branch-card { padding: 10px 16px; font-size: 13px; }
    .form-row { grid-template-columns: 1fr; }
    .source-toggles { grid-template-columns: 1fr; }
    .providers-grid { grid-template-columns: 1fr; }
    .type-info-grid { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .form-section { padding: 16px; }
    .provider-header { flex-direction: column; align-items: flex-start; }
    .grand-total-bar { flex-direction: column; align-items: stretch; }
    .grand-total-split { justify-content: center; }
    .grand-total-right { justify-content: center; }
    .grand-value { font-size: 20px; }
}
@media (max-width: 480px) {
    .branch-card { flex-direction: column; text-align: center; gap: 4px; }
    .source-toggle-content { padding: 12px 14px; }
    .toggle-icon { width: 38px; height: 38px; font-size: 15px; }
    .toggle-info h4 { font-size: 13px; }
    .cash-amount { font-size: 16px; padding: 12px 14px 12px 55px; }
    .currency-symbol-large { font-size: 14px; left: 12px; }
    .split-item { padding: 4px 10px; }
    .split-value { font-size: 13px; }
}
</style>

<script>
// ============================================================
// BRANCH CHANGE
// ============================================================
function onBranchChange() {
    const branchId = document.getElementById('branchSelect').value;
    if (branchId) {
        window.location.href = 'add.php?branch=' + branchId;
    } else {
        window.location.href = 'add.php';
    }
}

// ============================================================
// FLOAT TOGGLE
// ============================================================
function onFloatToggle(enabled) {
    const providerSection = document.getElementById('providerSection');
    if (enabled) {
        providerSection.style.display = 'block';
    } else {
        providerSection.style.display = 'none';
        document.querySelectorAll('.provider-checkbox').forEach(cb => {
            cb.checked = false;
            const id = cb.id.replace('provider_check_', '');
            onProviderCheck(cb, id, true);
        });
    }
    updateNoSourceMessage();
    calculateTotals();
}

// ============================================================
// CASH TOGGLE
// ============================================================
function onCashToggle(enabled) {
    const cashSection = document.getElementById('cashSection');
    if (enabled) {
        cashSection.style.display = 'block';
        setTimeout(() => {
            const input = document.getElementById('cashAmount');
            if (input) input.focus();
        }, 100);
    } else {
        cashSection.style.display = 'none';
        const input = document.getElementById('cashAmount');
        if (input) input.value = '';
    }
    updateNoSourceMessage();
    calculateTotals();
}

// ============================================================
// UPDATE NO SOURCE MESSAGE
// ============================================================
function updateNoSourceMessage() {
    const floatEnabled = document.getElementById('includeFloat').checked;
    const cashEnabled = document.getElementById('includeCash').checked;
    const noSourceMsg = document.getElementById('noSourceMessage');
    
    if (!floatEnabled && !cashEnabled) {
        noSourceMsg.style.display = 'block';
    } else {
        noSourceMsg.style.display = 'none';
    }
}

// ============================================================
// PROVIDER CHECKBOX TOGGLE
// ============================================================
function onProviderCheck(checkbox, providerId, silent) {
    const card = checkbox.closest('.provider-card');
    const inputWrapper = document.getElementById('input_wrapper_' + providerId);
    const lockedBadge = document.getElementById('locked_badge_' + providerId);
    const amountInput = document.getElementById('provider_amount_' + providerId);
    
    if (checkbox.checked) {
        card.classList.add('selected');
        if (inputWrapper) inputWrapper.style.display = 'flex';
        if (lockedBadge) lockedBadge.style.display = 'none';
        if (amountInput && !silent) {
            setTimeout(() => amountInput.focus(), 100);
        }
    } else {
        card.classList.remove('selected');
        if (inputWrapper) inputWrapper.style.display = 'none';
        if (lockedBadge) lockedBadge.style.display = 'flex';
        if (amountInput) amountInput.value = '';
    }
    
    calculateTotals();
}

// ============================================================
// SELECT ALL PROVIDERS
// ============================================================
function selectAllProviders() {
    document.querySelectorAll('.provider-checkbox').forEach(cb => {
        cb.checked = true;
        const id = cb.id.replace('provider_check_', '');
        onProviderCheck(cb, id, true);
    });
    calculateTotals();
}

// ============================================================
// DESELECT ALL PROVIDERS
// ============================================================
function deselectAllProviders() {
    document.querySelectorAll('.provider-checkbox').forEach(cb => {
        cb.checked = false;
        const id = cb.id.replace('provider_check_', '');
        onProviderCheck(cb, id, true);
    });
    calculateTotals();
}

// ============================================================
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    let value = input.value.replace(/[^0-9.]/g, '');
    let parts = value.split('.');
    let integerPart = parts[0] || '';
    let decimalPart = parts[1] || '';
    
    if (integerPart.length > 0) {
        integerPart = parseInt(integerPart).toLocaleString('en-US');
    }
    if (decimalPart.length > 2) {
        decimalPart = decimalPart.substring(0, 2);
    }
    
    let formatted = integerPart;
    if (decimalPart.length > 0) {
        formatted += '.' + decimalPart;
    }
    input.value = formatted;
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
function calculateTotals() {
    let totalFloat = 0;
    let selectedCount = 0;
    let cashAmount = 0;
    
    const floatEnabled = document.getElementById('includeFloat').checked;
    const cashEnabled = document.getElementById('includeCash').checked;
    
    if (floatEnabled) {
        document.querySelectorAll('.provider-checkbox:checked').forEach(cb => {
            selectedCount++;
            const id = cb.id.replace('provider_check_', '');
            const input = document.getElementById('provider_amount_' + id);
            if (input) {
                const val = parseFloat(input.value.replace(/,/g, '')) || 0;
                totalFloat += val;
            }
        });
    }
    
    if (cashEnabled) {
        const cashInput = document.getElementById('cashAmount');
        if (cashInput && cashInput.value) {
            cashAmount = parseFloat(cashInput.value.replace(/,/g, '')) || 0;
        }
    }
    
    const grandTotal = totalFloat + cashAmount;
    
    const selectedCountEl = document.getElementById('selectedProvidersCount');
    const totalFloatEl = document.getElementById('totalFloatDisplay');
    const floatSummaryEl = document.getElementById('floatSummaryDisplay');
    const cashSummaryEl = document.getElementById('cashSummaryDisplay');
    const grandTotalEl = document.getElementById('grandTotalDisplay');
    
    if (selectedCountEl) selectedCountEl.textContent = selectedCount;
    if (totalFloatEl) totalFloatEl.textContent = 'TSh ' + formatNumberDisplay(totalFloat);
    if (floatSummaryEl) floatSummaryEl.textContent = 'TSh ' + formatNumberDisplay(totalFloat);
    if (cashSummaryEl) cashSummaryEl.textContent = 'TSh ' + formatNumberDisplay(cashAmount);
    if (grandTotalEl) grandTotalEl.textContent = 'TSh ' + formatNumberDisplay(grandTotal);
}

function formatNumberDisplay(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updateNoSourceMessage();
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) {
            html.classList.add('dark-mode');
        } else {
            html.classList.remove('dark-mode');
        }
    }
    
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
    
    // Form validation - ✅ Description NO LONGER required
    const form = document.getElementById('capitalForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const branchId = document.getElementById('branchSelect').value;
            const floatEnabled = document.getElementById('includeFloat').checked;
            const cashEnabled = document.getElementById('includeCash').checked;
            
            if (!branchId) {
                e.preventDefault();
                alert('Please select a branch first');
                return false;
            }
            
            if (!floatEnabled && !cashEnabled) {
                e.preventDefault();
                alert('Please enable Float or Cash (or both)');
                return false;
            }
            
            if (floatEnabled) {
                const selected = document.querySelectorAll('.provider-checkbox:checked');
                let hasAmount = false;
                selected.forEach(cb => {
                    const id = cb.id.replace('provider_check_', '');
                    const input = document.getElementById('provider_amount_' + id);
                    if (input) {
                        const val = parseFloat(input.value.replace(/,/g, '')) || 0;
                        if (val > 0) hasAmount = true;
                    }
                });
                
                if (selected.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one provider for Float');
                    return false;
                }
                if (!hasAmount) {
                    e.preventDefault();
                    alert('Please enter an amount for at least one provider');
                    return false;
                }
            }
            
            if (cashEnabled) {
                const cashInput = document.getElementById('cashAmount');
                const cashVal = parseFloat(cashInput.value.replace(/,/g, '')) || 0;
                if (cashVal <= 0) {
                    e.preventDefault();
                    alert('Please enter a Cash amount');
                    return false;
                }
            }
            
            // ✅ Description validation REMOVED
        });
    }
});
</script>

</body>
</html>