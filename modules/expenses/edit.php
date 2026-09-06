<?php
// ================================================================
// FILE: modules/expenses/edit.php
// EDIT EXPENSE
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

// Check permission
if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    // Get expense
    $sql = "SELECT * FROM expenses WHERE id = ?";
    $params = [$id];
    
    // Employees can only edit their own expenses
    if ($role === 'employee') {
        $sql .= " AND employee_id = ?";
        $params[] = $user_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching expense: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$expense) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

try {
    // Get categories
    $stmt = $db->prepare("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY category_name");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get branches (for admin/super_admin)
    if ($role === 'admin' || $role === 'super_admin') {
        $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
        $stmt->execute();
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $branches = [];
    }

} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $categories = [];
    $branches = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $expense_name = trim($_POST['expense_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $is_business = isset($_POST['is_business_expense']) ? 1 : 0;
        $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $is_salary_related = isset($_POST['is_salary_related']) ? 1 : 0;
        $salary_reference = isset($_POST['salary_reference']) ? intval($_POST['salary_reference']) : null;

        // Validate
        if (empty($expense_name)) {
            $error = 'Please enter expense name';
        } elseif (empty($category)) {
            $error = 'Please select category';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than 0';
        } elseif (empty($expense_date)) {
            $error = 'Please select expense date';
        } else {
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

            // Upload receipt if any
            $receipt_path = $expense['receipt_path'];
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/expenses/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                // Delete old receipt if exists
                if ($receipt_path && file_exists('../../' . $receipt_path)) {
                    unlink('../../' . $receipt_path);
                }
                $file_name = time() . '_' . $_FILES['receipt']['name'];
                $target_file = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target_file)) {
                    $receipt_path = 'uploads/expenses/' . $file_name;
                }
            }

            $sql = "UPDATE expenses SET 
                branch = ?,
                branch_id = ?,
                expense_date = ?,
                expense_name = ?,
                category = ?,
                amount = ?,
                description = ?,
                notes = ?,
                is_business_expense = ?,
                is_salary_related = ?,
                salary_reference = ?,
                receipt_path = ?
                WHERE id = ?";

            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $branch_name,
                $branch_id > 0 ? $branch_id : null,
                $expense_date,
                $expense_name,
                $category,
                $amount,
                $description,
                $notes,
                $is_business,
                $is_salary_related,
                $salary_reference,
                $receipt_path,
                $id
            ]);

            if ($result) {
                logActivity($user_id, 'Edit Expense', 'Expenses', $id);
                $success = 'Expense updated successfully!';
                header('Refresh: 2; URL=view.php?id=' . $id);
            } else {
                $error = 'Failed to update expense. Please try again.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error updating expense: " . $e->getMessage());
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
                <h2><i class="fas fa-edit" style="color:#bb0404;"></i> Edit Expense</h2>
                <p class="text-muted">Update expense details</p>
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
            <form method="POST" action="" enctype="multipart/form-data" class="expense-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>Expense Number</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($expense['expense_number']); ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Expense Date <span class="required">*</span></label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo htmlspecialchars($expense['expense_date']); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Category <span class="required">*</span></label>
                        <select name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['category_name']); ?>" <?php echo $expense['category'] == $c['category_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expense Name <span class="required">*</span></label>
                        <input type="text" name="expense_name" class="form-control" value="<?php echo htmlspecialchars($expense['expense_name']); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Amount <span class="required">*</span></label>
                        <input type="number" name="amount" class="form-control" value="<?php echo htmlspecialchars($expense['amount']); ?>" step="0.01" min="0.01" required>
                    </div>
                    <?php if ($role === 'admin' || $role === 'super_admin'): ?>
                    <div class="form-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="0">Main Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo $expense['branch_id'] == $b['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($role === 'admin' || $role === 'super_admin'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Receipt (Optional)</label>
                        <input type="file" name="receipt" class="form-control" accept="image/*,.pdf">
                        <?php if ($expense['receipt_path']): ?>
                            <small class="form-text">
                                Current receipt: <a href="../../<?php echo htmlspecialchars($expense['receipt_path']); ?>" target="_blank">View</a>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label>Created By</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(getEmployeeName($expense['employee_id'])); ?>" disabled>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($expense['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($expense['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_business_expense" value="1" <?php echo $expense['is_business_expense'] ? 'checked' : ''; ?>>
                            <span>Business Expense</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_salary_related" value="1" <?php echo $expense['is_salary_related'] ? 'checked' : ''; ?>>
                            <span>Salary Related</span>
                        </label>
                        <?php if ($expense['salary_reference']): ?>
                            <small class="form-text">Linked to salary: <?php echo htmlspecialchars($expense['salary_reference']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Expense</button>
                    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Reuse styles from add.php */
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
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

.form-control:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.form-control[type="file"] {
    padding: 6px 10px;
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

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    cursor: pointer;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #bb0404;
}

.form-text {
    font-size: 12px;
    color: var(--text-muted);
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

/* Dark Mode */
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
    --shadow-hover: rgba(0,0,0,0.08);
}

body.dark-mode {
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
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

.dark-mode-toggle {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.dark-mode-btn {
    background: var(--bg-card);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.dark-mode-btn:hover {
    background: var(--bg-table-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    .form-actions {
        flex-direction: column;
    }
    .form-actions .btn {
        width: 100%;
        justify-content: center;
    }
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