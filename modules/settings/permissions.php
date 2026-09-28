<?php
// ================================================================
// FILE: modules/settings/permissions.php
// PERMISSIONS MANAGEMENT - RED THEME
// ✅ Role-based permissions matrix with toggle switches
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
// GET SELECTED ROLE
// ============================================================
$selected_role = isset($_GET['role']) ? trim($_GET['role']) : 'employee';
$allowed_roles = ['super_admin', 'admin', 'employee'];
if (!in_array($selected_role, $allowed_roles)) {
    $selected_role = 'employee';
}

// ============================================================
// MODULES LIST (Static)
// ============================================================
$all_modules = [
    'dashboard' => ['name' => 'Dashboard', 'icon' => 'fas fa-home', 'color' => '#2563EB', 'desc' => 'Main dashboard overview'],
    'morning_report' => ['name' => 'Morning Report', 'icon' => 'fas fa-sun', 'color' => '#F59E0B', 'desc' => 'Daily morning reports'],
    'evening_stock' => ['name' => 'Evening Stock', 'icon' => 'fas fa-moon', 'color' => '#7C3AED', 'desc' => 'Evening stock management'],
    'daily_report' => ['name' => 'Daily Report', 'icon' => 'fas fa-file-alt', 'color' => '#0891b2', 'desc' => 'Daily reports'],
    'commissions' => ['name' => 'Commissions', 'icon' => 'fas fa-hand-holding-usd', 'color' => '#059669', 'desc' => 'Commission management'],
    'expenses' => ['name' => 'Expenses', 'icon' => 'fas fa-receipt', 'color' => '#DC2626', 'desc' => 'Expense tracking'],
    'store_cash_out' => ['name' => 'Store Cash Out', 'icon' => 'fas fa-money-bill-wave', 'color' => '#D97706', 'desc' => 'Cash out requests'],
    'capital_management' => ['name' => 'Capital', 'icon' => 'fas fa-building', 'color' => '#4F46E5', 'desc' => 'Capital management'],
    'salaries' => ['name' => 'Salaries', 'icon' => 'fas fa-wallet', 'color' => '#DB2777', 'desc' => 'Employee salaries'],
    'reports' => ['name' => 'Reports', 'icon' => 'fas fa-chart-pie', 'color' => '#7C3AED', 'desc' => 'Reports dashboard'],
    'employees' => ['name' => 'Employees', 'icon' => 'fas fa-users', 'color' => '#0891b2', 'desc' => 'Employee management'],
    'settings' => ['name' => 'Settings', 'icon' => 'fas fa-cog', 'color' => '#6B7280', 'desc' => 'System settings'],
    'activity_logs' => ['name' => 'Activity Logs', 'icon' => 'fas fa-history', 'color' => '#4B5563', 'desc' => 'System activity logs'],
    'profile' => ['name' => 'Profile', 'icon' => 'fas fa-user-circle', 'color' => '#059669', 'desc' => 'User profile'],
];

// ============================================================
// HANDLE SAVE PERMISSIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_permissions'])) {
    try {
        $save_role = $_POST['role'] ?? 'employee';
        
        if (!in_array($save_role, $allowed_roles)) {
            throw new Exception('Invalid role selected.');
        }
        
        $db->beginTransaction();
        
        // Delete existing permissions for this role
        $stmt = $db->prepare("DELETE FROM user_permissions WHERE role = ?");
        $stmt->execute([$save_role]);
        
        // Insert new permissions
        $stmt = $db->prepare("
            INSERT INTO user_permissions (role, module, can_view, can_add, can_edit, can_delete, can_export)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $saved_count = 0;
        $modules_post = $_POST['modules'] ?? [];
        
        foreach ($all_modules as $module_key => $module_info) {
            $perms = $modules_post[$module_key] ?? [];
            
            $can_view = isset($perms['view']) ? 1 : 0;
            $can_add = isset($perms['add']) ? 1 : 0;
            $can_edit = isset($perms['edit']) ? 1 : 0;
            $can_delete = isset($perms['delete']) ? 1 : 0;
            $can_export = isset($perms['export']) ? 1 : 0;
            
            // Only save if at least one permission is checked, OR always save
            $stmt->execute([
                $save_role, $module_key, 
                $can_view, $can_add, $can_edit, $can_delete, $can_export
            ]);
            $saved_count++;
        }
        
        $db->commit();
        
        logActivity($_SESSION['user_id'], 'Update Permissions', 'Settings', null, null, json_encode([
            'role' => $save_role,
            'modules_count' => $saved_count
        ]));
        
        $success = "Permissions saved successfully for " . ucfirst(str_replace('_', ' ', $save_role)) . "! ({$saved_count} modules)";
        
        // Redirect to same role
        header("Refresh: 1; URL=permissions.php?role={$save_role}");
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error saving permissions: " . $e->getMessage());
    }
}

// ============================================================
// HANDLE RESET TO DEFAULT
// ============================================================
if (isset($_GET['reset']) && $_GET['reset'] === 'default') {
    try {
        $db->beginTransaction();
        
        // Delete existing permissions for selected role
        $stmt = $db->prepare("DELETE FROM user_permissions WHERE role = ?");
        $stmt->execute([$selected_role]);
        
        // Default permissions based on role
        $default_perms = getDefaultPermissions($selected_role, $all_modules);
        
        $stmt = $db->prepare("
            INSERT INTO user_permissions (role, module, can_view, can_add, can_edit, can_delete, can_export)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($default_perms as $module_key => $perms) {
            $stmt->execute([
                $selected_role, $module_key,
                $perms['view'], $perms['add'], $perms['edit'], 
                $perms['delete'], $perms['export']
            ]);
        }
        
        $db->commit();
        
        logActivity($_SESSION['user_id'], 'Reset Permissions', 'Settings', null, null, json_encode([
            'role' => $selected_role
        ]));
        
        $success = 'Permissions reset to default successfully!';
        header("Refresh: 1; URL=permissions.php?role={$selected_role}");
        
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// ============================================================
// FUNCTION: GET DEFAULT PERMISSIONS FOR ROLE
// ============================================================
function getDefaultPermissions($role, $modules) {
    $perms = [];
    
    foreach ($modules as $key => $info) {
        $perms[$key] = [
            'view' => 1, 'add' => 0, 'edit' => 0, 'delete' => 0, 'export' => 0
        ];
        
        if ($role === 'super_admin' || $role === 'admin') {
            // Admin/Super Admin - full access
            if ($key !== 'settings' || $role === 'super_admin') {
                $perms[$key] = [
                    'view' => 1, 'add' => 1, 'edit' => 1, 
                    'delete' => ($role === 'super_admin' ? 1 : 0), 
                    'export' => 1
                ];
            }
        }
    }
    
    return $perms;
}

// ============================================================
// GET CURRENT PERMISSIONS FOR SELECTED ROLE
// ============================================================
$current_permissions = [];
try {
    $stmt = $db->prepare("SELECT * FROM user_permissions WHERE role = ?");
    $stmt->execute([$selected_role]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        $current_permissions[$row['module']] = [
            'view' => intval($row['can_view']),
            'add' => intval($row['can_add']),
            'edit' => intval($row['can_edit']),
            'delete' => intval($row['can_delete']),
            'export' => intval($row['can_export']),
        ];
    }
    
} catch (PDOException $e) {
    error_log("Error loading permissions: " . $e->getMessage());
}

// ============================================================
// STATS - Count permissions
// ============================================================
$total_perms = 0;
$enabled_perms = 0;
foreach ($all_modules as $key => $info) {
    $total_perms += 5; // 5 permissions per module
    if (isset($current_permissions[$key])) {
        $p = $current_permissions[$key];
        $enabled_perms += $p['view'] + $p['add'] + $p['edit'] + $p['delete'] + $p['export'];
    }
}

// ============================================================
// ROLE STATS
// ============================================================
$role_stats = [];
foreach ($allowed_roles as $r) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE role = ?");
        $stmt->execute([$r]);
        $role_stats[$r] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $role_stats[$r] = 0;
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
                <h2><i class="fas fa-lock" style="color:#bb0404;"></i> Permissions Management</h2>
                <p class="text-muted">Manage role-based permissions for each module</p>
            </div>
            <div class="header-right">
                <a href="?role=<?php echo $selected_role; ?>&reset=default" 
                   class="btn btn-warning"
                   onclick="return confirm('Reset permissions to default for this role?');">
                    <i class="fas fa-undo"></i> Reset to Default
                </a>
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

        <!-- Role Tabs -->
        <div class="role-tabs">
            <a href="?role=super_admin" class="role-tab <?php echo $selected_role === 'super_admin' ? 'active' : ''; ?>">
                <div class="role-tab-icon" style="background: #FEE2E2; color: #991B1B;">
                    <i class="fas fa-crown"></i>
                </div>
                <div class="role-tab-info">
                    <span class="role-tab-name">Super Admin</span>
                    <span class="role-tab-count"><?php echo $role_stats['super_admin'] ?? 0; ?> users</span>
                </div>
            </a>
            
            <a href="?role=admin" class="role-tab <?php echo $selected_role === 'admin' ? 'active' : ''; ?>">
                <div class="role-tab-icon" style="background: #DBEAFE; color: #1E40AF;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="role-tab-info">
                    <span class="role-tab-name">Admin</span>
                    <span class="role-tab-count"><?php echo $role_stats['admin'] ?? 0; ?> users</span>
                </div>
            </a>
            
            <a href="?role=employee" class="role-tab <?php echo $selected_role === 'employee' ? 'active' : ''; ?>">
                <div class="role-tab-icon" style="background: #D1FAE5; color: #047857;">
                    <i class="fas fa-user"></i>
                </div>
                <div class="role-tab-info">
                    <span class="role-tab-name">Employee</span>
                    <span class="role-tab-count"><?php echo $role_stats['employee'] ?? 0; ?> users</span>
                </div>
            </a>
        </div>

        <!-- Info Banner -->
        <div class="info-banner">
            <div class="info-banner-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="info-banner-content">
                <strong>Managing permissions for: <span class="role-highlight"><?php echo ucfirst(str_replace('_', ' ', $selected_role)); ?></span></strong>
                <p>
                    Configure what users with this role can do in each module. 
                    Toggle switches to grant or revoke permissions.
                    Currently <strong><?php echo $enabled_perms; ?>/<?php echo $total_perms; ?></strong> permissions enabled.
                </p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <button type="button" class="quick-action-btn" onclick="selectAll()">
                <i class="fas fa-check-double"></i> Select All
            </button>
            <button type="button" class="quick-action-btn" onclick="clearAll()">
                <i class="fas fa-times-circle"></i> Clear All
            </button>
            <button type="button" class="quick-action-btn" onclick="selectAllView()">
                <i class="fas fa-eye"></i> View Only
            </button>
            <div class="quick-search">
                <i class="fas fa-search"></i>
                <input type="text" 
                       id="moduleSearch" 
                       placeholder="Search modules..." 
                       oninput="filterModules(this.value)">
            </div>
        </div>

        <!-- Permissions Form -->
        <form method="POST" action="" id="permissionsForm">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($selected_role); ?>">
            <input type="hidden" name="save_permissions" value="1">
            
            <div class="table-container">
                <div class="table-header">
                    <div class="table-header-left">
                        <h4><i class="fas fa-th-large"></i> Modules & Permissions</h4>
                        <span class="count-badge"><?php echo count($all_modules); ?></span>
                    </div>
                    
                    <div class="table-header-right">
                        <div class="table-legend">
                            <span class="legend-item">
                                <span class="legend-dot dot-view"></span> View
                            </span>
                            <span class="legend-item">
                                <span class="legend-dot dot-add"></span> Add
                            </span>
                            <span class="legend-item">
                                <span class="legend-dot dot-edit"></span> Edit
                            </span>
                            <span class="legend-item">
                                <span class="legend-dot dot-delete"></span> Delete
                            </span>
                            <span class="legend-item">
                                <span class="legend-dot dot-export"></span> Export
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="permissions-table" id="permissionsTable">
                        <thead>
                            <tr>
                                <th class="col-module">Module</th>
                                <th class="col-perm text-center">
                                    <span class="perm-header view">
                                        <i class="fas fa-eye"></i> View
                                    </span>
                                </th>
                                <th class="col-perm text-center">
                                    <span class="perm-header add">
                                        <i class="fas fa-plus"></i> Add
                                    </span>
                                </th>
                                <th class="col-perm text-center">
                                    <span class="perm-header edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </span>
                                </th>
                                <th class="col-perm text-center">
                                    <span class="perm-header delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </span>
                                </th>
                                <th class="col-perm text-center">
                                    <span class="perm-header export">
                                        <i class="fas fa-download"></i> Export
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="permissionsTableBody">
                            <?php foreach ($all_modules as $key => $info): 
                                $perms = $current_permissions[$key] ?? ['view' => 0, 'add' => 0, 'edit' => 0, 'delete' => 0, 'export' => 0];
                                $search_text = strtolower($info['name'] . ' ' . $key . ' ' . $info['desc']);
                            ?>
                                <tr class="module-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td class="col-module">
                                        <div class="module-cell">
                                            <div class="module-icon" style="background: <?php echo $info['color']; ?>;">
                                                <i class="<?php echo $info['icon']; ?>"></i>
                                            </div>
                                            <div class="module-info">
                                                <span class="module-name"><?php echo htmlspecialchars($info['name']); ?></span>
                                                <span class="module-desc"><?php echo htmlspecialchars($info['desc']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td class="text-center">
                                        <label class="perm-switch">
                                            <input type="checkbox" 
                                                   name="modules[<?php echo $key; ?>][view]" 
                                                   value="1"
                                                   data-module="<?php echo $key; ?>"
                                                   data-perm="view"
                                                   <?php echo $perms['view'] ? 'checked' : ''; ?>>
                                            <span class="perm-slider perm-view"></span>
                                        </label>
                                    </td>
                                    
                                    <td class="text-center">
                                        <label class="perm-switch">
                                            <input type="checkbox" 
                                                   name="modules[<?php echo $key; ?>][add]" 
                                                   value="1"
                                                   data-module="<?php echo $key; ?>"
                                                   data-perm="add"
                                                   <?php echo $perms['add'] ? 'checked' : ''; ?>>
                                            <span class="perm-slider perm-add"></span>
                                        </label>
                                    </td>
                                    
                                    <td class="text-center">
                                        <label class="perm-switch">
                                            <input type="checkbox" 
                                                   name="modules[<?php echo $key; ?>][edit]" 
                                                   value="1"
                                                   data-module="<?php echo $key; ?>"
                                                   data-perm="edit"
                                                   <?php echo $perms['edit'] ? 'checked' : ''; ?>>
                                            <span class="perm-slider perm-edit"></span>
                                        </label>
                                    </td>
                                    
                                    <td class="text-center">
                                        <label class="perm-switch">
                                            <input type="checkbox" 
                                                   name="modules[<?php echo $key; ?>][delete]" 
                                                   value="1"
                                                   data-module="<?php echo $key; ?>"
                                                   data-perm="delete"
                                                   <?php echo $perms['delete'] ? 'checked' : ''; ?>>
                                            <span class="perm-slider perm-delete"></span>
                                        </label>
                                    </td>
                                    
                                    <td class="text-center">
                                        <label class="perm-switch">
                                            <input type="checkbox" 
                                                   name="modules[<?php echo $key; ?>][export]" 
                                                   value="1"
                                                   data-module="<?php echo $key; ?>"
                                                   data-perm="export"
                                                   <?php echo $perms['export'] ? 'checked' : ''; ?>>
                                            <span class="perm-slider perm-export"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <tr id="noSearchResultsRow" style="display:none;">
                                <td colspan="6" class="no-data">
                                    <i class="fas fa-search-minus" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No modules match your search</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Save Bar -->
            <div class="save-bar">
                <div class="save-bar-info">
                    <i class="fas fa-shield-alt"></i>
                    <span>Changes will be saved for <strong><?php echo ucfirst(str_replace('_', ' ', $selected_role)); ?></strong> role</span>
                </div>
                <div class="save-bar-actions">
                    <a href="permissions.php?role=<?php echo $selected_role; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary btn-save">
                        <i class="fas fa-save"></i> Save Permissions
                    </button>
                </div>
            </div>
        </form>

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

/* FIX OVERFLOW */
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

*, *::before, *::after { box-sizing: border-box; }

/* PAGE HEADER */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
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

/* ROLE TABS */
.role-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.role-tab {
    background: var(--bg-card);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.role-tab:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px var(--shadow-hover);
    border-color: #bb0404;
}

.role-tab.active {
    border-color: #bb0404;
    background: linear-gradient(135deg, rgba(187, 4, 4, 0.05), rgba(187, 4, 4, 0.02));
    box-shadow: 0 4px 16px rgba(187, 4, 4, 0.15);
}

.role-tab.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: #bb0404;
}

.role-tab-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    transition: transform 0.3s ease;
}

.role-tab:hover .role-tab-icon {
    transform: scale(1.08) rotate(-4deg);
}

.role-tab-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
    flex: 1;
}

.role-tab-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

.role-tab-count {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
}

/* INFO BANNER */
.info-banner {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 1.5px solid #93C5FD;
    border-left: 5px solid #1E40AF;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
}

html.dark-mode .info-banner {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    border-color: #3B82F6;
    border-left-color: #60A5FA;
}

.info-banner-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #1E40AF;
    color: #FFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}

.info-banner-content {
    flex: 1;
    min-width: 0;
}

.info-banner-content strong {
    font-size: 14px;
    color: #1E40AF;
    display: block;
    margin-bottom: 6px;
}

html.dark-mode .info-banner-content strong { color: #93C5FD; }

.info-banner-content p {
    font-size: 13px;
    color: #1E3A8A;
    margin: 0;
    line-height: 1.6;
}

html.dark-mode .info-banner-content p { color: #DBEAFE; }

.role-highlight {
    background: rgba(255, 255, 255, 0.5);
    padding: 2px 8px;
    border-radius: 6px;
    font-weight: 800;
}

html.dark-mode .role-highlight { background: rgba(0,0,0,0.3); color: #93C5FD; }

/* QUICK ACTIONS */
.quick-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.quick-action-btn {
    padding: 9px 16px;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-secondary);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
}

.quick-action-btn:hover {
    background: #FEE2E2;
    color: #bb0404;
    border-color: #FCA5A5;
    transform: translateY(-2px);
}

.quick-search {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    padding: 6px 12px;
    margin-left: auto;
    min-width: 240px;
    transition: all 0.3s ease;
}

.quick-search:focus-within {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.quick-search i { color: #bb0404; font-size: 13px; }

.quick-search input {
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

.quick-search input::placeholder { color: var(--text-muted); font-size: 12px; }

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

.btn-warning { background: #F59E0B; color: white; }
.btn-warning:hover { background: #D97706; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(217,119,6,0.3); }

.btn-save {
    padding: 11px 28px;
    font-size: 14px;
    font-weight: 700;
}

/* TABLE CONTAINER */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    width: 100%;
    overflow: hidden;
    margin-bottom: 20px;
}

/* TABLE HEADER */
.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 12px;
    background: var(--bg-table-even);
}

.table-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
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

.table-legend {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.dot-view { background: #2563EB; }
.dot-add { background: #059669; }
.dot-edit { background: #D97706; }
.dot-delete { background: #DC2626; }
.dot-export { background: #7C3AED; }

/* TABLE RESPONSIVE */
.table-responsive {
    overflow-x: auto;
    max-width: 100%;
    width: 100%;
}

.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-track {
    background: var(--bg-table-even);
    border-radius: 4px;
}
.table-responsive::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #bb0404, #8a0303);
    border-radius: 4px;
}

/* PERMISSIONS TABLE */
.permissions-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 900px;
}

.permissions-table thead {
    background: #bb0404;
    position: sticky;
    top: 0;
    z-index: 2;
}

.permissions-table thead th {
    padding: 14px 12px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}

.permissions-table thead th.text-center { text-align: center; }

.col-module { width: 40%; }
.col-perm { width: 12%; }

.perm-header {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.2);
}

.perm-header i { font-size: 11px; }

.permissions-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}

.permissions-table tbody tr:hover { background: var(--bg-table-hover); }
.permissions-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.permissions-table tbody tr.module-hidden { display: none !important; }

.permissions-table tbody td {
    padding: 14px 12px;
    color: var(--text-secondary);
    vertical-align: middle;
}

.permissions-table tbody td.text-center { text-align: center; }

/* MODULE CELL */
.module-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.module-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    color: #FFFFFF;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.module-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.module-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}

.module-desc {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 300px;
}

/* PERMISSION SWITCH */
.perm-switch {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: relative;
    cursor: pointer;
    user-select: none;
}

.perm-switch input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.perm-slider {
    position: relative;
    display: block;
    width: 44px;
    height: 24px;
    background: #D1D5DB;
    border-radius: 12px;
    transition: all 0.3s ease;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}

.perm-slider::before {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    left: 3px;
    top: 3px;
    background: #FFFFFF;
    border-radius: 50%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.perm-switch input:checked + .perm-slider::before {
    transform: translateX(20px);
}

.perm-switch input:focus + .perm-slider {
    box-shadow: 0 0 0 3px rgba(187,4,4,0.15), inset 0 2px 4px rgba(0,0,0,0.1);
}

/* Colored Sliders */
.perm-switch input:checked + .perm-view { background: #2563EB; }
.perm-switch input:checked + .perm-add { background: #059669; }
.perm-switch input:checked + .perm-edit { background: #D97706; }
.perm-switch input:checked + .perm-delete { background: #DC2626; }
.perm-switch input:checked + .perm-export { background: #7C3AED; }

.perm-switch:hover .perm-slider {
    transform: scale(1.05);
}

/* SAVE BAR */
.save-bar {
    position: sticky;
    bottom: 20px;
    background: var(--bg-card);
    border: 2px solid #bb0404;
    border-radius: 12px;
    padding: 16px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    box-shadow: 0 8px 24px rgba(187, 4, 4, 0.2);
    flex-wrap: wrap;
    z-index: 100;
    backdrop-filter: blur(10px);
}

.save-bar-info {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-secondary);
    font-weight: 500;
}

.save-bar-info i {
    color: #bb0404;
    font-size: 18px;
}

.save-bar-info strong {
    color: var(--text-primary);
    font-weight: 700;
}

.save-bar-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
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

/* NO DATA */
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
    .role-tabs { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .quick-actions { flex-direction: column; align-items: stretch; }
    .quick-action-btn { width: 100%; justify-content: center; }
    .quick-search { margin-left: 0; width: 100%; min-width: 0; }
    
    .table-legend { display: none; }
    
    .permissions-table { min-width: 700px; }
    .module-desc { display: none; }
    
    .save-bar { flex-direction: column; align-items: stretch; text-align: center; }
    .save-bar-info { justify-content: center; }
    .save-bar-actions { flex-direction: column; }
    .save-bar-actions .btn { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
    .page-header .header-left h2 { font-size: 18px; }
    .role-tab-icon { width: 44px; height: 44px; font-size: 20px; }
    .role-tab-name { font-size: 13px; }
    .perm-slider { width: 38px; height: 20px; }
    .perm-slider::before { width: 14px; height: 14px; }
    .perm-switch input:checked + .perm-slider::before { transform: translateX(18px); }
}
</style>

<script>
// ============================================================
// SELECT ALL / CLEAR ALL
// ============================================================
function selectAll() {
    const checkboxes = document.querySelectorAll('#permissionsTableBody input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = true);
    showToast('All permissions selected');
}

function clearAll() {
    if (!confirm('Are you sure you want to clear all permissions?')) return;
    const checkboxes = document.querySelectorAll('#permissionsTableBody input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = false);
    showToast('All permissions cleared');
}

function selectAllView() {
    const checkboxes = document.querySelectorAll('#permissionsTableBody input[type="checkbox"]');
    checkboxes.forEach(cb => {
        if (cb.getAttribute('data-perm') === 'view') {
            cb.checked = true;
        } else {
            cb.checked = false;
        }
    });
    showToast('View-only permissions set');
}

// ============================================================
// FILTER MODULES (LIVE SEARCH)
// ============================================================
function filterModules(searchTerm) {
    const rows = document.querySelectorAll('#permissionsTableBody tr.module-row');
    const noResultsRow = document.getElementById('noSearchResultsRow');
    const term = searchTerm.trim().toLowerCase();
    
    if (term === '') {
        rows.forEach(row => row.classList.remove('module-hidden'));
        if (noResultsRow) noResultsRow.style.display = 'none';
        return;
    }
    
    let matchCount = 0;
    rows.forEach(row => {
        const searchText = (row.getAttribute('data-search') || '').toLowerCase();
        if (searchText.includes(term)) {
            row.classList.remove('module-hidden');
            matchCount++;
        } else {
            row.classList.add('module-hidden');
        }
    });
    
    if (noResultsRow) {
        noResultsRow.style.display = matchCount === 0 ? '' : 'none';
    }
}

// ============================================================
// TOAST NOTIFICATIONS
// ============================================================
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = 'toast-notification toast-' + type;
    toast.innerHTML = '<i class="fas fa-info-circle"></i> ' + message;
    
    const colors = {
        info: '#2563EB',
        success: '#059669',
        warning: '#D97706',
        danger: '#DC2626'
    };
    
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${colors[type] || colors.info};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 13px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        z-index: 99999;
        display: flex;
        align-items: center;
        gap: 8px;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 2000);
}

// Add keyframes for toast
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(400px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

// ============================================================
// FORM SUBMIT
// ============================================================
document.getElementById('permissionsForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('.btn-save');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
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
    
    // Search shortcut Ctrl+K
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const search = document.getElementById('moduleSearch');
            if (search) search.focus();
        }
    });
    
    console.log('%c 🔐 Permissions Management Loaded', 
        'background:#bb0404; color:white; padding:4px 12px; border-radius:4px; font-size:12px;');
});
</script>

</body>
</html>