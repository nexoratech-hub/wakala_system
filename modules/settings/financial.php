<?php
// ================================================================
// FILE: modules/settings/financial.php
// FINANCIAL SETTINGS - RED THEME
// FIXED: Duplicate entry 'currency' error
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
// GET CURRENT SETTINGS
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_group IN ('financial', 'capital', 'salary', 'general')
    ");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Error loading settings: " . $e->getMessage());
    $settings = [];
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $currency = trim($_POST['currency'] ?? 'TSh');
        $opening_capital = floatval($_POST['opening_capital'] ?? 0);
        $tax_rate = floatval($_POST['tax_rate'] ?? 0);
        $salary_month = $_POST['salary_month'] ?? date('Y-m-01');
        
        // Validation
        if ($opening_capital < 0) {
            throw new Exception('Opening capital cannot be negative.');
        }
        if ($tax_rate < 0 || $tax_rate > 100) {
            throw new Exception('Tax rate must be between 0 and 100.');
        }
        
        // Settings na group zao sahihi
        $settings_data = [
            ['key' => 'currency',        'value' => $currency,        'group' => 'general'],
            ['key' => 'opening_capital', 'value' => $opening_capital, 'group' => 'capital'],
            ['key' => 'tax_rate',        'value' => $tax_rate,        'group' => 'salary'],
            ['key' => 'salary_month',    'value' => $salary_month,    'group' => 'salary'],
        ];
        
        // ✅ FIX: Use INSERT ... ON DUPLICATE KEY UPDATE
        // This prevents duplicate key error even if the row exists
        $stmt = $db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_group) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value),
                setting_group = VALUES(setting_group),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        foreach ($settings_data as $item) {
            $stmt->execute([$item['key'], $item['value'], $item['group']]);
        }
        
        logActivity($_SESSION['user_id'], 'Update Financial Settings', 'Settings', null, null, json_encode([
            'currency' => $currency,
            'opening_capital' => $opening_capital,
            'tax_rate' => $tax_rate,
            'salary_month' => $salary_month
        ]));
        
        $success = 'Financial settings updated successfully!';
        
        // Reload settings
        $stmt = $db->prepare("
            SELECT setting_key, setting_value 
            FROM system_settings 
            WHERE setting_group IN ('financial', 'capital', 'salary', 'general')
        ");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Error updating financial settings: " . $e->getMessage());
    }
}

// ============================================================
// GET FINANCIAL SUMMARY
// ============================================================
$financial_data = [
    'current_capital' => 0,
    'total_in' => 0,
    'total_out' => 0,
    'month_expenses' => 0,
    'total_salaries_paid' => 0,
    'total_transactions' => 0,
];

try {
    // Capital In (opening, additional, profit_allocation)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM capital_management 
        WHERE transaction_type IN ('opening', 'additional', 'profit_allocation')
    ");
    $stmt->execute();
    $financial_data['total_in'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // Capital Out (cash_out, adjustment)
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM capital_management 
        WHERE transaction_type IN ('cash_out', 'adjustment')
    ");
    $stmt->execute();
    $financial_data['total_out'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // Current Capital
    $financial_data['current_capital'] = $financial_data['total_in'] - $financial_data['total_out'];
    
    // Month Expenses
    $month_start = date('Y-m-01');
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM expenses 
        WHERE expense_date >= ? AND is_business_expense = 1
    ");
    $stmt->execute([$month_start]);
    $financial_data['month_expenses'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // Total Salaries Paid
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(net_pay), 0) as total 
        FROM employee_salaries 
        WHERE status = 'paid'
    ");
    $stmt->execute();
    $financial_data['total_salaries_paid'] = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    // Total Transactions
    $stmt = $db->prepare("SELECT COUNT(*) FROM transactions");
    $stmt->execute();
    $financial_data['total_transactions'] = intval($stmt->fetchColumn());
    
} catch (PDOException $e) {
    error_log("Error getting financial data: " . $e->getMessage());
}

// ============================================================
// CURRENCIES
// ============================================================
$currencies = [
    'TSh' => 'Tanzanian Shilling (TSh)',
    'USD' => 'US Dollar ($)',
    'EUR' => 'Euro (€)',
    'GBP' => 'British Pound (£)',
    'KES' => 'Kenyan Shilling (KES)',
    'UGX' => 'Ugandan Shilling (UGX)',
    'ZAR' => 'South African Rand (R)',
    'NGN' => 'Nigerian Naira (₦)'
];

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
                <h2><i class="fas fa-coins" style="color:#bb0404;"></i> Financial Settings</h2>
                <p class="text-muted">Configure currency, capital, tax rates, and salary settings</p>
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
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Current Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($financial_data['current_capital']); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-green">
                <div class="summary-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Capital In</span>
                    <span class="summary-value"><?php echo formatCurrency($financial_data['total_in']); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-red">
                <div class="summary-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">Total Capital Out</span>
                    <span class="summary-value"><?php echo formatCurrency($financial_data['total_out']); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-orange">
                <div class="summary-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="summary-info">
                    <span class="summary-label">This Month Expenses</span>
                    <span class="summary-value"><?php echo formatCurrency($financial_data['month_expenses']); ?></span>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <div class="form-card">
            <form method="POST" action="" class="settings-form" id="settingsForm">
                
                <!-- Currency Settings -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon" style="background:#DBEAFE; color:#1E40AF; border-color:#93C5FD;">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="section-title-wrapper">
                            <h4>Currency Settings</h4>
                            <p>Default currency for all transactions</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Default Currency <span class="required">*</span></label>
                            <select name="currency" class="form-control" required>
                                <?php foreach ($currencies as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                        <?php echo ($settings['currency'] ?? 'TSh') == $value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text">Currency used across the system</small>
                        </div>
                    </div>
                </div>

                <!-- Capital Settings -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon" style="background:#D1FAE5; color:#047857; border-color:#6EE7B7;">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="section-title-wrapper">
                            <h4>Capital Settings</h4>
                            <p>Initial capital configuration</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opening Capital <span class="required">*</span></label>
                            <input type="number" 
                                   name="opening_capital" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($settings['opening_capital'] ?? 0); ?>" 
                                   step="0.01" 
                                   min="0" 
                                   required>
                            <small class="form-text">Initial capital when starting the business</small>
                        </div>
                    </div>
                </div>

                <!-- Tax & Salary Settings -->
                <div class="form-section">
                    <div class="section-header">
                        <div class="section-icon" style="background:#FEF3C7; color:#B45309; border-color:#FCD34D;">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="section-title-wrapper">
                            <h4>Tax & Salary Settings</h4>
                            <p>Tax rates and salary configuration</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tax Rate (%)</label>
                            <input type="number" 
                                   name="tax_rate" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($settings['tax_rate'] ?? 0); ?>" 
                                   step="0.01" 
                                   min="0" 
                                   max="100">
                            <small class="form-text">Default tax rate for salaries (e.g., 10 for 10%)</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Current Salary Month</label>
                            <input type="date" 
                                   name="salary_month" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($settings['salary_month'] ?? date('Y-m-01')); ?>">
                            <small class="form-text">First day of the current salary month</small>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                    <a href="financial.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Financial Overview -->
        <div class="info-card">
            <div class="info-card-header">
                <div class="info-card-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="info-card-title">
                    <h4>Financial Overview</h4>
                    <p>Current financial status of the system</p>
                </div>
            </div>
            
            <div class="financial-grid">
                <div class="financial-item financial-item-blue">
                    <div class="financial-item-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="financial-item-info">
                        <span class="financial-item-label">Current Capital</span>
                        <span class="financial-item-value"><?php echo formatCurrency($financial_data['current_capital']); ?></span>
                    </div>
                </div>
                
                <div class="financial-item financial-item-green">
                    <div class="financial-item-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="financial-item-info">
                        <span class="financial-item-label">Total Capital In</span>
                        <span class="financial-item-value"><?php echo formatCurrency($financial_data['total_in']); ?></span>
                    </div>
                </div>
                
                <div class="financial-item financial-item-red">
                    <div class="financial-item-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="financial-item-info">
                        <span class="financial-item-label">Total Capital Out</span>
                        <span class="financial-item-value"><?php echo formatCurrency($financial_data['total_out']); ?></span>
                    </div>
                </div>
                
                <div class="financial-item financial-item-orange">
                    <div class="financial-item-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="financial-item-info">
                        <span class="financial-item-label">This Month Expenses</span>
                        <span class="financial-item-value"><?php echo formatCurrency($financial_data['month_expenses']); ?></span>
                    </div>
                </div>
                
                <div class="financial-item financial-item-purple">
                    <div class="financial-item-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="financial-item-info">
                        <span class="financial-item-label">Total Salaries Paid</span>
                        <span class="financial-item-value"><?php echo formatCurrency($financial_data['total_salaries_paid']); ?></span>
                    </div>
                </div>
                
                <div class="financial-item financial-item-cyan">
                    <div class="financial-item-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="financial-item-info">
                        <span class="financial-item-label">Total Transactions</span>
                        <span class="financial-item-value"><?php echo number_format($financial_data['total_transactions']); ?></span>
                    </div>
                </div>
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

.summary-red {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.summary-red .summary-icon {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.summary-red .summary-value { color: #B91C1C; }

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

html.dark-mode .summary-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .summary-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-red { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .summary-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .summary-blue .summary-value { color: #60A5FA; }
html.dark-mode .summary-green .summary-value { color: #6EE7B7; }
html.dark-mode .summary-red .summary-value { color: #FCA5A5; }
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
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-word;
}

/* FORM CARD */
.form-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 0;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
}

.form-section:last-of-type {
    border-bottom: none;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px dashed var(--border-color);
}

.section-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 1.5px solid;
}

.section-title-wrapper h4 {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.section-title-wrapper p {
    font-size: 12px;
    color: var(--text-muted);
    margin: 2px 0 0 0;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-row:last-child {
    margin-bottom: 0;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-secondary);
}

.form-group label .required { color: #DC2626; }

.form-control {
    padding: 10px 14px;
    border: 1.5px solid var(--border-color);
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
    background: var(--bg-card);
}

.form-text {
    font-size: 11px;
    color: var(--text-muted);
    font-style: italic;
}

.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    background: var(--bg-table-even);
    border-top: 1px solid var(--border-color);
}

.btn {
    padding: 10px 22px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}

.btn-primary { 
    background: linear-gradient(135deg, #bb0404, #8a0303); 
    color: white; 
    box-shadow: 0 4px 12px rgba(187,4,4,0.3);
}
.btn-primary:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 6px 20px rgba(187,4,4,0.5); 
    color: white;
}
.btn-primary:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover { 
    background: var(--bg-table-hover); 
    color: var(--text-primary);
    transform: translateY(-2px);
}

/* INFO CARD */
.info-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 0;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.info-card-header {
    padding: 18px 24px;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    overflow: hidden;
}

.info-card-header::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.08);
    border-radius: 50%;
    pointer-events: none;
}

.info-card-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    border: 1.5px solid rgba(255,255,255,0.3);
    position: relative;
    z-index: 1;
    flex-shrink: 0;
}

.info-card-title {
    position: relative;
    z-index: 1;
}

.info-card-title h4 {
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    color: #FFFFFF;
}

.info-card-title p {
    font-size: 12px;
    color: rgba(255,255,255,0.85);
    margin: 2px 0 0 0;
}

/* FINANCIAL GRID */
.financial-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 20px 24px;
}

.financial-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border-radius: 10px;
    border: 1.5px solid;
    transition: all 0.3s ease;
    min-width: 0;
}

.financial-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.financial-item-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.financial-item-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}

.financial-item-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--text-muted);
}

.financial-item-value {
    font-size: 15px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    color: var(--text-primary);
    letter-spacing: -0.3px;
    word-break: break-word;
}

.financial-item-blue { background: rgba(37, 99, 235, 0.06); border-color: rgba(37, 99, 235, 0.2); }
.financial-item-blue .financial-item-icon { background: rgba(37, 99, 235, 0.15); color: #2563EB; }
.financial-item-blue .financial-item-value { color: #1D4ED8; }

.financial-item-green { background: rgba(5, 150, 105, 0.06); border-color: rgba(5, 150, 105, 0.2); }
.financial-item-green .financial-item-icon { background: rgba(5, 150, 105, 0.15); color: #059669; }
.financial-item-green .financial-item-value { color: #047857; }

.financial-item-red { background: rgba(220, 38, 38, 0.06); border-color: rgba(220, 38, 38, 0.2); }
.financial-item-red .financial-item-icon { background: rgba(220, 38, 38, 0.15); color: #DC2626; }
.financial-item-red .financial-item-value { color: #B91C1C; }

.financial-item-orange { background: rgba(217, 119, 6, 0.06); border-color: rgba(217, 119, 6, 0.2); }
.financial-item-orange .financial-item-icon { background: rgba(217, 119, 6, 0.15); color: #D97706; }
.financial-item-orange .financial-item-value { color: #B45309; }

.financial-item-purple { background: rgba(124, 58, 237, 0.06); border-color: rgba(124, 58, 237, 0.2); }
.financial-item-purple .financial-item-icon { background: rgba(124, 58, 237, 0.15); color: #7C3AED; }
.financial-item-purple .financial-item-value { color: #6D28D9; }

.financial-item-cyan { background: rgba(8, 145, 178, 0.06); border-color: rgba(8, 145, 178, 0.2); }
.financial-item-cyan .financial-item-icon { background: rgba(8, 145, 178, 0.15); color: #0891b2; }
.financial-item-cyan .financial-item-value { color: #0E7490; }

html.dark-mode .financial-item-blue { background: rgba(37, 99, 235, 0.12); }
html.dark-mode .financial-item-green { background: rgba(5, 150, 105, 0.12); }
html.dark-mode .financial-item-red { background: rgba(220, 38, 38, 0.12); }
html.dark-mode .financial-item-orange { background: rgba(217, 119, 6, 0.12); }
html.dark-mode .financial-item-purple { background: rgba(124, 58, 237, 0.12); }
html.dark-mode .financial-item-cyan { background: rgba(8, 145, 178, 0.12); }

html.dark-mode .financial-item-blue .financial-item-value { color: #60A5FA; }
html.dark-mode .financial-item-green .financial-item-value { color: #6EE7B7; }
html.dark-mode .financial-item-red .financial-item-value { color: #FCA5A5; }
html.dark-mode .financial-item-orange .financial-item-value { color: #FBBF24; }
html.dark-mode .financial-item-purple .financial-item-value { color: #C4B5FD; }
html.dark-mode .financial-item-cyan .financial-item-value { color: #67E8F9; }

/* ALERTS */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 500;
}

.alert-success { background: #D1FAE5; color: #065F46; border: 1.5px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1.5px solid #FECACA; }

html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

/* DARK MODE TOGGLE */
.dark-mode-toggle {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.dark-mode-btn {
    background: var(--bg-card);
    color: var(--text-primary);
    border: 1.5px solid var(--border-color);
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
    box-shadow: 0 4px 12px var(--shadow-color);
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, 1fr); }
    .financial-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .summary-cards { grid-template-columns: 1fr; }
    .financial-grid { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .section-header { flex-direction: column; align-items: flex-start; }
}

@media (max-width: 480px) {
    .summary-value { font-size: 16px; }
    .summary-icon { width: 42px; height: 42px; font-size: 18px; }
    .financial-item-value { font-size: 13px; }
    .financial-item-icon { width: 40px; height: 40px; font-size: 16px; }
}
</style>

<script>
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
    
    // Form validation
    const form = document.getElementById('settingsForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const openingCapital = parseFloat(document.querySelector('input[name="opening_capital"]').value) || 0;
            const taxRate = parseFloat(document.querySelector('input[name="tax_rate"]').value) || 0;
            
            if (openingCapital < 0) {
                e.preventDefault();
                alert('Opening capital cannot be negative.');
                return false;
            }
            
            if (taxRate < 0 || taxRate > 100) {
                e.preventDefault();
                alert('Tax rate must be between 0 and 100.');
                return false;
            }
            
            // Disable submit button to prevent double submission
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
            
            return true;
        });
    }
    
    console.log('%c 💰 Financial Settings Loaded - FIXED', 
        'background:#bb0404; color:white; padding:4px 12px; border-radius:4px; font-size:12px;');
});
</script>

</body>
</html>