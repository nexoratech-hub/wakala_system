<?php
// ================================================================
// FILE: modules/capital_management/add.php
// ADD CAPITAL TRANSACTION
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

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

// Get selected branch from session
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
if (isset($_GET['branch'])) {
    $_SESSION['selected_branch'] = $selected_branch;
} elseif (isset($_SESSION['selected_branch']) && !isset($_GET['branch'])) {
    $selected_branch = $_SESSION['selected_branch'];
}
$selected_branch = $selected_branch ?? 0;

// Get branches
try {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
    error_log("Error fetching branches: " . $e->getMessage());
}

// Get branch name for display
$branch_name = 'Main Branch';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            break;
        }
    }
}

// Get employees
try {
    $stmt = $db->prepare("SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
    error_log("Error fetching employees: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_type = $_POST['transaction_type'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $branch_id = intval($_POST['branch_id'] ?? 0);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    $reference_id = !empty($_POST['reference_id']) ? intval($_POST['reference_id']) : null;
    $reference_module = $_POST['reference_module'] ?? null;
    
    // Validate
    if (empty($transaction_type)) {
        $error = 'Please select transaction type';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than 0';
    } elseif (empty($description)) {
        $error = 'Please enter description';
    } else {
        try {
            // Generate capital number
            $capital_number = generateNumber('CAP');
            
            // Get branch name
            $branch_name_selected = 'Main';
            if ($branch_id > 0) {
                $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
                $stmt->execute([$branch_id]);
                $branch = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($branch) {
                    $branch_name_selected = $branch['branch_name'];
                }
            }
            
            // Insert
            $sql = "INSERT INTO capital_management (
                capital_number, employee_id, branch_id, branch, transaction_date,
                transaction_type, amount, description, reference_id, reference_module, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $capital_number,
                $user_id,
                $branch_id > 0 ? $branch_id : null,
                $branch_name_selected,
                $transaction_date,
                $transaction_type,
                $amount,
                $description,
                $reference_id,
                $reference_module,
                $notes
            ]);
            
            if ($result) {
                $new_id = $db->lastInsertId();
                
                // Log activity
                logActivity($user_id, 'Add Capital', 'Capital Management', $new_id, null, json_encode([
                    'type' => $transaction_type,
                    'amount' => $amount,
                    'description' => $description
                ]));
                
                $success = 'Capital transaction added successfully!';
                
                // Redirect after 2 seconds
                header('Refresh: 2; URL=index.php');
            } else {
                $error = 'Failed to add transaction. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log("Error adding capital transaction: " . $e->getMessage());
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
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add Capital Transaction</h2>
                <p class="text-muted">Record new capital transaction</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" class="capital-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>Transaction Type <span class="required">*</span></label>
                        <select name="transaction_type" class="form-control" required onchange="toggleTypeFields(this.value)">
                            <option value="">Select Type</option>
                            <?php foreach ($type_labels as $key => $type): ?>
                                <option value="<?php echo $key; ?>"><?php echo $type['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Transaction Date <span class="required">*</span></label>
                        <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount <span class="required">*</span></label>
                        <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="0">Main Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" id="referenceFields" style="display:none;">
                    <div class="form-group">
                        <label>Reference Module</label>
                        <select name="reference_module" class="form-control" id="refModule">
                            <option value="">Select Module</option>
                            <option value="commission">Commission</option>
                            <option value="expense">Expense</option>
                            <option value="salary">Salary</option>
                            <option value="cash_out">Cash Out</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reference ID</label>
                        <input type="number" name="reference_id" class="form-control" placeholder="Reference ID" id="refId">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Description <span class="required">*</span></label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the transaction" required></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Transaction</button>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

        <div class="type-info-card">
            <h4><i class="fas fa-info-circle" style="color:#bb0404;"></i> Transaction Types</h4>
            <div class="type-info-grid">
                <div class="type-info-item type-blue"><span class="dot"></span> <strong>Opening Capital</strong> - Initial capital when starting</div>
                <div class="type-info-item type-green"><span class="dot"></span> <strong>Additional Capital</strong> - Adding more capital</div>
                <div class="type-info-item type-purple"><span class="dot"></span> <strong>Profit Allocation</strong> - Allocating profit to capital</div>
                <div class="type-info-item type-red"><span class="dot"></span> <strong>Capital Cash Out</strong> - Withdrawing from capital</div>
                <div class="type-info-item type-orange"><span class="dot"></span> <strong>Adjustment</strong> - Correcting capital balance</div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   BRANCH CARD - RED
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
}

.branch-card i {
    font-size: 18px;
}

.branch-card .branch-label {
    font-weight: 500;
    font-size: 13px;
    opacity: 0.9;
}

.branch-card .branch-name {
    font-weight: 700;
    font-size: 15px;
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
    color: #1f2937;
    margin: 0;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: #6b7280;
    margin: 4px 0 0 0;
}

.header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* ============================================================
   FORM CARD
   ============================================================ */
.form-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 24px;
    border: 1px solid #e5e7eb;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}

.form-group label .required {
    color: #DC2626;
}

.form-control {
    padding: 10px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    color: #1f2937;
    background: #f9fafb;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 60px;
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 8px 18px;
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
}
.btn-primary:hover {
    background: #8a0303;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #e5e7eb;
}
.btn-secondary:hover {
    background: #e5e7eb;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #A7F3D0;
}
.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

/* ============================================================
   TYPE INFO CARD
   ============================================================ */
.type-info-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid #e5e7eb;
}

.type-info-card h4 {
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 12px 0;
}

.type-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.type-info-item {
    font-size: 13px;
    color: #374151;
    padding: 6px 10px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
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
    .branch-card {
        padding: 10px 16px;
        font-size: 13px;
        flex-wrap: wrap;
    }
    
    .branch-card .branch-name {
        font-size: 14px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    .type-info-grid {
        grid-template-columns: 1fr;
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 480px) {
    .branch-card {
        flex-direction: column;
        text-align: center;
        gap: 4px;
    }
}
</style>

<script>
function toggleTypeFields(type) {
    const refFields = document.getElementById('referenceFields');
    if (type === 'profit_allocation' || type === 'cash_out' || type === 'adjustment') {
        refFields.style.display = 'grid';
    } else {
        refFields.style.display = 'none';
    }
}
</script>

</body>
</html>