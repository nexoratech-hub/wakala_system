<?php
// ================================================================
// FILE: modules/expenses/categories.php
// EXPENSE CATEGORIES - MANAGE CATEGORIES
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';

// Only admin and super_admin can manage categories
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$error = '';
$success = '';

try {
    // Get categories
    $stmt = $db->prepare("SELECT * FROM expense_categories ORDER BY category_name");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error loading categories: " . $e->getMessage());
    $categories = [];
}

// Handle add category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    try {
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($category_name)) {
            $error = 'Please enter category name';
        } else {
            $stmt = $db->prepare("INSERT INTO expense_categories (category_name, description) VALUES (?, ?)");
            $stmt->execute([$category_name, $description]);
            $success = 'Category added successfully!';
            header('Refresh: 1; URL=categories.php');
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = 'Category already exists!';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
        error_log("Error adding category: " . $e->getMessage());
    }
}

// Handle edit category
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_category'])) {
    try {
        $id = intval($_POST['category_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id <= 0 || empty($category_name)) {
            $error = 'Invalid data';
        } else {
            $stmt = $db->prepare("UPDATE expense_categories SET category_name = ?, description = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$category_name, $description, $is_active, $id]);
            $success = 'Category updated successfully!';
            header('Refresh: 1; URL=categories.php');
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = 'Category name already exists!';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
        error_log("Error updating category: " . $e->getMessage());
    }
}

// Handle delete category
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        
        // Check if category is in use
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM expenses WHERE category = (SELECT category_name FROM expense_categories WHERE id = ?)");
        $stmt->execute([$id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        if ($count > 0) {
            $error = 'Cannot delete category that is in use by expenses.';
        } else {
            $stmt = $db->prepare("DELETE FROM expense_categories WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Category deleted successfully!';
            header('Refresh: 1; URL=categories.php');
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error deleting category: " . $e->getMessage());
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
                <h2><i class="fas fa-tags" style="color:#bb0404;"></i> Expense Categories</h2>
                <p class="text-muted">Manage expense categories</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Expenses
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Add Category Form -->
        <div class="form-card">
            <h4><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add New Category</h4>
            <form method="POST" action="" class="category-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>Category Name <span class="required">*</span></label>
                        <input type="text" name="category_name" class="form-control" placeholder="Enter category name" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" placeholder="Enter description (optional)">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" name="add_category" class="btn btn-primary"><i class="fas fa-plus"></i> Add Category</button>
                </div>
            </form>
        </div>

        <!-- Categories List -->
        <div class="table-container">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Categories</h4>
                <span class="record-count"><?php echo count($categories); ?> categories</span>
            </div>
            
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($categories) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cat['description'] ?? '-'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $cat['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <?php if ($cat['is_system']): ?>
                                            <span class="system-badge">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="editCategory(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['category_name']); ?>', '<?php echo htmlspecialchars($cat['description'] ?? ''); ?>', <?php echo $cat['is_active']; ?>)" 
                                                    class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if (!$cat['is_system']): ?>
                                                <button onclick="deleteCategory(<?php echo $cat['id']; ?>)" class="btn-action btn-delete" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center no-data">
                                    <i class="fas fa-tags" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No categories found</p>
                                    <p style="color:var(--text-muted);font-size:12px;">Add your first category using the form above</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-edit"></i> Edit Category</h4>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="category_id" id="edit_category_id">
                    <input type="hidden" name="edit_category" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Category Name <span class="required">*</span></label>
                            <input type="text" name="category_name" id="edit_category_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" name="description" id="edit_description" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                                <span>Active</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Category</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 20px 24px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.form-card h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
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

.form-actions {
    display: flex;
    gap: 12px;
    padding-top: 8px;
}

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

.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--border-color);
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.table-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.table-header h4 i { color: #bb0404; margin-right: 8px; }
.record-count { font-size: 12px; color: var(--text-muted); }

.table-responsive { overflow-x: auto; }
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #bb0404;
}
.data-table thead th {
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.data-table tbody td { padding: 10px 12px; color: var(--text-secondary); }

.status-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.status-badge.active { background: #D1FAE5; color: #065F46; }
.status-badge.inactive { background: #FEE2E2; color: #991B1B; }

.system-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    background: #EDE9FE;
    color: #6D28D9;
    margin-left: 4px;
}

.action-buttons { display: flex; gap: 4px; }
.btn-action {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 13px;
}

.btn-edit { background: #D1FAE5; color: #065F46; }
.btn-edit:hover { background: #065F46; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

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

.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
}

.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    max-width: 600px;
    width: 100%;
    padding: 24px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.modal-header h4 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0 8px;
    line-height: 1;
}

.modal-close:hover { color: var(--text-primary); }

.no-data { padding: 40px 20px; text-align: center; }

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
function editCategory(id, name, description, isActive) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_is_active').checked = isActive == 1;
    document.getElementById('editModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function deleteCategory(id) {
    if (confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
        window.location.href = 'categories.php?delete=' + id;
    }
}

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});

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