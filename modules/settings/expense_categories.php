<?php
// ================================================================
// FILE: modules/settings/expense_categories.php
// EXPENSE CATEGORIES MANAGEMENT
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

// Only admin and super_admin can access settings
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$error = '';
$success = '';

// ============================================================
// GET CATEGORIES
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT 
            ec.*,
            (SELECT COUNT(*) FROM expenses WHERE category = ec.category_name) as usage_count,
            (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE category = ec.category_name) as total_amount
        FROM expense_categories ec
        ORDER BY ec.is_system DESC, ec.category_name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary stats
    $total_categories = count($categories);
    $active_count = 0;
    $inactive_count = 0;
    $system_count = 0;
    $custom_count = 0;
    
    foreach ($categories as $cat) {
        if ($cat['is_active']) $active_count++;
        else $inactive_count++;
        
        if ($cat['is_system']) $system_count++;
        else $custom_count++;
    }
    
} catch (PDOException $e) {
    error_log("Error loading categories: " . $e->getMessage());
    $categories = [];
    $total_categories = 0;
    $active_count = 0;
    $inactive_count = 0;
    $system_count = 0;
    $custom_count = 0;
}

// ============================================================
// HANDLE ADD CATEGORY
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_category'])) {
    try {
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($category_name)) {
            $error = 'Category name is required';
        } else {
            // Check if category already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM expense_categories WHERE category_name = ?");
            $stmt->execute([$category_name]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Category name already exists!';
            } else {
                $stmt = $db->prepare("INSERT INTO expense_categories (category_name, description, is_active, is_system) VALUES (?, ?, ?, 0)");
                $stmt->execute([$category_name, $description, $is_active]);
                
                logActivity($_SESSION['user_id'], 'Add Expense Category', 'Settings', $db->lastInsertId(), null, json_encode([
                    'category_name' => $category_name,
                    'description' => $description
                ]));
                
                $success = 'Category added successfully!';
                header('Refresh: 1; URL=expense_categories.php');
            }
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = 'Category name already exists!';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
        error_log("Error adding category: " . $e->getMessage());
    }
}

// ============================================================
// HANDLE EDIT CATEGORY
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_category'])) {
    try {
        $id = intval($_POST['category_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id <= 0 || empty($category_name)) {
            $error = 'Invalid data';
        } else {
            // Check if another category with same name exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM expense_categories WHERE category_name = ? AND id != ?");
            $stmt->execute([$category_name, $id]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Category name already exists!';
            } else {
                // Get old data for logging
                $stmt = $db->prepare("SELECT category_name FROM expense_categories WHERE id = ?");
                $stmt->execute([$id]);
                $old_name = $stmt->fetchColumn();
                
                // Update category
                $stmt = $db->prepare("UPDATE expense_categories SET category_name = ?, description = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$category_name, $description, $is_active, $id]);
                
                // If name changed, update expenses table
                if ($old_name && $old_name !== $category_name) {
                    $stmt = $db->prepare("UPDATE expenses SET category = ? WHERE category = ?");
                    $stmt->execute([$category_name, $old_name]);
                }
                
                logActivity($_SESSION['user_id'], 'Edit Expense Category', 'Settings', $id, null, json_encode([
                    'old_name' => $old_name,
                    'new_name' => $category_name
                ]));
                
                $success = 'Category updated successfully!';
                header('Refresh: 1; URL=expense_categories.php');
            }
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

// ============================================================
// HANDLE DELETE CATEGORY
// ============================================================
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        
        // Get category info
        $stmt = $db->prepare("SELECT category_name, is_system FROM expense_categories WHERE id = ?");
        $stmt->execute([$id]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cat) {
            $error = 'Category not found.';
        } elseif ($cat['is_system']) {
            $error = 'Cannot delete system category. You can only deactivate it.';
        } else {
            // Check if category is in use
            $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE category = ?");
            $stmt->execute([$cat['category_name']]);
            $usage = $stmt->fetchColumn();
            
            if ($usage > 0) {
                $error = 'Cannot delete category. It is used in ' . $usage . ' expense(s). Deactivate it instead.';
            } else {
                $stmt = $db->prepare("DELETE FROM expense_categories WHERE id = ?");
                $stmt->execute([$id]);
                
                logActivity($_SESSION['user_id'], 'Delete Expense Category', 'Settings', $id, null, json_encode([
                    'category_name' => $cat['category_name']
                ]));
                
                $success = 'Category deleted successfully!';
                header('Refresh: 1; URL=expense_categories.php');
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error deleting category: " . $e->getMessage());
    }
}

// ============================================================
// HANDLE TOGGLE STATUS
// ============================================================
if (isset($_GET['toggle'])) {
    try {
        $id = intval($_GET['toggle']);
        
        $stmt = $db->prepare("SELECT category_name, is_active FROM expense_categories WHERE id = ?");
        $stmt->execute([$id]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cat) {
            $new_status = $cat['is_active'] ? 0 : 1;
            $stmt = $db->prepare("UPDATE expense_categories SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            logActivity($_SESSION['user_id'], 'Toggle Expense Category', 'Settings', $id, null, json_encode([
                'category_name' => $cat['category_name'],
                'new_status' => $new_status
            ]));
            
            $success = 'Category status updated successfully!';
            header('Refresh: 1; URL=expense_categories.php');
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error toggling category: " . $e->getMessage());
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-tags" style="color:#bb0404;"></i> Expense Categories</h2>
                <p class="text-muted">Manage expense categories for your business</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Settings
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card summary-blue">
                <div class="summary-icon">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Categories</span>
                    <span class="summary-value"><?php echo number_format($total_categories); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-green">
                <div class="summary-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Active</span>
                    <span class="summary-value"><?php echo number_format($active_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-orange">
                <div class="summary-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Inactive</span>
                    <span class="summary-value"><?php echo number_format($inactive_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-purple">
                <div class="summary-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">System / Custom</span>
                    <span class="summary-value"><?php echo $system_count; ?> / <?php echo $custom_count; ?></span>
                </div>
            </div>
        </div>

        <!-- Add Category Form -->
        <div class="form-card">
            <h4><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add New Category</h4>
            <form method="POST" action="" class="category-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>Category Name <span class="required">*</span></label>
                        <input type="text" name="category_name" class="form-control" placeholder="e.g., Office Supplies" required maxlength="50">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g., Office supplies and materials" maxlength="200">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <span>Active</span>
                        </label>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" name="add_category" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
            </form>
        </div>

        <!-- Categories List -->
        <div class="table-container">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Expense Categories</h4>
                <div class="table-header-right">
                    <div class="table-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search categories..." oninput="filterTable()">
                    </div>
                    <span class="record-count"><?php echo count($categories); ?> categories</span>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="data-table" id="categoriesTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th style="width: 100px;">Type</th>
                            <th style="width: 100px;">Usage</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($categories) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr class="category-row" data-search="<?php echo htmlspecialchars(strtolower($cat['category_name'] . ' ' . $cat['description'])); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($cat['category_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="desc-text"><?php echo htmlspecialchars($cat['description'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($cat['is_system']): ?>
                                            <span class="type-badge type-system">
                                                <i class="fas fa-lock"></i> System
                                            </span>
                                        <?php else: ?>
                                            <span class="type-badge type-custom">
                                                <i class="fas fa-user"></i> Custom
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cat['usage_count'] > 0): ?>
                                            <span class="usage-badge" title="Total: <?php echo formatCurrency($cat['total_amount']); ?>">
                                                <i class="fas fa-receipt"></i> <?php echo number_format($cat['usage_count']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="usage-empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $cat['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $cat['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="editCategory(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars(addslashes($cat['category_name'])); ?>', '<?php echo htmlspecialchars(addslashes($cat['description'] ?? '')); ?>', <?php echo $cat['is_active']; ?>, <?php echo $cat['is_system']; ?>)" 
                                                    class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <a href="?toggle=<?php echo $cat['id']; ?>" 
                                               class="btn-action <?php echo $cat['is_active'] ? 'btn-warning' : 'btn-success'; ?>" 
                                               title="<?php echo $cat['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                               onclick="return confirm('<?php echo $cat['is_active'] ? 'Deactivate' : 'Activate'; ?> this category?');">
                                                <i class="fas fa-<?php echo $cat['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                            </a>
                                            
                                            <?php if (!$cat['is_system'] && $cat['usage_count'] == 0): ?>
                                                <a href="?delete=<?php echo $cat['id']; ?>" 
                                                   class="btn-action btn-delete" 
                                                   title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this category?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-action btn-disabled" 
                                                        title="<?php echo $cat['is_system'] ? 'System category cannot be deleted' : 'Category is in use'; ?>" 
                                                        disabled>
                                                    <i class="fas fa-lock"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr id="noResultsRow" style="display:none;">
                                <td colspan="7" class="text-center no-data">
                                    <i class="fas fa-search-minus" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No categories match your search</p>
                                </td>
                            </tr>
                            
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center no-data">
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
                    
                    <div class="form-group">
                        <label>Category Name <span class="required">*</span></label>
                        <input type="text" name="category_name" id="edit_category_name" class="form-control" required maxlength="50">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" id="edit_description" class="form-control" maxlength="200">
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                            <span>Active</span>
                        </label>
                    </div>
                    
                    <div class="form-group" id="system_notice" style="display:none;">
                        <div class="system-notice">
                            <i class="fas fa-info-circle"></i>
                            <span>This is a system category. It cannot be deleted, only deactivated.</span>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Category
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   VARIABLES
   ============================================================ */
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
    --shadow-hover: rgba(0,0,0,0.1);
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

/* PAGE HEADER */
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
    color: var(--text-primary);
    margin: 0;
}

.page-header .header-left h2 i {
    margin-right: 10px;
}

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

/* SUMMARY CARDS */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.summary-card {
    position: relative;
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s ease;
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(187, 4, 4, 0.15);
}

.summary-blue {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.summary-blue .summary-icon {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.summary-blue .summary-value { color: #1D4ED8; }

.summary-green {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.summary-green .summary-icon {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.summary-green .summary-value { color: #047857; }

.summary-orange {
    background: rgba(217, 119, 6, 0.08);
    border-color: rgba(217, 119, 6, 0.2);
}
.summary-orange .summary-icon {
    background: rgba(217, 119, 6, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(217, 119, 6, 0.3);
}
.summary-orange .summary-value { color: #B45309; }

.summary-purple {
    background: rgba(124, 58, 237, 0.08);
    border-color: rgba(124, 58, 237, 0.2);
}
.summary-purple .summary-icon {
    background: rgba(124, 58, 237, 0.15);
    color: #7C3AED;
    border: 1.5px solid rgba(124, 58, 237, 0.3);
}
.summary-purple .summary-value { color: #6D28D9; }

html.dark-mode .summary-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .summary-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .summary-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .summary-blue .summary-value { color: #60A5FA; }
html.dark-mode .summary-green .summary-value { color: #6EE7B7; }
html.dark-mode .summary-orange .summary-value { color: #FBBF24; }
html.dark-mode .summary-purple .summary-value { color: #C4B5FD; }

.summary-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.summary-card:hover .summary-icon {
    transform: scale(1.08) rotate(-4deg);
}

.summary-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}

.summary-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
}

.summary-value {
    font-size: 20px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

/* FORM CARD */
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
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

/* TABLE */
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
    flex-wrap: wrap;
    gap: 12px;
}

.table-header h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.table-header h4 i { color: #bb0404; margin-right: 8px; }

.table-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.table-search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 6px 12px;
    min-width: 240px;
    transition: all 0.3s ease;
}

.table-search:focus-within {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.table-search i {
    color: var(--text-muted);
    font-size: 12px;
}

.table-search input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    font-family: 'Inter', sans-serif;
}

.table-search input::placeholder {
    color: var(--text-muted);
}

.record-count {
    font-size: 12px;
    color: var(--text-muted);
}

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

.desc-text {
    display: inline-block;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    color: var(--text-muted);
}

/* TYPE BADGE */
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.type-system {
    background: #DBEAFE;
    color: #1E40AF;
    border: 1px solid #93C5FD;
}

.type-custom {
    background: #D1FAE5;
    color: #047857;
    border: 1px solid #6EE7B7;
}

html.dark-mode .type-system { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .type-custom { background: #065F46; color: #6EE7B7; border-color: #059669; }

/* USAGE BADGE */
.usage-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    background: #FEF3C7;
    color: #B45309;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid #FCD34D;
    cursor: help;
}

html.dark-mode .usage-badge { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

.usage-empty {
    color: var(--text-light);
    font-size: 12px;
}

/* STATUS BADGE */
.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #6EE7B7;
}

.status-badge.inactive {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FCA5A5;
}

html.dark-mode .status-badge.active { background: #065F46; color: #6EE7B7; }
html.dark-mode .status-badge.inactive { background: #7F1D1D; color: #FCA5A5; }

/* ACTION BUTTONS */
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

.btn-edit { background: #DBEAFE; color: #1E40AF; }
.btn-edit:hover { background: #1E40AF; color: #ffffff; }

.btn-success { background: #D1FAE5; color: #065F46; }
.btn-success:hover { background: #065F46; color: #ffffff; }

.btn-warning { background: #FEF3C7; color: #B45309; }
.btn-warning:hover { background: #B45309; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

.btn-disabled { 
    background: var(--bg-table-even); 
    color: var(--text-light); 
    cursor: not-allowed;
    opacity: 0.5;
}

/* ALERTS */
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

html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

/* MODAL */
.modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
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
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
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

.system-notice {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #FEF3C7;
    color: #78350F;
    border-radius: 8px;
    font-size: 12px;
    border: 1px solid #FCD34D;
}

html.dark-mode .system-notice {
    background: #5F3A1E;
    color: #FCD34D;
    border-color: #D97706;
}

/* NO DATA */
.no-data { padding: 40px 20px; text-align: center; }

/* DARK MODE TOGGLE */
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

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .summary-cards { grid-template-columns: 1fr; }
    .table-header { flex-direction: column; align-items: stretch; }
    .table-header-right { width: 100%; justify-content: space-between; }
    .table-search { min-width: 0; flex: 1; }
}
</style>

<script>
// ============================================================
// EDIT CATEGORY
// ============================================================
function editCategory(id, name, description, isActive, isSystem) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('edit_description').value = description || '';
    document.getElementById('edit_is_active').checked = isActive == 1;
    
    // Show system notice if it's a system category
    const notice = document.getElementById('system_notice');
    if (isSystem) {
        notice.style.display = 'flex';
    } else {
        notice.style.display = 'none';
    }
    
    document.getElementById('editModal').style.display = 'flex';
    document.getElementById('edit_category_name').focus();
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// ============================================================
// SEARCH / FILTER TABLE
// ============================================================
function filterTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('.category-row');
    const noResultsRow = document.getElementById('noResultsRow');
    
    let visibleCount = 0;
    
    rows.forEach(row => {
        const searchText = row.getAttribute('data-search') || '';
        if (filter === '' || searchText.includes(filter)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Show "no results" message
    if (noResultsRow) {
        noResultsRow.style.display = visibleCount === 0 && rows.length > 0 ? '' : 'none';
    }
}

// ============================================================
// DARK MODE
// ============================================================
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
    // Restore dark mode
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        const btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.querySelector('i').className = 'fas fa-sun';
            btn.querySelector('span').textContent = 'Light Mode';
        }
    }
    
    // Auto-hide alerts
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 400);
        }, 5000);
    });
    
    // Focus search on Ctrl+K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const search = document.getElementById('searchInput');
            if (search) search.focus();
        }
    });
});
</script>

</body>
</html>