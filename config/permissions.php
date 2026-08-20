<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\config\permissions.php
// WAKALA FINANCIAL SYSTEM - ROLE-BASED PERMISSIONS
// ================================================================

// ============================================================
// PERMISSIONS MATRIX
// ============================================================
$permissions = [
    
    // ============================================================
    // SUPER ADMIN - Full access to everything
    // ============================================================
    'super_admin' => [
        'dashboard' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'morning_report' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'evening_stock' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'commissions' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'expenses' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'store_cash_out' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'capital_management' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'salaries' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'employees' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'settings' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'activity_logs' => ['view' => true, 'add' => false, 'edit' => false, 'delete' => false],
        'profile' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
    ],
    
    // ============================================================
    // ADMIN - Full access except system settings and employee management
    // ============================================================
    'admin' => [
        'dashboard' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'morning_report' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'evening_stock' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'commissions' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'expenses' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'store_cash_out' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'capital_management' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'salaries' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => true],
        'employees' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
        'settings' => ['view' => true, 'add' => false, 'edit' => false, 'delete' => false],
        'activity_logs' => ['view' => true, 'add' => false, 'edit' => false, 'delete' => false],
        'profile' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
    ],
    
    // ============================================================
    // EMPLOYEE - Can only view and add their own records
    // ============================================================
    'employee' => [
        'dashboard' => ['view' => true, 'add' => false, 'edit' => false, 'delete' => false],
        'morning_report' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
        'evening_stock' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
        'commissions' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
        'expenses' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
        'store_cash_out' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
        'capital_management' => ['view' => true, 'add' => false, 'edit' => false, 'delete' => false],
        'salaries' => ['view' => true, 'add' => false, 'edit' => false, 'delete' => false],
        'profile' => ['view' => true, 'add' => true, 'edit' => true, 'delete' => false],
    ]
];

// ============================================================
// PERMISSION CHECK FUNCTIONS
// ============================================================

/**
 * Check if user has permission for a module action
 * 
 * @param string $module The module name
 * @param string $action The action (view, add, edit, delete)
 * @return bool True if user has permission
 */
function hasPermission($module, $action) {
    global $permissions;
    $role = $_SESSION['role'] ?? 'employee';
    
    if (!isset($permissions[$role][$module])) {
        return false;
    }
    
    return $permissions[$role][$module][$action] ?? false;
}

/**
 * Check if user can view a module
 * 
 * @param string $module The module name
 * @return bool True if user can view
 */
function canView($module) {
    return hasPermission($module, 'view');
}

/**
 * Check if user can add to a module
 * 
 * @param string $module The module name
 * @return bool True if user can add
 */
function canAdd($module) {
    return hasPermission($module, 'add');
}

/**
 * Check if user can edit in a module
 * 
 * @param string $module The module name
 * @return bool True if user can edit
 */
function canEdit($module) {
    return hasPermission($module, 'edit');
}

/**
 * Check if user can delete from a module
 * 
 * @param string $module The module name
 * @return bool True if user can delete
 */
function canDelete($module) {
    return hasPermission($module, 'delete');
}

/**
 * Check if user is admin (admin or super_admin)
 * 
 * @return bool True if user is admin
 */
function isAdmin() {
    $role = $_SESSION['role'] ?? 'employee';
    return $role === 'admin' || $role === 'super_admin';
}

/**
 * Check if user is super admin
 * 
 * @return bool True if user is super admin
 */
function isSuperAdmin() {
    return ($_SESSION['role'] ?? '') === 'super_admin';
}

/**
 * Check if user is logged in
 * 
 * @return bool True if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
?>