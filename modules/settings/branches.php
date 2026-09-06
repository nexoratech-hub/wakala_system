<?php
// ================================================================
// FILE: modules/settings/branches.php
// BRANCH MANAGEMENT
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

try {
    // Get branches
    $stmt = $db->prepare("SELECT b.*, e.full_name as manager_name 
                          FROM branches b
                          LEFT JOIN employees e ON b.manager_id = e.id
                          ORDER BY b.branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employees for manager selection
    $stmt = $db->prepare("SELECT id, full_name FROM employees WHERE is_active = 1 ORDER BY full_name");
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error loading branches: " . $e->getMessage());
    $branches = [];
    $employees = [];
}

// Handle add branch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_branch'])) {
    try {
        $branch_code = trim($_POST['branch_code'] ?? '');
        $branch_name = trim($_POST['branch_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($branch_code) || empty($branch_name)) {
            $error = 'Branch code and name are required';
        } else {
            $stmt = $db->prepare("INSERT INTO branches (branch_code, branch_name, location, phone, email, manager_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_code, $branch_name, $location, $phone, $email, $manager_id, $is_active]);
            
            logActivity($_SESSION['user_id'], 'Add Branch', 'Settings', $db->lastInsertId(), null, json_encode([
                'branch_code' => $branch_code,
                'branch_name' => $branch_name
            ]));
            
            $success = 'Branch added successfully!';
            header('Refresh: 1; URL=branches.php');
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = 'Branch code already exists!';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
        error_log("Error adding branch: " . $e->getMessage());
    }
}

// Handle edit branch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_branch'])) {
    try {
        $id = intval($_POST['branch_id'] ?? 0);
        $branch_code = trim($_POST['branch_code'] ?? '');
        $branch_name = trim($_POST['branch_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($id <= 0 || empty($branch_code) || empty($branch_name)) {
            $error = 'Invalid data';
        } else {
            $stmt = $db->prepare("UPDATE branches SET branch_code = ?, branch_name = ?, location = ?, phone = ?, email = ?, manager_id = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$branch_code, $branch_name, $location, $phone, $email, $manager_id, $is_active, $id]);
            
            logActivity($_SESSION['user_id'], 'Edit Branch', 'Settings', $id);
            $success = 'Branch updated successfully!';
            header('Refresh: 1; URL=branches.php');
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $error = 'Branch code already exists!';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
        error_log("Error updating branch: " . $e->getMessage());
    }
}

// Handle delete branch
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        
        // Check if branch has employees
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM employees WHERE branch_id = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        if ($count > 0) {
            $error = 'Cannot delete branch with assigned employees. Please reassign employees first.';
        } else {
            $stmt = $db->prepare("DELETE FROM branches WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Branch deleted successfully!';
            header('Refresh: 1; URL=branches.php');
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error deleting branch: " . $e->getMessage());
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
                <h2><i class="fas fa-building" style="color:#bb0404;"></i> Branch Management</h2>
                <p class="text-muted">Manage branches and locations</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Settings
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Add Branch Form -->
        <div class="form-card">
            <h4><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Add New Branch</h4>
            <form method="POST" action="" class="branch-form">
                <div class="form-row">
                    <div class="form-group">
                        <label>Branch Code <span class="required">*</span></label>
                        <input type="text" name="branch_code" class="form-control" placeholder="e.g., KND" required>
                    </div>
                    <div class="form-group">
                        <label>Branch Name <span class="required">*</span></label>
                        <input type="text" name="branch_name" class="form-control" placeholder="e.g., Kinondoni" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Location</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g., Kinondoni, Dar es Salaam">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g., +255 700 000 200">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" placeholder="e.g., branch@company.com">
                    </div>
                    <div class="form-group">
                        <label>Manager</label>
                        <select name="manager_id" class="form-control">
                            <option value="">Select Manager</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
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
                    <button type="submit" name="add_branch" class="btn btn-primary"><i class="fas fa-plus"></i> Add Branch</button>
                </div>
            </form>
        </div>

        <!-- Branches List -->
        <div class="table-container">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Branches</h4>
                <span class="record-count"><?php echo count($branches); ?> branches</span>
            </div>
            
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Code</th>
                            <th>Branch Name</th>
                            <th>Location</th>
                            <th>Manager</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($branches) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($branches as $branch): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($branch['branch_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                                    <td><?php echo htmlspecialchars($branch['location'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($branch['manager_name'] ?? 'Not Assigned'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $branch['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $branch['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="editBranch(<?php echo $branch['id']; ?>, '<?php echo htmlspecialchars($branch['branch_code']); ?>', '<?php echo htmlspecialchars($branch['branch_name']); ?>', '<?php echo htmlspecialchars($branch['location'] ?? ''); ?>', '<?php echo htmlspecialchars($branch['phone'] ?? ''); ?>', '<?php echo htmlspecialchars($branch['email'] ?? ''); ?>', <?php echo $branch['manager_id'] ?? 'null'; ?>, <?php echo $branch['is_active']; ?>)" 
                                                    class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteBranch(<?php echo $branch['id']; ?>)" class="btn-action btn-delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center no-data">
                                    <i class="fas fa-building" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No branches found</p>
                                    <p style="color:var(--text-muted);font-size:12px;">Add your first branch using the form above</p>
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
                    <h4><i class="fas fa-edit"></i> Edit Branch</h4>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="branch_id" id="edit_branch_id">
                    <input type="hidden" name="edit_branch" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch Code <span class="required">*</span></label>
                            <input type="text" name="branch_code" id="edit_branch_code" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Branch Name <span class="required">*</span></label>
                            <input type="text" name="branch_name" id="edit_branch_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" id="edit_location" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Manager</label>
                            <select name="manager_id" id="edit_manager_id" class="form-control">
                                <option value="">Select Manager</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
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
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Branch</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Reuse styles from general.php and add branch-specific styles */
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
function editBranch(id, code, name, location, phone, email, managerId, isActive) {
    document.getElementById('edit_branch_id').value = id;
    document.getElementById('edit_branch_code').value = code;
    document.getElementById('edit_branch_name').value = name;
    document.getElementById('edit_location').value = location || '';
    document.getElementById('edit_phone').value = phone || '';
    document.getElementById('edit_email').value = email || '';
    document.getElementById('edit_manager_id').value = managerId || '';
    document.getElementById('edit_is_active').checked = isActive == 1;
    document.getElementById('editModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function deleteBranch(id) {
    if (confirm('Are you sure you want to delete this branch? This will also remove all associated data.')) {
        window.location.href = 'branches.php?delete=' + id;
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