<?php
// ================================================================
// FILE: modules/expenses/categories.php
// WAKALA FINANCIAL SYSTEM - EXPENSE CATEGORIES (ADMIN)
// ✅ RED theme
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// ADD CATEGORY
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    try {
        $category_name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($category_name)) throw new Exception('Category name is required.');
        
        $stmt = $db->prepare("SELECT id FROM expense_categories WHERE category_name = ?");
        $stmt->execute([$category_name]);
        if ($stmt->fetch()) throw new Exception('Category already exists.');
        
        $stmt = $db->prepare("INSERT INTO expense_categories (category_name, description, is_active, is_system) VALUES (?, ?, 1, 0)");
        $stmt->execute([$category_name, $description]);
        
        logActivity($user_id, 'Add Expense Category', 'Expenses', $db->lastInsertId(), '', 'Added category: ' . $category_name);
        
        $_SESSION['success_message'] = 'Category "' . $category_name . '" added successfully!';
        header('Location: categories.php');
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: categories.php');
        exit();
    }
}

// ============================================================
// DELETE CATEGORY
// ============================================================
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    try {
        $delete_id = intval($_GET['delete']);
        
        $stmt = $db->prepare("SELECT * FROM expense_categories WHERE id = ?");
        $stmt->execute([$delete_id]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cat) throw new Exception('Category not found.');
        if ($cat['is_system'] == 1) throw new Exception('Cannot delete system categories.');
        
        // Check if used
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM expenses WHERE category = ?");
        $stmt->execute([$cat['category_name']]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usage['c'] > 0) throw new Exception('Cannot delete category in use (' . $usage['c'] . ' expenses).');
        
        $stmt = $db->prepare("DELETE FROM expense_categories WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        logActivity($user_id, 'Delete Expense Category', 'Expenses', $delete_id, '', 'Deleted category: ' . $cat['category_name']);
        
        $_SESSION['success_message'] = 'Category deleted successfully!';
        header('Location: categories.php');
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: categories.php');
        exit();
    }
}

// ============================================================
// GET CATEGORIES
// ============================================================
$stmt = $db->prepare("
    SELECT c.*,
    (SELECT COUNT(*) FROM expenses WHERE category = c.category_name) as usage_count,
    (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE category = c.category_name) as total_amount
    FROM expense_categories c
    ORDER BY c.is_system DESC, c.category_name ASC
");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- RED BRANCH STATUS CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-tags"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Expense Categories</span>
                <span class="branch-status-name">Manage Categories</span>
            </div>
            <a href="index.php" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Expenses</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-tags"></i> Expense Categories</h2>
                <span class="record-count"><?php echo count($categories); ?> categories</span>
            </div>
            <div class="page-header-right">
                <button type="button" class="btn btn-add" onclick="openAddModal()">
                    <i class="fas fa-plus-circle"></i> Add Category
                </button>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header-red">
                <h3><i class="fas fa-list"></i> All Categories</h3>
            </div>
            
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th class="text-center">Usage</th>
                            <th class="text-right">Total Amount</th>
                            <th style="width: 100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; foreach ($categories as $cat): ?>
                            <tr>
                                <td><span class="row-number"><?php echo $counter++; ?></span></td>
                                <td>
                                    <span class="cat-name"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                                </td>
                                <td>
                                    <span class="cat-desc"><?php echo htmlspecialchars($cat['description'] ?? '-'); ?></span>
                                </td>
                                <td>
                                    <span class="type-badge <?php echo $cat['is_system'] ? 'type-system' : 'type-custom'; ?>">
                                        <i class="fas <?php echo $cat['is_system'] ? 'fa-lock' : 'fa-user'; ?>"></i>
                                        <?php echo $cat['is_system'] ? 'System' : 'Custom'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="usage-badge">
                                        <?php echo intval($cat['usage_count']); ?>
                                    </span>
                                </td>
                                <td class="text-right">
                                    <span class="amount-badge"><?php echo formatCurrency($cat['total_amount']); ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($cat['is_system'] == 0): ?>
                                            <a href="categories.php?delete=<?php echo $cat['id']; ?>" 
                                               class="btn-action btn-delete" 
                                               onclick="return confirm('Delete category: <?php echo addslashes($cat['category_name']); ?>?')"
                                               title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-action btn-locked" title="System category - cannot delete">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add New Category</h3>
            <button class="modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_category">
            <div class="modal-body">
                <div class="form-group">
                    <label>Category Name <span class="required">*</span></label>
                    <input type="text" name="category_name" class="form-control" 
                           placeholder="e.g. Office Supplies" required maxlength="50">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" 
                              placeholder="Short description (optional)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn btn-submit">
                    <i class="fas fa-save"></i> Save Category
                </button>
            </div>
        </form>
    </div>
</div>

<style>
:root {
    --exp-bg: #f3f4f6;
    --exp-card-bg: #FFFFFF;
    --exp-text: #1F2937;
    --exp-text-secondary: #6B7280;
    --exp-text-light: #9CA3AF;
    --exp-border: #E5E7EB;
    --exp-hover: #F3F4F6;
    --exp-shadow: rgba(0,0,0,0.06);
}
html.dark-mode {
    --exp-bg: #0f172a;
    --exp-card-bg: #1E293B;
    --exp-text: #F1F5F9;
    --exp-text-secondary: #94A3B8;
    --exp-text-light: #64748B;
    --exp-border: #334155;
    --exp-hover: #2D3A4F;
    --exp-shadow: rgba(0,0,0,0.3);
}
* { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100% !important; }
body { background: var(--exp-bg) !important; color: var(--exp-text); }
.main-wrapper, .main-content { background: var(--exp-bg) !important; }
.main-content { padding: 16px 20px !important; }

.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative; overflow: hidden;
    flex-wrap: wrap; color: #FFFFFF;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1; position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name { font-size: 18px; font-weight: 700; color: #FFFFFF; }
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; }

.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 16px;
    padding: 0 4px; flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 20px; font-weight: 700;
    color: var(--exp-text); margin: 0;
}
.page-header-left h2 i { color: #DC2626; margin-right: 6px; }
.record-count {
    font-size: 12px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 2px 10px; border-radius: 12px;
}

.btn {
    padding: 10px 20px; border-radius: 8px;
    font-weight: 700; font-size: 13px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; white-space: nowrap;
}
.btn-add {
    background: #DC2626; color: white;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
}
.btn-add:hover { background: #B91C1C; transform: translateY(-1px); }
.btn-submit {
    background: #DC2626; color: white;
}
.btn-submit:hover { background: #B91C1C; }
.btn-cancel {
    background: var(--exp-hover); color: var(--exp-text-secondary);
    border: 1.5px solid var(--exp-border);
}
.btn-cancel:hover { background: var(--exp-border); color: var(--exp-text); }

.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert i { font-size: 18px; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 20px;
    color: inherit; cursor: pointer; opacity: 0.6;
}

.table-container {
    background: var(--exp-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    overflow: hidden;
}
.table-header-red {
    padding: 14px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
}
.table-header-red h3 {
    font-size: 15px; font-weight: 800;
    margin: 0; display: flex; align-items: center; gap: 10px;
}
.table-header-red h3 i { color: #FCD34D; }

.table-responsive { overflow-x: auto; width: 100%; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table thead { background: #DC2626; }
.data-table thead th {
    padding: 12px 14px; text-align: left;
    font-weight: 700; color: #FFFFFF;
    text-transform: uppercase; font-size: 10px;
    letter-spacing: 0.5px; white-space: nowrap;
}
.data-table thead th.text-center { text-align: center; }
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--exp-border);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--exp-hover); }
.data-table tbody td { padding: 12px 14px; color: var(--exp-text); vertical-align: middle; }
.data-table tbody td.text-center { text-align: center; }
.data-table tbody td.text-right { text-align: right; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--exp-hover);
    font-size: 11px; font-weight: 700;
    color: var(--exp-text-secondary);
    border: 1px solid var(--exp-border);
}
.cat-name {
    font-weight: 700; color: var(--exp-text);
    font-size: 13px;
}
.cat-desc {
    font-size: 12px; color: var(--exp-text-secondary);
    font-style: italic;
}
.type-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.type-system {
    background: #FEE2E2; color: #991B1B;
    border: 1px solid #FECACA;
}
.type-custom {
    background: #D1FAE5; color: #065F46;
    border: 1px solid #A7F3D0;
}
html.dark-mode .type-system { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }
html.dark-mode .type-custom { background: #065F46; color: #D1FAE5; border-color: #047857; }

.usage-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; padding: 4px 10px;
    background: #FEF3C7; color: #92400E;
    border-radius: 8px; font-size: 12px; font-weight: 800;
    border: 1px solid #FDE68A;
}
html.dark-mode .usage-badge { background: #5F3A1E; color: #FCD34D; border-color: #F59E0B; }

.amount-badge {
    display: inline-flex; align-items: center;
    padding: 5px 12px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 8px;
    font-weight: 800; font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FECACA;
    white-space: nowrap;
}
html.dark-mode .amount-badge { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

.action-buttons { display: flex; gap: 5px; justify-content: center; }
.btn-action {
    width: 34px; height: 34px;
    border-radius: 8px; border: none;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.25s ease;
    text-decoration: none; font-size: 13px;
}
.btn-delete {
    background: #FEE2E2; color: #991B1B;
    border: 1.5px solid #FCA5A5;
}
.btn-delete:hover {
    background: #991B1B; color: #FFFFFF;
    transform: translateY(-2px);
}
.btn-locked {
    background: var(--exp-hover); color: var(--exp-text-light);
    border: 1.5px solid var(--exp-border);
    cursor: not-allowed;
    opacity: 0.5;
}

/* MODAL */
.modal-overlay {
    display: none;
    position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 9999;
    align-items: center; justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
}
.modal-overlay.show { display: flex; }
.modal-content {
    background: var(--exp-card-bg);
    border-radius: 16px;
    width: 100%; max-width: 500px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    overflow: hidden;
    animation: modalIn 0.3s ease;
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 {
    margin: 0; font-size: 16px; font-weight: 800;
    display: flex; align-items: center; gap: 10px;
}
.modal-header h3 i { color: #FCD34D; }
.modal-close {
    background: rgba(255, 255, 255, 0.15);
    border: none; width: 32px; height: 32px;
    border-radius: 50%; color: #FFFFFF;
    font-size: 20px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s ease;
}
.modal-close:hover { background: rgba(255, 255, 255, 0.3); }
.modal-body { padding: 24px; }
.form-group { margin-bottom: 16px; }
.form-group label {
    display: block; font-size: 13px; font-weight: 700;
    color: var(--exp-text); margin-bottom: 6px;
}
.form-group label .required { color: #DC2626; }
.form-control {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--exp-border);
    font-size: 14px;
    background: var(--exp-hover);
    color: var(--exp-text);
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}
.form-control:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--exp-card-bg);
}
textarea.form-control { resize: vertical; min-height: 70px; }
.modal-footer {
    padding: 16px 24px;
    background: var(--exp-hover);
    display: flex; gap: 10px; justify-content: flex-end;
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 10px; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header-right { width: 100%; }
    .page-header-right .btn { width: 100%; justify-content: center; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .data-table thead th, .data-table tbody td { padding: 8px 10px; font-size: 11px; }
}
</style>

<script>
function openAddModal() { document.getElementById('addModal').classList.add('show'); }
function closeAddModal() { document.getElementById('addModal').classList.remove('show'); }

document.getElementById('addModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAddModal();
});

document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    var sa = document.querySelector('.alert-success');
    if (sa) setTimeout(function() { sa.style.display = 'none'; }, 5000);
});
</script>
</body>
</html>