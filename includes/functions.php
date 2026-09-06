<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\includes\functions.php
// WAKALA FINANCIAL SYSTEM - HELPER FUNCTIONS
// ================================================================

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

// ============================================================
// AUTHENTICATION FUNCTIONS
// ============================================================

/**
 * Check if user is logged in
 * 
 * @return bool True if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user is admin (admin or super_admin)
 * 
 * @return bool True if user is admin
 */
function isAdmin() {
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin');
}

/**
 * Check if user is super admin
 * 
 * @return bool True if user is super admin
 */
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

/**
 * Get current user ID
 * 
 * @return int|false User ID or false if not logged in
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? false;
}

/**
 * Get current user role
 * 
 * @return string|false User role or false if not logged in
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? false;
}

/**
 * Get current user full name
 * 
 * @return string|false User full name or false if not logged in
 */
function getCurrentUserName() {
    return $_SESSION['full_name'] ?? false;
}

// ============================================================
// FORMATTING FUNCTIONS
// ============================================================

/**
 * Format currency amount
 * 
 * @param float $amount The amount to format
 * @return string Formatted currency string
 */
function formatCurrency($amount) {
    return CURRENCY . ' ' . number_format((float)$amount, 0, '.', ',');
}

/**
 * Format currency for display (short format)
 * 
 * @param float $amount The amount to format
 * @return string Formatted currency string
 */
function formatCurrencyShort($amount) {
    if ($amount >= 1000000) {
        return CURRENCY . ' ' . number_format($amount / 1000000, 1) . 'M';
    } elseif ($amount >= 1000) {
        return CURRENCY . ' ' . number_format($amount / 1000, 1) . 'K';
    }
    return formatCurrency($amount);
}

/**
 * Format date
 * 
 * @param string $date The date string
 * @return string Formatted date
 */
function formatDate($date) {
    return date(DATE_FORMAT, strtotime($date));
}

/**
 * Format datetime
 * 
 * @param string $datetime The datetime string
 * @return string Formatted datetime
 */
function formatDateTime($datetime) {
    return date(DATETIME_FORMAT, strtotime($datetime));
}

/**
 * Format time
 * 
 * @param string $time The time string
 * @return string Formatted time
 */
function formatTime($time) {
    return date(TIME_FORMAT, strtotime($time));
}

// ============================================================
// GENERATE FUNCTIONS
// ============================================================

/**
 * Generate unique transaction number
 * 
 * @param string $prefix The prefix for the number
 * @return string Unique number
 */
function generateNumber($prefix) {
    return $prefix . '-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Generate employee ID
 * 
 * @return string Unique employee ID
 */
function generateEmployeeId() {
    global $db;
    $stmt = $db->query("SELECT COUNT(*) as count FROM employees");
    $count = $stmt->fetch()['count'] ?? 0;
    return 'EMP-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate report number
 * 
 * @return string Unique report number
 */
function generateReportNumber() {
    return 'RPT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Generate commission number
 * 
 * @return string Unique commission number
 */
function generateCommissionNumber() {
    return 'COM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Generate expense number
 * 
 * @return string Unique expense number
 */
function generateExpenseNumber() {
    return 'EXP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Generate cash out number
 * 
 * @return string Unique cash out number
 */
function generateCashOutNumber() {
    return 'CO-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Generate salary number
 * 
 * @return string Unique salary number
 */
function generateSalaryNumber() {
    return 'SAL-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// ============================================================
// SANITIZATION FUNCTIONS
// ============================================================

/**
 * Sanitize input string
 * 
 * @param string $input The input to sanitize
 * @return string Sanitized string
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize array of inputs
 * 
 * @param array $array The array to sanitize
 * @return array Sanitized array
 */
function sanitizeArray($array) {
    return array_map('sanitize', $array);
}

/**
 * Validate email
 * 
 * @param string $email The email to validate
 * @return bool True if valid email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (Tanzanian format)
 * 
 * @param string $phone The phone number to validate
 * @return bool True if valid phone number
 */
function isValidPhone($phone) {
    return preg_match('/^(0|255|\+255)[67][0-9]{8}$/', $phone);
}

/**
 * Validate amount
 * 
 * @param mixed $amount The amount to validate
 * @return bool True if valid amount
 */
function isValidAmount($amount) {
    return is_numeric($amount) && $amount >= 0;
}

// ============================================================
// DATABASE HELPER FUNCTIONS
// ============================================================

/**
 * Get employee name by ID
 * 
 * @param int $id Employee ID
 * @return string Employee name or 'Unknown'
 */
function getEmployeeName($id) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT full_name FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ? $result['full_name'] : 'Unknown';
    } catch (Exception $e) {
        return 'Unknown';
    }
}

/**
 * Get employee details by ID
 * 
 * @param int $id Employee ID
 * @return array|false Employee data or false
 */
function getEmployee($id) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get all active employees
 * 
 * @return array Array of active employees
 */
function getActiveEmployees() {
    global $db;
    $stmt = $db->prepare("SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get provider name by code
 * 
 * @param string $code Provider code
 * @return string Provider name or code
 */
function getProviderName($code) {
    global $db;
    $stmt = $db->prepare("SELECT provider_name FROM providers WHERE provider_code = ?");
    $stmt->execute([$code]);
    $result = $stmt->fetch();
    return $result ? $result['provider_name'] : $code;
}

/**
 * Get all active providers
 * 
 * @return array Array of active providers
 */
function getActiveProviders() {
    global $db;
    $stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get provider fields for forms
 * 
 * @return array Provider fields with details
 */
function getProviderFields() {
    $providers = getActiveProviders();
    $fields = [];
    foreach ($providers as $provider) {
        $fields[$provider['provider_code']] = [
            'name' => $provider['provider_name'],
            'code' => $provider['provider_code'],
            'color' => $provider['color_code'] ?? '#0B5ED7',
            'icon' => $provider['icon_class'] ?? 'fas fa-university'
        ];
    }
    return $fields;
}

// ============================================================
// SYSTEM SETTINGS FUNCTIONS
// ============================================================

/**
 * Get system setting value
 * 
 * @param string $key The setting key
 * @return string|null Setting value or null
 */
function getSetting($key) {
    global $db;
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : null;
}

/**
 * Update system setting
 * 
 * @param string $key The setting key
 * @param string $value The setting value
 * @return bool True on success
 */
function updateSetting($key, $value) {
    global $db;
    $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
    return $stmt->execute([$value, $key]);
}

/**
 * Get opening capital
 * 
 * @return float Opening capital amount
 */
function getOpeningCapital() {
    return (float)(getSetting('opening_capital') ?? 0);
}

// ============================================================
// ACTIVITY LOG FUNCTIONS - FIXED
// ============================================================

/**
 * Log activity to database
 * 
 * @param int $employee_id User ID
 * @param string $action Action performed
 * @param string $module Module name
 * @param int|null $record_id Record ID (optional)
 * @param string|null $old_value Old value (optional)
 * @param string|null $new_value New value (optional)
 * @param int|null $branch_id Branch ID (optional)
 * @return bool True on success
 */
function logActivity($employee_id, $action, $module, $record_id = null, $old_value = null, $new_value = null, $branch_id = null) {
    global $db;
    
    // Skip if employee_id is not valid
    if (empty($employee_id) || $employee_id <= 0) {
        return false;
    }
    
    // Check if employee exists and is active
    try {
        $check_stmt = $db->prepare("SELECT id FROM employees WHERE id = ? AND is_active = 1");
        $check_stmt->execute([$employee_id]);
        $employee_exists = $check_stmt->fetch();
        
        if (!$employee_exists) {
            // Employee doesn't exist or is inactive, skip logging
            return false;
        }
    } catch (Exception $e) {
        // If we can't check, skip logging
        return false;
    }
    
    // Check if activity_logs table exists
    try {
        $check_table = $db->query("SHOW TABLES LIKE 'activity_logs'");
        if ($check_table->rowCount() == 0) {
            return false;
        }
    } catch (Exception $e) {
        return false;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
        $stmt = $db->prepare("INSERT INTO activity_logs 
                             (employee_id, action, module, record_id, old_value, new_value, ip_address, user_agent, branch_id) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$employee_id, $action, $module, $record_id, $old_value, $new_value, $ip, $user_agent, $branch_id]);
    } catch (Exception $e) {
        // Silently fail if logging fails
        error_log("Activity log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get recent activities
 * 
 * @param int $limit Number of activities to return
 * @param int|null $employee_id Filter by employee ID (optional)
 * @return array Array of activities
 */
function getRecentActivities($limit = 10, $employee_id = null) {
    global $db;
    $sql = "SELECT al.*, e.full_name as employee 
            FROM activity_logs al 
            JOIN employees e ON al.employee_id = e.id 
            WHERE 1=1";
    $params = [];
    
    if ($employee_id) {
        $sql .= " AND al.employee_id = ?";
        $params[] = $employee_id;
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================================
// CALCULATION FUNCTIONS
// ============================================================

/**
 * Calculate total from array of amounts
 * 
 * @param array $amounts Array of amounts
 * @return float Total
 */
function calculateTotal($amounts) {
    return array_sum(array_map('floatval', $amounts));
}

/**
 * Calculate net profit
 * 
 * @param float $commission Total commission
 * @param float $expenses Total expenses
 * @param float $salaries Total salaries
 * @return float Net profit
 */
function calculateProfit($commission, $expenses, $salaries = 0) {
    return floatval($commission) - floatval($expenses) - floatval($salaries);
}

/**
 * Calculate current capital
 * 
 * @param float $opening Opening capital
 * @param float $additions Additional capital
 * @param float $withdrawals Capital withdrawals
 * @return float Current capital
 */
function calculateCapital($opening, $additions, $withdrawals) {
    return floatval($opening) + floatval($additions) - floatval($withdrawals);
}

/**
 * Calculate float difference
 * 
 * @param float $evening Evening total
 * @param float $morning Morning total
 * @return float Difference
 */
function calculateFloatDifference($evening, $morning) {
    return floatval($evening) - floatval($morning);
}

// ============================================================
// CHECK FUNCTIONS
// ============================================================

/**
 * Check if morning report exists for date and branch
 * 
 * @param int $employee_id Employee ID
 * @param string $date Date (Y-m-d)
 * @param int $branch_id Branch ID
 * @return bool True if exists
 */
function morningReportExists($employee_id, $date, $branch_id = null) {
    global $db;
    $sql = "SELECT id FROM morning_reports WHERE employee_id = ? AND report_date = ?";
    $params = [$employee_id, $date];
    
    if ($branch_id) {
        $sql .= " AND branch_id = ?";
        $params[] = $branch_id;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

/**
 * Check if evening stock exists for date
 * 
 * @param int $employee_id Employee ID
 * @param string $date Date (Y-m-d)
 * @return bool True if exists
 */
function eveningStockExists($employee_id, $date) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM evening_stocks WHERE employee_id = ? AND stock_date = ?");
    $stmt->execute([$employee_id, $date]);
    return $stmt->rowCount() > 0;
}

/**
 * Check if daily report exists for date
 * 
 * @param int $employee_id Employee ID
 * @param string $date Date (Y-m-d)
 * @return bool True if exists
 */
function dailyReportExists($employee_id, $date) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM daily_reports WHERE employee_id = ? AND report_date = ?");
    $stmt->execute([$employee_id, $date]);
    return $stmt->rowCount() > 0;
}

/**
 * Check if salary exists for employee and month
 * 
 * @param int $employee_id Employee ID
 * @param string $month Month (Y-m-01)
 * @return bool True if exists
 */
function salaryExists($employee_id, $month) {
    global $db;
    $stmt = $db->prepare("SELECT id FROM employee_salaries WHERE employee_id = ? AND salary_month = ?");
    $stmt->execute([$employee_id, $month]);
    return $stmt->rowCount() > 0;
}

// ============================================================
// DATE HELPER FUNCTIONS
// ============================================================

/**
 * Get today's date
 * 
 * @return string Today's date (Y-m-d)
 */
function today() {
    return date('Y-m-d');
}

/**
 * Get current month start
 * 
 * @return string Current month start (Y-m-01)
 */
function currentMonth() {
    return date('Y-m-01');
}

/**
 * Get current month name
 * 
 * @return string Current month name
 */
function currentMonthName() {
    return date('F Y');
}

/**
 * Get current year
 * 
 * @return int Current year
 */
function currentYear() {
    return (int)date('Y');
}

/**
 * Get previous month
 * 
 * @return string Previous month (Y-m-01)
 */
function previousMonth() {
    return date('Y-m-01', strtotime('-1 month'));
}

/**
 * Get next month
 * 
 * @return string Next month (Y-m-01)
 */
function nextMonth() {
    return date('Y-m-01', strtotime('+1 month'));
}

/**
 * Get month range for dropdown
 * 
 * @param int $months Number of months back
 * @return array Array of months
 */
function getMonthRange($months = 12) {
    $months_list = [];
    for ($i = 0; $i < $months; $i++) {
        $date = date('Y-m-01', strtotime("-$i months"));
        $months_list[] = [
            'value' => $date,
            'label' => date('F Y', strtotime($date))
        ];
    }
    return $months_list;
}

// ============================================================
// FILE HANDLING FUNCTIONS
// ============================================================

/**
 * Upload file
 * 
 * @param array $file $_FILES array
 * @param string $target_dir Target directory
 * @param array $allowed_types Allowed file types
 * @param int $max_size Max file size in bytes
 * @return string|false File path on success, false on failure
 */
function uploadFile($file, $target_dir, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'], $max_size = 5242880) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_types)) {
        return false;
    }
    
    if ($file['size'] > $max_size) {
        return false;
    }
    
    $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $target_path = $target_dir . $filename;
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return $target_path;
    }
    
    return false;
}

// ============================================================
// RESPONSE FUNCTIONS
// ============================================================

/**
 * Send JSON response
 * 
 * @param array $data Response data
 * @param int $status HTTP status code
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Send success JSON response
 * 
 * @param string $message Success message
 * @param array $data Additional data
 */
function jsonSuccess($message = 'Success', $data = []) {
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

/**
 * Send error JSON response
 * 
 * @param string $message Error message
 * @param array $data Additional data
 */
function jsonError($message = 'Error', $data = []) {
    jsonResponse(array_merge(['success' => false, 'message' => $message], $data), 400);
}

/**
 * Send unauthorized JSON response
 */
function jsonUnauthorized($message = 'Unauthorized') {
    jsonResponse(['success' => false, 'message' => $message], 401);
}

/**
 * Send forbidden JSON response
 */
function jsonForbidden($message = 'Forbidden') {
    jsonResponse(['success' => false, 'message' => $message], 403);
}

/**
 * Send not found JSON response
 */
function jsonNotFound($message = 'Not Found') {
    jsonResponse(['success' => false, 'message' => $message], 404);
}

// ============================================================
// DEBUG FUNCTIONS
// ============================================================

/**
 * Debug function - print variable and die
 * 
 * @param mixed $data Data to debug
 */
function dd($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
    die();
}

/**
 * Debug function - print variable
 * 
 * @param mixed $data Data to debug
 */
function dump($data) {
    echo '<pre>';
    var_dump($data);
    echo '</pre>';
}

/**
 * Log message to error log
 * 
 * @param string $message Message to log
 * @param string $level Log level (info, warning, error)
 */
function logMessage($message, $level = 'info') {
    $log_file = ROOT_PATH . '/logs/activities.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    if (!is_dir(dirname($log_file))) {
        mkdir(dirname($log_file), 0777, true);
    }
    
    error_log($entry, 3, $log_file);
}
?>