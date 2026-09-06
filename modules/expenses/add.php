<?php
// ================================================================
// FILE: modules/expenses/add.php
// ADD EXPENSE
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

    // Get employee's branch
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_branch_id = $employee['branch_id'] ?? null;

} catch (PDOException $e) {
    error_log("Error loading data: " . $e->getMessage());
    $categories = [];
    $branches = [];
    $user_branch_id = null;
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
        $branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : $user_branch_id;
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
            // Generate expense number
            $expense_number = generateNumber('EXP');
            
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
            $receipt_path = null;
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/expenses/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_name = time() . '_' . $_FILES['receipt']['name'];
                $target_file = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target_file)) {
                    $receipt_path = 'uploads/expenses/' . $file_name;
                }
            }

            $sql = "INSERT INTO expenses (
                expense_number, employee_id, branch, branch_id, expense_date,
                expense_name, category, amount, description, notes,
                is_business_expense, is_salary_related, salary_reference,
                receipt_path
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $db->prepare($sql);
            $result = $stmt->execute([
                $expense_number,
                $user_id,
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
                $receipt_path
            ]);

            if ($result) {
                $new_id = $db->lastInsertId();
                
                // Log activity
                logActivity($user_id, 'Add Expense', 'Expenses', $new_id, null, json_encode([
                    'expense_number' => $expense_number,
                    'category' => $category,
                    'amount' => $amount,
                    'is_business' => $is_business
                ]));
                
                $success = 'Expense added successfully!';
                header('Refresh: 2; URL=view.php?id=' . $new_id);
            } else {
                $error = 'Failed to add expense. Please try again.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error adding expense: " . $e->getMessage());
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
                <h2><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add Expense</h2>
                <p class="text-muted">Record a new expense</p>
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
            <form method="POST" action="" enctype="multipart/form-data" class="expense-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>Expense Date <span class="required">*</span></label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Category <span class="required">*</span></label>
                        <select name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['category_name']); ?>">
                                    <?php echo htmlspecialchars($c['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Expense Name <span class="required">*</span></label>
                        <input type="text" name="expense_name" class="form-control" placeholder="Enter expense name" required>
                    </div>
                    <div class="form-group">
                        <label>Amount <span class="required">*</span></label>
                        <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                </div>

                <?php if ($role === 'admin' || $role === 'super_admin'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-control">
                            <option value="0">Main Branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo ($user_branch_id == $b['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Receipt (Optional)</label>
                        <input type="file" name="receipt" class="form-control" accept="image/*,.pdf">
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the expense"></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_business_expense" value="1" checked>
                            <span>Business Expense</span>
                        </label>
                        <small class="form-text text-muted">Check if this is a business-related expense</small>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_salary_related" value="1">
                            <span>Salary Related</span>
                        </label>
                        <small class="form-text text-muted">Check if this is related to salary payments</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Expense</button>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
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