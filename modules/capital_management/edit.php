<?php
// ================================================================
// FILE: modules/capital_management/edit.php
// EDIT CAPITAL TRANSACTION
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get transaction
    $stmt = $db->prepare("SELECT * FROM capital_management WHERE id = ?");
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

try {
    // Get branches
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
    error_log("Error fetching branches: " . $e->getMessage());
}

$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Capital Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transaction_type = $_POST['transaction_type'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $branch_id = intval($_POST['branch_id'] ?? 0);
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    
    if (empty($transaction_type)) {
        $error = 'Please select transaction type';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than 0';
    } elseif (empty($description)) {
        $error = 'Please enter description';
    } else {
        try {
            // Get branch name
            $branch_name = 'Main';
            if ($branch_id > 0) {
                $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
                $stmt->execute([$branch_id]);
                $branch = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($branch) {
                    $branch_name = $branch['branch_name'];
                }
            }
            
            $stmt = $db->prepare("UPDATE capital_management SET 
                branch_id = ?,
                branch = ?,
                transaction_date = ?,
                transaction_type = ?,
                amount = ?,
                description = ?,
                notes = ?
                WHERE id = ?");
            
            $result = $stmt->execute([
                $branch_id > 0 ? $branch_id : null,
                $branch_name,
                $transaction_date,
                $transaction_type,
                $amount,
                $description,
                $notes,
                $id
            ]);
            
            if ($result) {
                logActivity($user_id, 'Edit Capital', 'Capital Management', $id);
                $success = 'Capital transaction updated successfully!';
                header('Refresh: 2; URL=view.php?id=' . $id);
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
        
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#bb0404;"></i> Edit Capital Transaction</h2>
                <p class="text-muted">Update capital transaction details</p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Details
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
                        <label>Transaction Number</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($transaction['capital_number']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Transaction Date <span class="required">*</span></label>
                        <input type="date" name="transaction_date" class="form-control" 
                               value="<?php echo htmlspecialchars($transaction['transaction_date']); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Transaction Type <span class="required">*</span></label>
                        <select name="transaction_type" class="form-control" required>
                            <?php foreach ($type_labels as $key => $type): ?>
                                <option value="<?php echo $key; ?>" <?php echo $transaction['transaction_type'] == $key ? 'selected' : ''; ?>>
                                    <?php echo $type['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount <span class="required">*</span></label>
                        <input type="number" name="amount" class="form-control" 
                               value="<?php echo htmlspecialchars($transaction['amount']); ?>" step="0.01" min="0.01" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="0">Main Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $transaction['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Created By</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(getEmployeeName($transaction['employee_id'])); ?>" disabled>
                    </div>
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

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Transaction</button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
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
    color: var(--text-secondary);
}

.form-group label .required { color: #DC2626; }

.form-control {
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
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

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
}
</style>

<script>
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const btn = document.getElementById('darkModeToggle');
    if (document.body.classList.contains('dark-mode')) {
        btn.querySelector('i').className = 'fas fa-sun';
        btn.querySelector('span').textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        btn.querySelector('i').className = 'fas fa-moon';
        btn.querySelector('span').textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        const btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.querySelector('i').className = 'fas fa-sun';
            btn.querySelector('span').textContent = 'Light Mode';
        }
    }
});
</script>

</body>
</html>