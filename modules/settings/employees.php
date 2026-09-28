<?php
// ================================================================
// FILE: modules/settings/employees.php
// EMPLOYEES MANAGEMENT - RED THEME
// ✅ Fixed width, live search, scroll buttons, password toggle
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
// GET FILTERS
// ============================================================
$filter_role = isset($_GET['role']) ? trim($_GET['role']) : '';
$filter_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ============================================================
// GET BRANCHES FOR FILTER
// ============================================================
try {
    $stmt = $db->prepare("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
}

// ============================================================
// GET EMPLOYEES
// ============================================================
try {
    $sql = "
        SELECT 
            e.*,
            b.branch_name AS branch_display_name,
            b.branch_code AS branch_display_code
        FROM employees e
        LEFT JOIN branches b ON e.branch_id = b.id
        WHERE 1=1
    ";
    $params = [];
    
    if (!empty($filter_role)) {
        $sql .= " AND e.role = ?";
        $params[] = $filter_role;
    }
    
    if ($filter_branch > 0) {
        $sql .= " AND e.branch_id = ?";
        $params[] = $filter_branch;
    }
    
    if (!empty($filter_status)) {
        if ($filter_status === 'active') {
            $sql .= " AND e.is_active = 1";
        } elseif ($filter_status === 'inactive') {
            $sql .= " AND e.is_active = 0";
        } else {
            $sql .= " AND e.employment_status = ?";
            $params[] = $filter_status;
        }
    }
    
    if (!empty($search)) {
        $sql .= " AND (e.full_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ? OR e.phone LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    
    $sql .= " ORDER BY e.is_active DESC, e.full_name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_employees = count($employees);
    $active_count = 0;
    $inactive_count = 0;
    $admin_count = 0;
    $employee_count = 0;
    
    foreach ($employees as $emp) {
        if ($emp['is_active']) $active_count++;
        else $inactive_count++;
        
        if (in_array($emp['role'], ['admin', 'super_admin'])) $admin_count++;
        else $employee_count++;
    }
    
} catch (PDOException $e) {
    error_log("Error loading employees: " . $e->getMessage());
    $employees = [];
    $total_employees = 0;
    $active_count = 0;
    $inactive_count = 0;
    $admin_count = 0;
    $employee_count = 0;
}

// ============================================================
// HANDLE ADD EMPLOYEE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_employee'])) {
    try {
        $full_name = trim($_POST['full_name'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';
        $position = trim($_POST['position'] ?? '');
        $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $base_salary = floatval($_POST['base_salary'] ?? 0);
        $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $gender = trim($_POST['gender'] ?? '');
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $address = trim($_POST['address'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($full_name) || empty($email) || empty($username) || empty($password) || empty($employee_id)) {
            throw new Exception('Full name, Employee ID, Email, Username and Password are required.');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }
        
        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Email already exists!');
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Username already exists!');
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Employee ID already exists!');
        
        $branch_name = 'Main';
        if ($branch_id) {
            $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $branch_name = $stmt->fetchColumn() ?: 'Main';
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            INSERT INTO employees (
                full_name, employee_id, email, phone, username, password_hash,
                role, position, branch, branch_id, base_salary, hire_date,
                gender, date_of_birth, address, emergency_contact, emergency_phone,
                is_active, employment_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->execute([
            $full_name, $employee_id, $email, $phone, $username, $password_hash,
            $role, $position, $branch_name, $branch_id, $base_salary, $hire_date,
            $gender, $date_of_birth, $address, $emergency_contact, $emergency_phone,
            $is_active
        ]);
        
        $new_id = $db->lastInsertId();
        logActivity($_SESSION['user_id'], 'Add Employee', 'Settings', $new_id, null, json_encode([
            'employee_id' => $employee_id, 'full_name' => $full_name, 'role' => $role
        ]));
        
        $success = 'Employee added successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = ($e->getCode() == 23000) ? 'Duplicate entry.' : 'Database error: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE EDIT EMPLOYEE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_employee'])) {
    try {
        $id = intval($_POST['employee_id_hidden'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'employee';
        $position = trim($_POST['position'] ?? '');
        $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $base_salary = floatval($_POST['base_salary'] ?? 0);
        $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $gender = trim($_POST['gender'] ?? '');
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $address = trim($_POST['address'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $employment_status = $_POST['employment_status'] ?? 'active';
        $new_password = $_POST['new_password'] ?? '';
        
        if ($id <= 0 || empty($full_name) || empty($email) || empty($employee_id)) {
            throw new Exception('Invalid data.');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Email already exists!');
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ? AND id != ?");
        $stmt->execute([$employee_id, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Employee ID already exists!');
        
        $branch_name = 'Main';
        if ($branch_id) {
            $stmt = $db->prepare("SELECT branch_name FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $branch_name = $stmt->fetchColumn() ?: 'Main';
        }
        
        if (!empty($new_password)) {
            if (strlen($new_password) < 6) throw new Exception('New password must be at least 6 characters.');
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                UPDATE employees SET 
                    full_name = ?, employee_id = ?, email = ?, phone = ?,
                    role = ?, position = ?, branch = ?, branch_id = ?,
                    base_salary = ?, hire_date = ?, gender = ?, date_of_birth = ?,
                    address = ?, emergency_contact = ?, emergency_phone = ?,
                    is_active = ?, employment_status = ?, password_hash = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $full_name, $employee_id, $email, $phone,
                $role, $position, $branch_name, $branch_id,
                $base_salary, $hire_date, $gender, $date_of_birth,
                $address, $emergency_contact, $emergency_phone,
                $is_active, $employment_status, $password_hash, $id
            ]);
        } else {
            $stmt = $db->prepare("
                UPDATE employees SET 
                    full_name = ?, employee_id = ?, email = ?, phone = ?,
                    role = ?, position = ?, branch = ?, branch_id = ?,
                    base_salary = ?, hire_date = ?, gender = ?, date_of_birth = ?,
                    address = ?, emergency_contact = ?, emergency_phone = ?,
                    is_active = ?, employment_status = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $full_name, $employee_id, $email, $phone,
                $role, $position, $branch_name, $branch_id,
                $base_salary, $hire_date, $gender, $date_of_birth,
                $address, $emergency_contact, $emergency_phone,
                $is_active, $employment_status, $id
            ]);
        }
        
        logActivity($_SESSION['user_id'], 'Edit Employee', 'Settings', $id, null, json_encode([
            'employee_id' => $employee_id, 'full_name' => $full_name
        ]));
        
        $success = 'Employee updated successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = ($e->getCode() == 23000) ? 'Duplicate entry.' : 'Database error: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE DELETE EMPLOYEE
// ============================================================
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        
        if ($id == $_SESSION['user_id']) throw new Exception('Cannot delete your own account.');
        
        $stmt = $db->prepare("SELECT employee_id, full_name FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$emp) throw new Exception('Employee not found.');
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE employee_id = ?");
        $stmt->execute([$id]);
        $txn_count = $stmt->fetchColumn();
        
        if ($txn_count > 0) throw new Exception("Cannot delete employee. They have {$txn_count} transaction(s). Deactivate instead.");
        
        $stmt = $db->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        
        logActivity($_SESSION['user_id'], 'Delete Employee', 'Settings', $id, null, json_encode([
            'employee_id' => $emp['employee_id'], 'full_name' => $emp['full_name']
        ]));
        
        $success = 'Employee deleted successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE TOGGLE STATUS
// ============================================================
if (isset($_GET['toggle'])) {
    try {
        $id = intval($_GET['toggle']);
        
        if ($id == $_SESSION['user_id']) throw new Exception('Cannot deactivate your own account.');
        
        $stmt = $db->prepare("SELECT full_name, is_active FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($emp) {
            $new_status = $emp['is_active'] ? 0 : 1;
            $stmt = $db->prepare("UPDATE employees SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            logActivity($_SESSION['user_id'], 'Toggle Employee Status', 'Settings', $id, null, json_encode([
                'full_name' => $emp['full_name'], 'new_status' => $new_status
            ]));
            
            $success = 'Employee status updated successfully!';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
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
                <h2><i class="fas fa-users" style="color:#bb0404;"></i> Employees Management</h2>
                <p class="text-muted">Manage employees and their accounts</p>
            </div>
            <div class="header-right">
                <button onclick="openAddModal()" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Add Employee
                </button>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
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
                <div class="summary-icon"><i class="fas fa-users"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Employees</span>
                    <span class="summary-value"><?php echo number_format($total_employees); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-green">
                <div class="summary-icon"><i class="fas fa-user-check"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Active</span>
                    <span class="summary-value"><?php echo number_format($active_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-purple">
                <div class="summary-icon"><i class="fas fa-user-shield"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Admins</span>
                    <span class="summary-value"><?php echo number_format($admin_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-orange">
                <div class="summary-icon"><i class="fas fa-user-slash"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Inactive</span>
                    <span class="summary-value"><?php echo number_format($inactive_count); ?></span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" class="form-control" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Name, email, ID...">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-user-tag"></i> Role</label>
                    <select name="role" class="form-control">
                        <option value="">All Roles</option>
                        <option value="super_admin" <?php echo $filter_role === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="employee" <?php echo $filter_role === 'employee' ? 'selected' : ''; ?>>Employee</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-store-alt"></i> Branch</label>
                    <select name="branch_id" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $filter_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-toggle-on"></i> Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="employees.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Employees Table -->
        <div class="table-container">
            
            <!-- Table Header: Search Left, Scroll Right -->
            <div class="table-header">
                <div class="table-header-left">
                    <h4><i class="fas fa-list"></i> Employees List</h4>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($employees); ?></span>
                </div>
                
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search employees..."
                               oninput="performLiveSearch(this.value)"
                               autocomplete="off">
                        <button type="button" 
                                class="search-clear-btn" 
                                id="searchClearBtn" 
                                onclick="clearLiveSearch()" 
                                style="display:none;"
                                title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count-badge" 
                              id="searchCountBadge" 
                              style="display:none;">0</span>
                    </div>
                    
                    <div class="table-scroll-buttons">
                        <button type="button" class="scroll-btn" onclick="scrollTable('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button type="button" class="scroll-btn" onclick="scrollTable('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive" id="tableWrapper">
                <table class="data-table" id="employeesTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Employee</th>
                            <th>Employee ID</th>
                            <th>Contact</th>
                            <th>Role</th>
                            <th>Position</th>
                            <th>Branch</th>
                            <th>Salary</th>
                            <th>Status</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="employeesTableBody">
                        <?php if (count($employees) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($employees as $emp): 
                                $initial = strtoupper(substr($emp['full_name'], 0, 1));
                                $avatar = $emp['profile_pic'] ?? '';
                                
                                $role_class = 'role-employee';
                                if ($emp['role'] === 'super_admin') $role_class = 'role-super';
                                elseif ($emp['role'] === 'admin') $role_class = 'role-admin';
                                
                                $role_display = ucfirst(str_replace('_', ' ', $emp['role']));
                                
                                $search_text = strtolower(
                                    $emp['full_name'] . ' ' . 
                                    $emp['employee_id'] . ' ' . 
                                    $emp['email'] . ' ' . 
                                    ($emp['phone'] ?? '') . ' ' . 
                                    $emp['role'] . ' ' . 
                                    ($emp['position'] ?? '') . ' ' . 
                                    ($emp['branch_display_name'] ?? '')
                                );
                            ?>
                                <tr class="employee-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <div class="employee-cell">
                                            <?php if ($avatar && file_exists('../../' . $avatar)): ?>
                                                <img src="../../<?php echo htmlspecialchars($avatar); ?>" alt="" class="employee-avatar">
                                            <?php else: ?>
                                                <div class="employee-avatar"><?php echo $initial; ?></div>
                                            <?php endif; ?>
                                            <div class="employee-info">
                                                <span class="employee-name"><?php echo htmlspecialchars($emp['full_name']); ?></span>
                                                <span class="employee-email"><?php echo htmlspecialchars($emp['email']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="id-badge"><?php echo htmlspecialchars($emp['employee_id']); ?></span>
                                    </td>
                                    <td>
                                        <div class="contact-cell">
                                            <?php if (!empty($emp['phone'])): ?>
                                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($emp['phone']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge <?php echo $role_class; ?>">
                                            <?php echo htmlspecialchars($role_display); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($emp['position'])): ?>
                                            <span class="position-text"><?php echo htmlspecialchars($emp['position']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($emp['branch_display_name'])): ?>
                                            <div class="branch-cell">
                                                <span class="branch-name"><?php echo htmlspecialchars($emp['branch_display_name']); ?></span>
                                                <?php if (!empty($emp['branch_display_code'])): ?>
                                                    <span class="branch-code-sm"><?php echo htmlspecialchars($emp['branch_display_code']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (floatval($emp['base_salary']) > 0): ?>
                                            <span class="salary-badge"><?php echo formatCurrency($emp['base_salary']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $emp['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $emp['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick='editEmployee(<?php echo json_encode($emp); ?>)' 
                                                    class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <?php if ($emp['id'] != $_SESSION['user_id']): ?>
                                                <a href="?toggle=<?php echo $emp['id']; ?>" 
                                                   class="btn-action <?php echo $emp['is_active'] ? 'btn-warning' : 'btn-success'; ?>" 
                                                   title="<?php echo $emp['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                                   onclick="return confirm('<?php echo $emp['is_active'] ? 'Deactivate' : 'Activate'; ?> this employee?');">
                                                    <i class="fas fa-<?php echo $emp['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                                </a>
                                                
                                                <a href="?delete=<?php echo $emp['id']; ?>" 
                                                   class="btn-action btn-delete" 
                                                   title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="btn-action btn-self" title="This is you">
                                                    <i class="fas fa-user-check"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="no-data">
                                    <i class="fas fa-users" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No employees found</p>
                                    <p style="color:var(--text-muted);font-size:12px;">Click "Add Employee" to create one</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <!-- No search results row -->
                        <tr id="noSearchResultsRow" style="display:none;">
                            <td colspan="10" class="no-data">
                                <i class="fas fa-search-minus" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                <p style="color:var(--text-muted);">No employees match your search</p>
                                <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()" style="margin-top:12px;">
                                    <i class="fas fa-times"></i> Clear Search
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================================================
             ADD EMPLOYEE MODAL
             ============================================================ -->
        <div id="addModal" class="modal" style="display:none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h4><i class="fas fa-user-plus"></i> Add New Employee</h4>
                    <button class="modal-close" onclick="closeAddModal()">&times;</button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-body">
                        
                        <!-- Basic Info -->
                        <div class="modal-section">
                            <h5><i class="fas fa-user"></i> Basic Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name <span class="required">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Employee ID <span class="required">*</span></label>
                                    <input type="text" name="employee_id" class="form-control" placeholder="e.g., EMP-003" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Email <span class="required">*</span></label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="text" name="phone" class="form-control" placeholder="+255...">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Gender</label>
                                    <select name="gender" class="form-control">
                                        <option value="">Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Date of Birth</label>
                                    <input type="date" name="date_of_birth" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Account Credentials -->
                        <div class="modal-section">
                            <h5><i class="fas fa-key"></i> Account Credentials</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Username <span class="required">*</span></label>
                                    <input type="text" name="username" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Password <span class="required">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" 
                                               name="password" 
                                               id="add_password" 
                                               class="form-control" 
                                               minlength="6" 
                                               required>
                                        <button type="button" 
                                                class="password-toggle-btn" 
                                                onclick="togglePassword('add_password', this)"
                                                title="Show password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="form-text">Minimum 6 characters</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Work Information -->
                        <div class="modal-section">
                            <h5><i class="fas fa-briefcase"></i> Work Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Role <span class="required">*</span></label>
                                    <select name="role" class="form-control" required>
                                        <option value="employee">Employee</option>
                                        <option value="admin">Admin</option>
                                        <option value="super_admin">Super Admin</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Position</label>
                                    <input type="text" name="position" class="form-control" placeholder="e.g., Cashier">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Branch</label>
                                    <select name="branch_id" class="form-control">
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Base Salary</label>
                                    <input type="number" name="base_salary" class="form-control" step="0.01" min="0" value="0">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Hire Date</label>
                                    <input type="date" name="hire_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_active" value="1" checked>
                                        <span>Active</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Info -->
                        <div class="modal-section">
                            <h5><i class="fas fa-info-circle"></i> Additional Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Emergency Contact</label>
                                    <input type="text" name="emergency_contact" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Emergency Phone</label>
                                    <input type="text" name="emergency_phone" class="form-control">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAddModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="add_employee" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Employee
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================================
             EDIT EMPLOYEE MODAL
             ============================================================ -->
        <div id="editModal" class="modal" style="display:none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h4><i class="fas fa-user-edit"></i> Edit Employee</h4>
                    <button class="modal-close" onclick="closeEditModal()">&times;</button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="employee_id_hidden" id="edit_employee_id_hidden">
                    <input type="hidden" name="edit_employee" value="1">
                    
                    <div class="modal-body">
                        
                        <!-- Basic Info -->
                        <div class="modal-section">
                            <h5><i class="fas fa-user"></i> Basic Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name <span class="required">*</span></label>
                                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Employee ID <span class="required">*</span></label>
                                    <input type="text" name="employee_id" id="edit_employee_id" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Email <span class="required">*</span></label>
                                    <input type="email" name="email" id="edit_email" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="text" name="phone" id="edit_phone" class="form-control">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Gender</label>
                                    <select name="gender" id="edit_gender" class="form-control">
                                        <option value="">Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Date of Birth</label>
                                    <input type="date" name="date_of_birth" id="edit_date_of_birth" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Password Section with Toggle -->
                        <div class="modal-section">
                            <h5><i class="fas fa-lock"></i> Change Password (Optional)</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>New Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" 
                                               name="new_password" 
                                               id="edit_new_password" 
                                               class="form-control" 
                                               minlength="6" 
                                               placeholder="Leave empty to keep current">
                                        <button type="button" 
                                                class="password-toggle-btn" 
                                                onclick="togglePassword('edit_new_password', this)"
                                                title="Show password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="form-text">Minimum 6 characters. Leave empty to keep current password.</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Work Information -->
                        <div class="modal-section">
                            <h5><i class="fas fa-briefcase"></i> Work Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Role <span class="required">*</span></label>
                                    <select name="role" id="edit_role" class="form-control" required>
                                        <option value="employee">Employee</option>
                                        <option value="admin">Admin</option>
                                        <option value="super_admin">Super Admin</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Position</label>
                                    <input type="text" name="position" id="edit_position" class="form-control">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Branch</label>
                                    <select name="branch_id" id="edit_branch_id" class="form-control">
                                        <option value="">Select Branch</option>
                                        <?php foreach ($branches as $b): ?>
                                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Base Salary</label>
                                    <input type="number" name="base_salary" id="edit_base_salary" class="form-control" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Hire Date</label>
                                    <input type="date" name="hire_date" id="edit_hire_date" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Employment Status</label>
                                    <select name="employment_status" id="edit_employment_status" class="form-control">
                                        <option value="active">Active</option>
                                        <option value="on_leave">On Leave</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="terminated">Terminated</option>
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
                        </div>
                        
                        <!-- Additional Info -->
                        <div class="modal-section">
                            <h5><i class="fas fa-info-circle"></i> Additional Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Emergency Contact</label>
                                    <input type="text" name="emergency_contact" id="edit_emergency_contact" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Emergency Phone</label>
                                    <input type="text" name="emergency_phone" id="edit_emergency_phone" class="form-control">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Address</label>
                                <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Employee
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

/* ============================================================
   🔧 FIX OVERFLOW
   ============================================================ */
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
    margin: 0;
    padding: 0;
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    padding: 16px 20px !important;
    box-sizing: border-box !important;
}

*, *::before, *::after {
    box-sizing: border-box;
}

/* PAGE HEADER */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
    max-width: 100%;
}

.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.page-header .header-left h2 i { margin-right: 10px; }

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* SUMMARY CARDS */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
    width: 100%;
}

.summary-card {
    position: relative;
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
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

.summary-blue { background: rgba(37, 99, 235, 0.08); border-color: rgba(37, 99, 235, 0.2); }
.summary-blue .summary-icon { background: rgba(37, 99, 235, 0.15); color: #2563EB; border: 1.5px solid rgba(37, 99, 235, 0.3); }
.summary-blue .summary-value { color: #1D4ED8; }

.summary-green { background: rgba(5, 150, 105, 0.08); border-color: rgba(5, 150, 105, 0.2); }
.summary-green .summary-icon { background: rgba(5, 150, 105, 0.15); color: #059669; border: 1.5px solid rgba(5, 150, 105, 0.3); }
.summary-green .summary-value { color: #047857; }

.summary-purple { background: rgba(124, 58, 237, 0.08); border-color: rgba(124, 58, 237, 0.2); }
.summary-purple .summary-icon { background: rgba(124, 58, 237, 0.15); color: #7C3AED; border: 1.5px solid rgba(124, 58, 237, 0.3); }
.summary-purple .summary-value { color: #6D28D9; }

.summary-orange { background: rgba(217, 119, 6, 0.08); border-color: rgba(217, 119, 6, 0.2); }
.summary-orange .summary-icon { background: rgba(217, 119, 6, 0.15); color: #D97706; border: 1.5px solid rgba(217, 119, 6, 0.3); }
.summary-orange .summary-value { color: #B45309; }

html.dark-mode .summary-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .summary-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .summary-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .summary-blue .summary-value { color: #60A5FA; }
html.dark-mode .summary-green .summary-value { color: #6EE7B7; }
html.dark-mode .summary-purple .summary-value { color: #C4B5FD; }
html.dark-mode .summary-orange .summary-value { color: #FBBF24; }

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

/* FILTER BAR */
.filter-bar {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
    width: 100%;
    max-width: 100%;
}

.filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.filter-group label i { color: #bb0404; }

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.filter-actions .btn {
    flex: 1;
    justify-content: center;
}

/* FORM CONTROLS */
.form-control {
    padding: 9px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    width: 100%;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

/* BUTTONS */
.btn {
    padding: 9px 18px;
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
    white-space: nowrap;
}

.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

/* ============================================================
   TABLE CONTAINER
   ============================================================ */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    width: 100%;
    max-width: 100%;
    overflow: hidden;
    box-sizing: border-box;
}

/* ============================================================
   TABLE HEADER
   ============================================================ */
.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
    box-sizing: border-box;
}

.table-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.table-header-left h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    white-space: nowrap;
}

.table-header-left h4 i { color: #bb0404; margin-right: 8px; }

.count-badge {
    background: #bb0404;
    color: white;
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    min-width: 24px;
    text-align: center;
}

.table-header-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1;
    justify-content: flex-end;
    min-width: 0;
}

/* LIVE SEARCH */
.table-search-live {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    padding: 6px 12px;
    min-width: 240px;
    max-width: 300px;
    transition: all 0.3s ease;
}

.table-search-live:focus-within {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
    background: var(--bg-card);
}

.table-search-live > i {
    color: #bb0404;
    font-size: 13px;
    flex-shrink: 0;
}

.table-search-live input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    font-family: 'Inter', sans-serif;
    min-width: 0;
}

.table-search-live input::placeholder {
    color: var(--text-muted);
    font-size: 12px;
}

.table-search-live .search-clear-btn {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    padding: 0;
}

.table-search-live .search-clear-btn:hover {
    background: #DC2626;
    color: #FFFFFF;
}

.table-search-live .search-count-badge {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* SCROLL BUTTONS */
.table-scroll-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.scroll-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: #bb0404;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    transition: all 0.2s ease;
    flex-shrink: 0;
    padding: 0;
    line-height: 1;
}

.scroll-btn:hover {
    background: #bb0404;
    color: #ffffff;
    border-color: #bb0404;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(187,4,4,0.3);
}

.scroll-btn:active {
    transform: translateY(0);
}

.scroll-btn i {
    font-size: 13px;
    display: block;
    line-height: 1;
}

/* TABLE RESPONSIVE */
.table-responsive {
    overflow-x: auto !important;
    overflow-y: hidden;
    max-width: 100% !important;
    width: 100% !important;
    -webkit-overflow-scrolling: touch;
    display: block;
    scroll-behavior: smooth;
}

.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: var(--bg-table-even);
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #bb0404, #8a0303);
    border-radius: 4px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 1200px;
}

.data-table thead {
    background: #bb0404;
    position: sticky;
    top: 0;
    z-index: 2;
}

.data-table thead th {
    padding: 12px;
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
.data-table tbody tr.search-hidden { display: none !important; }
.data-table tbody tr.search-match { 
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.18), rgba(252, 211, 77, 0.08)) !important;
    border-left: 4px solid #F59E0B;
}

.data-table tbody td {
    padding: 10px 12px;
    color: var(--text-secondary);
    vertical-align: middle;
}

.data-table mark {
    background: #FEF08A;
    color: #78350F;
    padding: 1px 3px;
    border-radius: 3px;
    font-weight: 800;
}

/* EMPLOYEE CELL */
.employee-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.employee-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: #FFFFFF;
    flex-shrink: 0;
    background: linear-gradient(135deg, #bb0404, #8a0303);
    border: 2px solid #FCA5A5;
    object-fit: cover;
}

.employee-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.employee-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

.employee-email {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
}

.id-badge {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 800;
    color: #bb0404;
    background: #FEE2E2;
    padding: 3px 10px;
    border-radius: 6px;
    white-space: nowrap;
    border: 1px solid #FCA5A5;
}

html.dark-mode .id-badge {
    background: #7F1D1D;
    color: #FCA5A5;
    border-color: #DC2626;
}

.contact-cell {
    font-size: 12px;
    color: var(--text-secondary);
    white-space: nowrap;
}

.contact-cell i {
    color: #bb0404;
    font-size: 10px;
    margin-right: 4px;
}

.role-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1.5px solid;
}

.role-super { background: #FEE2E2; color: #991B1B; border-color: #FCA5A5; }
.role-admin { background: #DBEAFE; color: #1E40AF; border-color: #93C5FD; }
.role-employee { background: #D1FAE5; color: #047857; border-color: #6EE7B7; }

html.dark-mode .role-super { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .role-admin { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .role-employee { background: #065F46; color: #6EE7B7; border-color: #059669; }

.position-text {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.branch-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 100px;
}

.branch-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

.branch-code-sm {
    font-size: 9px;
    font-weight: 700;
    color: #bb0404;
    font-family: 'Courier New', monospace;
    background: #FEE2E2;
    padding: 1px 6px;
    border-radius: 4px;
    align-self: flex-start;
}

html.dark-mode .branch-code-sm {
    background: #7F1D1D;
    color: #FCA5A5;
}

.salary-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    background: #D1FAE5;
    color: #047857;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1px solid #6EE7B7;
    white-space: nowrap;
}

html.dark-mode .salary-badge {
    background: #065F46;
    color: #6EE7B7;
    border-color: #059669;
}

.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
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
    padding: 0;
}

.btn-edit { background: #DBEAFE; color: #1E40AF; }
.btn-edit:hover { background: #1E40AF; color: #ffffff; }

.btn-success { background: #D1FAE5; color: #065F46; }
.btn-success:hover { background: #065F46; color: #ffffff; }

.btn-warning { background: #FEF3C7; color: #B45309; }
.btn-warning:hover { background: #B45309; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

.btn-self {
    background: var(--bg-table-even);
    color: var(--text-light);
    cursor: default;
}

/* ALERTS */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
}

.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

/* ============================================================
   🔐 PASSWORD TOGGLE
   ============================================================ */
.password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
}

.password-wrapper .form-control {
    padding-right: 44px;
    width: 100%;
}

.password-toggle-btn {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: transparent;
    border: none;
    color: #bb0404;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.2s ease;
    z-index: 2;
    padding: 0;
}

.password-toggle-btn:hover {
    background: #FEE2E2;
    color: #8a0303;
}

.password-toggle-btn:active {
    transform: translateY(-50%) scale(0.95);
}

.password-toggle-btn i {
    font-size: 14px;
    display: block;
    line-height: 1;
}

html.dark-mode .password-toggle-btn {
    color: #FCA5A5;
}

html.dark-mode .password-toggle-btn:hover {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* MODAL */
.modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
    backdrop-filter: blur(4px);
}

.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.modal-large { max-width: 800px; }

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    border-radius: 12px 12px 0 0;
    position: sticky;
    top: 0;
    z-index: 10;
}

.modal-header h4 {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-close {
    background: rgba(255,255,255,0.15);
    border: none;
    font-size: 24px;
    color: #FFFFFF;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    line-height: 1;
}

.modal-close:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.modal-body {
    padding: 24px;
    max-height: calc(90vh - 160px);
    overflow-y: auto;
}

.modal-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px dashed var(--border-color);
}

.modal-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.modal-section h5 {
    font-size: 13px;
    font-weight: 700;
    color: #bb0404;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.modal-section h5 i { font-size: 14px; }

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 20px 24px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-table-even);
    border-radius: 0 0 12px 12px;
    position: sticky;
    bottom: 0;
}

.modal .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 14px;
}

.modal .form-row:last-child { margin-bottom: 0; }

.modal .form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.modal .form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
}

.modal .form-group label .required { color: #DC2626; }

.modal .form-text {
    font-size: 11px;
    color: var(--text-muted);
    font-style: italic;
}

.modal .checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    cursor: pointer;
    padding: 9px 0;
}

.modal .checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #bb0404;
}

.no-data { padding: 40px 20px; text-align: center; }
.text-muted { color: var(--text-muted); }
.text-center { text-align: center; }

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
    font-family: 'Inter', sans-serif;
}

.dark-mode-btn:hover {
    background: var(--bg-table-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 900px) {
    .table-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .table-header-left {
        justify-content: space-between;
        width: 100%;
    }
    
    .table-header-right {
        width: 100%;
        justify-content: space-between;
        flex-wrap: wrap;
    }
    
    .table-search-live {
        flex: 1;
        min-width: 0;
        max-width: none;
    }
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .summary-cards { grid-template-columns: 1fr; }
    .filter-form { grid-template-columns: 1fr; }
    .filter-actions { flex-direction: row; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    .modal .form-row { grid-template-columns: 1fr; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
    .modal-body { max-height: 70vh; }
    
    .table-header-right {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    
    .table-search-live {
        width: 100%;
        max-width: none;
    }
    
    .table-scroll-buttons {
        justify-content: center;
        width: 100%;
    }
}

@media (max-width: 480px) {
    .summary-value { font-size: 16px; }
    .summary-icon { width: 42px; height: 42px; font-size: 18px; }
    .table-search-live { width: 100%; }
    .scroll-btn { width: 40px; height: 40px; font-size: 14px; }
    .page-header .header-left h2 { font-size: 18px; }
}
</style>

<script>
// ============================================================
// LIVE SEARCH
// ============================================================
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('employeesTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.employee-row');
    const clearBtn = document.getElementById('searchClearBtn');
    const countBadge = document.getElementById('searchCountBadge');
    const noResultsRow = document.getElementById('noSearchResultsRow');
    const totalBadge = document.getElementById('totalCountBadge');
    
    const term = searchTerm.trim();
    
    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';
    
    if (term.length === 0) {
        rows.forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
            removeAllMarks(row);
        });
        if (countBadge) { countBadge.style.display = 'none'; countBadge.textContent = '0'; }
        if (noResultsRow) noResultsRow.style.display = 'none';
        if (totalBadge) totalBadge.textContent = rows.length;
        return;
    }
    
    const searchLower = term.toLowerCase();
    let matchCount = 0;
    
    rows.forEach(row => {
        removeAllMarks(row);
        const searchText = (row.getAttribute('data-search') || '').toLowerCase();
        const rowText = row.textContent.toLowerCase();
        
        if (searchText.includes(searchLower) || rowText.includes(searchLower)) {
            row.classList.remove('search-hidden');
            row.classList.add('search-match');
            highlightMatchesInRow(row, term);
            matchCount++;
        } else {
            row.classList.add('search-hidden');
            row.classList.remove('search-match');
        }
    });
    
    if (countBadge) {
        countBadge.textContent = matchCount;
        countBadge.style.display = matchCount > 0 ? 'inline-block' : 'none';
    }
    
    if (totalBadge) totalBadge.textContent = matchCount;
    
    if (noResultsRow) {
        noResultsRow.style.display = matchCount === 0 && rows.length > 0 ? '' : 'none';
    }
}

function removeAllMarks(row) {
    const marks = row.querySelectorAll('mark');
    if (marks.length === 0) return;
    marks.forEach(mark => {
        if (mark.parentNode) {
            const textNode = document.createTextNode(mark.textContent);
            mark.parentNode.replaceChild(textNode, mark);
        }
    });
    const cells = row.querySelectorAll('td');
    cells.forEach(cell => cell.normalize());
}

function highlightMatchesInRow(row, term) {
    if (!term || term.length === 0) return;
    const searchLower = term.toLowerCase();
    const termLength = term.length;
    const cells = row.querySelectorAll('td');
    
    cells.forEach(cell => {
        if (cell.querySelector('button') || cell.querySelector('a')) return;
        if (cell.querySelector('img') && cell.textContent.trim() === '') return;
        
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
            acceptNode: function(node) {
                if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'BUTTON') return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        
        const textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        
        textNodes.forEach(textNode => {
            const text = textNode.textContent;
            const lowerText = text.toLowerCase();
            if (!lowerText.includes(searchLower)) return;
            
            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            let index = lowerText.indexOf(searchLower);
            
            while (index !== -1) {
                if (index > lastIndex) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIndex, index)));
                }
                const mark = document.createElement('mark');
                mark.textContent = text.substring(index, index + termLength);
                fragment.appendChild(mark);
                lastIndex = index + termLength;
                index = lowerText.indexOf(searchLower, lastIndex);
            }
            if (lastIndex < text.length) {
                fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
            }
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    });
}

function clearLiveSearch() {
    const input = document.getElementById('liveSearchInput');
    if (input) { 
        input.value = ''; 
        performLiveSearch(''); 
        input.focus(); 
    }
}

// ============================================================
// SCROLL TABLE
// ============================================================
function scrollTable(direction) {
    const wrapper = document.getElementById('tableWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({
        left: direction === 'left' ? -350 : 350,
        behavior: 'smooth'
    });
}

// ============================================================
// 🔐 TOGGLE PASSWORD
// ============================================================
function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    if (!input) return;
    
    const icon = button.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
        button.title = 'Hide password';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
        button.title = 'Show password';
    }
    
    input.focus();
}

// ============================================================
// ADD MODAL
// ============================================================
function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
    document.body.style.overflow = '';
    // Reset password toggle
    const pwdInput = document.getElementById('add_password');
    const pwdBtn = pwdInput ? pwdInput.parentElement.querySelector('.password-toggle-btn') : null;
    if (pwdInput && pwdBtn) {
        pwdInput.type = 'password';
        pwdBtn.querySelector('i').className = 'fas fa-eye';
    }
}

// ============================================================
// EDIT MODAL
// ============================================================
function editEmployee(emp) {
    document.getElementById('edit_employee_id_hidden').value = emp.id;
    document.getElementById('edit_full_name').value = emp.full_name || '';
    document.getElementById('edit_employee_id').value = emp.employee_id || '';
    document.getElementById('edit_email').value = emp.email || '';
    document.getElementById('edit_phone').value = emp.phone || '';
    document.getElementById('edit_gender').value = emp.gender || '';
    document.getElementById('edit_date_of_birth').value = emp.date_of_birth || '';
    document.getElementById('edit_role').value = emp.role || 'employee';
    document.getElementById('edit_position').value = emp.position || '';
    document.getElementById('edit_branch_id').value = emp.branch_id || '';
    document.getElementById('edit_base_salary').value = emp.base_salary || 0;
    document.getElementById('edit_hire_date').value = emp.hire_date || '';
    document.getElementById('edit_employment_status').value = emp.employment_status || 'active';
    document.getElementById('edit_is_active').checked = emp.is_active == 1;
    document.getElementById('edit_emergency_contact').value = emp.emergency_contact || '';
    document.getElementById('edit_emergency_phone').value = emp.emergency_phone || '';
    document.getElementById('edit_address').value = emp.address || '';
    
    // Reset new password field
    const newPwdInput = document.getElementById('edit_new_password');
    if (newPwdInput) {
        newPwdInput.value = '';
        newPwdInput.type = 'password';
        const pwdBtn = newPwdInput.parentElement.querySelector('.password-toggle-btn');
        if (pwdBtn) {
            pwdBtn.querySelector('i').className = 'fas fa-eye';
        }
    }
    
    document.getElementById('editModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ============================================================
// CLOSE MODALS
// ============================================================
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
        const i = document.getElementById('liveSearchInput');
        if (i && i.value.length > 0) clearLiveSearch();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const i = document.getElementById('liveSearchInput');
        if (i) { i.focus(); i.select(); }
    }
});

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
    
    console.log('%c 👥 Employees Page - Final Version', 
        'background:#bb0404; color:white; padding:4px 12px; border-radius:4px; font-size:12px;');
});
</script>

</body>
</html>