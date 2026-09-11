<?php
// ================================================================
// FILE: modules/capital_management/edit.php
// EDIT CAPITAL TRANSACTION
// ✅ FIXED: Dark mode uses html.dark-mode (consistent with topbar)
// ✅ FIXED: No session override
// ✅ NEW: Shows provider details when editing Float transactions
// ✅ NEW: Better UI with sections
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
// GET TRANSACTION DATA
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            p.provider_name,
            p.provider_type,
            p.icon_class as provider_icon,
            p.color_code as provider_color,
            bp.provider_code as branch_provider_code
        FROM capital_management cm
        LEFT JOIN employees e ON cm.employee_id = e.id
        LEFT JOIN branches b ON cm.branch_id = b.id
        LEFT JOIN providers p ON cm.reference_id = p.id AND cm.reference_module = 'provider'
        LEFT JOIN branch_providers bp ON bp.branch_id = cm.branch_id AND bp.provider_id = p.id
        WHERE cm.id = ?
    ");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transaction: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$transaction) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// ============================================================
// GET BRANCHES
// ============================================================
try {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
}

// ============================================================
// TYPE LABELS
// ============================================================
$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Capital Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

// ============================================================
// DETERMINE SOURCE
// ============================================================
$is_float = ($transaction['reference_module'] === 'provider' && !empty($transaction['provider_name']));
$source_label = $is_float ? 'Float (Provider)' : 'Cash (Manual)';

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_type = $_POST['transaction_type'] ?? '';
    $amount = floatval(str_replace(',', '', $_POST['amount'] ?? 0));
    $description = trim($_POST['description'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $branch_id = intval($_POST['branch_id'] ?? 0);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    
    $errors = [];
    
    if (empty($transaction_type)) {
        $errors[] = 'Please select transaction type';
    }
    if ($amount <= 0) {
        $errors[] = 'Amount must be greater than 0';
    }
    if (empty($description)) {
        $errors[] = 'Please enter description';
    }
    if ($branch_id <= 0) {
        $errors[] = 'Please select a branch';
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
            
            // Update
            $stmt = $db->prepare("UPDATE capital_management SET 
                branch_id = ?,
                branch = ?,
                transaction_date = ?,
                transaction_type = ?,
                amount = ?,
                description = ?,
                notes = ?,
                updated_at = NOW()
                WHERE id = ?");
            
            $result = $stmt->execute([
                $branch_id,
                $branch_name_selected,
                $transaction_date,
                $transaction_type,
                $amount,
                $description,
                $notes,
                $id
            ]);
            
            if ($result) {
                logActivity($user_id, 'Edit Capital', 'Capital Management', $id, 
                    json_encode(['old_amount' => $transaction['amount']]), 
                    json_encode(['new_amount' => $amount]));
                
                $_SESSION['success_message'] = 'Capital transaction updated successfully!';
                header('Location: view.php?id=' . $id);
                exit();
            } else {
                $error = 'Failed to update transaction. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log("Error updating capital transaction: " . $e->getMessage());
        }
    }
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
        <div class="branch-card">
            <i class="fas fa-store-alt"></i>
            <span class="branch-label">Branch:</span>
            <span class="branch-name"><?php echo htmlspecialchars($transaction['branch_name'] ?? 'Main'); ?></span>
            <?php if (!empty($transaction['branch_code'])): ?>
                <span class="branch-code-badge"><?php echo htmlspecialchars($transaction['branch_code']); ?></span>
            <?php endif; ?>
            <a href="view.php?id=<?php echo $id; ?>" class="branch-back-btn">
                <i class="fas fa-arrow-left"></i> Back to Details
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#bb0404;"></i> Edit Capital Transaction</h2>
                <p class="text-muted">Update transaction details</p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                    <i class="fas fa-eye"></i> View Details
                </a>
            </div>
        </div>

        <!-- ============================================================
        ERROR / SUCCESS MESSAGES
        ============================================================ -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        INFO CARD (Read-only transaction info)
        ============================================================ -->
        <div class="info-card">
            <div class="info-card-header">
                <div class="info-header-left">
                    <div class="info-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div>
                        <span class="info-label">Transaction Info</span>
                        <span class="info-number"><?php echo htmlspecialchars($transaction['capital_number']); ?></span>
                    </div>
                </div>
                <div class="info-header-right">
                    <span class="source-badge <?php echo $is_float ? 'source-float' : 'source-cash'; ?>">
                        <i class="fas <?php echo $is_float ? 'fa-university' : 'fa-money-bill-wave'; ?>"></i>
                        <?php echo $source_label; ?>
                    </span>
                </div>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-item-label">
                        <i class="fas fa-user-tie"></i> Created By
                    </span>
                    <span class="info-item-value"><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-item-label">
                        <i class="fas fa-calendar-plus"></i> Created At
                    </span>
                    <span class="info-item-value">
                        <?php echo date('d M Y H:i', strtotime($transaction['created_at'])); ?>
                    </span>
                </div>
                
                <?php if ($is_float): ?>
                    <div class="info-item provider-info-item">
                        <span class="info-item-label">
                            <i class="fas fa-university"></i> Provider
                        </span>
                        <div class="provider-display-inline">
                            <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($transaction['provider_color'] ?? '#0B5ED7'); ?>;">
                                <i class="<?php echo htmlspecialchars($transaction['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                            </div>
                            <span class="info-item-value">
                                <?php echo htmlspecialchars($transaction['provider_name']); ?>
                                <span class="badge-inline"><?php echo htmlspecialchars($transaction['branch_provider_code'] ?? 'N/A'); ?></span>
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="info-item">
                        <span class="info-item-label">
                            <i class="fas fa-money-bill-wave"></i> Source Type
                        </span>
                        <span class="info-item-value">Cash (Manual Entry)</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        FORM CARD
        ============================================================ -->
        <div class="form-card">
            <form method="POST" action="" class="capital-form" id="editForm">
                
                <!-- ============================================================
                SECTION 1: TRANSACTION DETAILS
                ============================================================ -->
                <div class="form-section">
                    <div class="section-title">
                        <span class="step-number">1</span>
                        <span>Transaction Details</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Transaction Type <span class="required">*</span></label>
                            <select name="transaction_type" class="form-control" required>
                                <?php foreach ($type_labels as $key => $type): ?>
                                    <option value="<?php echo $key; ?>" 
                                        <?php echo $transaction['transaction_type'] == $key ? 'selected' : ''; ?>>
                                        <?php echo $type['label']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Transaction Date <span class="required">*</span></label>
                            <input type="date" name="transaction_date" class="form-control" 
                                   value="<?php echo htmlspecialchars($transaction['transaction_date']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount <span class="required">*</span></label>
                            <div class="amount-input-wrapper">
                                <span class="currency-symbol">TSh</span>
                                <input type="text" name="amount" id="amountInput"
                                       class="form-control amount-input money-input"
                                       value="<?php echo number_format($transaction['amount'], 0, '.', ','); ?>"
                                       placeholder="0.00"
                                       oninput="formatMoneyInput(this)"
                                       required>
                            </div>
                            <small>Enter amount in Tanzanian Shillings</small>
                        </div>
                        <div class="form-group">
                            <label>Branch <span class="required">*</span></label>
                            <select name="branch_id" class="form-control" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" 
                                        <?php echo $transaction['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                        (<?php echo htmlspecialchars($b['branch_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                SECTION 2: DESCRIPTION
                ============================================================ -->
                <div class="form-section">
                    <div class="section-title">
                        <span class="step-number">2</span>
                        <span>Description</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Description <span class="required">*</span></label>
                            <textarea name="description" class="form-control" rows="3" required><?php echo htmlspecialchars($transaction['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($transaction['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                PREVIEW BAR
                ============================================================ -->
                <div class="preview-bar" id="previewBar">
                    <div class="preview-left">
                        <i class="fas fa-calculator"></i>
                        <span>New Amount:</span>
                    </div>
                    <div class="preview-value" id="previewAmount">TSh <?php echo number_format($transaction['amount'], 0, '.', ','); ?></div>
                </div>

                <!-- ============================================================
                FORM ACTIONS
                ============================================================ -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Transaction
                    </button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
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
.branch-card i { font-size: 16px; }
.branch-card .branch-label { font-weight: 500; font-size: 13px; opacity: 0.9; }
.branch-card .branch-name { font-weight: 700; font-size: 15px; }
.branch-card .branch-code-badge {
    background: rgba(255,255,255,0.15);
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}
.branch-card .branch-back-btn {
    margin-left: auto;
    color: #ffffff;
    text-decoration: none;
    font-size: 12px;
    padding: 5px 14px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.branch-card .branch-back-btn:hover {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
}

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
.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    position: relative;
    animation: slideDown 0.4s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
.alert i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }
.alert > div { flex: 1; }
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

/* ============================================================
   INFO CARD (Read-only)
   ============================================================ */
.info-card {
    background: var(--cm-card-bg);
    border-radius: 12px;
    border: 1px solid var(--cm-border);
    box-shadow: 0 1px 3px var(--cm-shadow);
    margin-bottom: 20px;
    overflow: hidden;
    animation: fadeInUp 0.4s ease;
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

.info-card-header {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #FFFFFF;
    flex-wrap: wrap;
    gap: 12px;
}
.info-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.info-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    border: 1px solid rgba(255,255,255,0.1);
}
.info-label {
    display: block;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,0.7);
}
.info-number {
    display: block;
    font-size: 16px;
    font-weight: 700;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
}

.source-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.source-float {
    background: rgba(59, 130, 246, 0.25);
    color: #BFDBFE;
    border: 1px solid rgba(59, 130, 246, 0.3);
}
.source-cash {
    background: rgba(16, 185, 129, 0.25);
    color: #A7F3D0;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;
}
.info-item {
    padding: 14px 24px;
    border-bottom: 1px solid var(--cm-border);
    border-right: 1px solid var(--cm-border);
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.info-item:nth-child(2n) { border-right: none; }
.info-item:nth-last-child(-n+2) { border-bottom: none; }
.info-item.provider-info-item {
    grid-column: span 2;
    border-right: none;
}

.info-item-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--cm-text-light);
}
.info-item-label i { color: #3B82F6; font-size: 11px; }
.info-item-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--cm-text);
}

.provider-display-inline {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 2px;
}
.provider-icon-sm {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 12px;
    flex-shrink: 0;
}
.badge-inline {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 6px;
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
    margin-left: 6px;
}

/* ============================================================
   FORM CARD
   ============================================================ */
.form-card {
    background: var(--cm-card-bg);
    border-radius: 12px;
    border: 1px solid var(--cm-border);
    box-shadow: 0 1px 3px var(--cm-shadow);
    margin-bottom: 20px;
    overflow: hidden;
    animation: fadeInUp 0.4s ease 0.1s both;
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

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 12px;
}
.form-row:last-child { margin-bottom: 0; }

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
textarea.form-control {
    resize: vertical;
    min-height: 70px;
    font-family: 'Inter', sans-serif;
}
.form-group small {
    font-size: 11px;
    color: var(--cm-text-secondary);
    margin-top: 2px;
}

/* ============================================================
   AMOUNT INPUT
   ============================================================ */
.amount-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.currency-symbol {
    position: absolute;
    left: 14px;
    font-size: 14px;
    font-weight: 700;
    color: #bb0404;
    z-index: 1;
    pointer-events: none;
}
.amount-input {
    padding-left: 55px !important;
    font-size: 16px !important;
    font-weight: 700;
    text-align: right;
}

/* ============================================================
   PREVIEW BAR
   ============================================================ */
.preview-bar {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    padding: 14px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #FFFFFF;
}
.preview-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 600;
}
.preview-left i { font-size: 18px; }
.preview-value {
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 0.5px;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    border-top: 1px solid var(--cm-border);
    background: var(--cm-card-header);
    flex-wrap: wrap;
}

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
.btn-primary {
    background: #bb0404;
    color: white;
    flex: 1;
    justify-content: center;
    min-width: 180px;
}
.btn-primary:hover {
    background: #8a0303;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
}
.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
.btn-secondary {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-secondary:hover {
    background: var(--cm-border);
    color: var(--cm-text);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .info-grid { grid-template-columns: 1fr; }
    .info-item { border-right: none; }
    .info-item.provider-info-item { grid-column: span 1; }
    
    .form-row { grid-template-columns: 1fr; }
    .form-section { padding: 16px; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    
    .info-card-header { padding: 14px 18px; }
    .info-item { padding: 12px 18px; }
    .preview-value { font-size: 18px; }
}

@media (max-width: 480px) {
    .branch-card { flex-direction: column; text-align: center; gap: 6px; }
    .branch-card .branch-back-btn { margin-left: 0; }
    .info-number { font-size: 13px; }
    .preview-value { font-size: 16px; }
}
</style>

<script>
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
    
    // Update preview
    updatePreview();
}

// ============================================================
// UPDATE PREVIEW
// ============================================================
function updatePreview() {
    const input = document.getElementById('amountInput');
    const previewEl = document.getElementById('previewAmount');
    
    if (input && previewEl) {
        const val = parseFloat(input.value.replace(/,/g, '')) || 0;
        previewEl.textContent = 'TSh ' + val.toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });
    }
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // Initialize preview
    updatePreview();
    
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
    
    // Auto-hide alerts
    var errorAlert = document.querySelector('.alert-danger');
    if (errorAlert) {
        setTimeout(() => { errorAlert.style.display = 'none'; }, 8000);
    }
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => { successAlert.style.display = 'none'; }, 5000);
    }
    
    // Form validation
    const form = document.getElementById('editForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const amount = document.getElementById('amountInput');
            const amountVal = parseFloat(amount.value.replace(/,/g, '')) || 0;
            
            if (amountVal <= 0) {
                e.preventDefault();
                alert('Please enter an amount greater than 0');
                amount.focus();
                return false;
            }
            
            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;
        });
    }
    
    console.log('=== CAPITAL EDIT.PHP ===');
    console.log('Transaction ID: <?php echo $id; ?>');
    console.log('Number: <?php echo htmlspecialchars($transaction['capital_number']); ?>');
    console.log('Type: <?php echo $transaction['transaction_type']; ?>');
    console.log('Source: <?php echo $is_float ? "Float" : "Cash"; ?>');
});
</script>

</body>
</html>