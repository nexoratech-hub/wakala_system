<?php
// ================================================================
// FILE: modules/settings/financial.php
// FINANCIAL SETTINGS
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
    // Get current settings
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'financial' OR setting_group = 'capital' OR setting_group = 'salary'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Error loading settings: " . $e->getMessage());
    $settings = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $currency = $_POST['currency'] ?? 'TSh';
        $opening_capital = floatval($_POST['opening_capital'] ?? 0);
        $tax_rate = floatval($_POST['tax_rate'] ?? 0);
        $salary_month = $_POST['salary_month'] ?? date('Y-m-01');
        
        $settings_data = [
            'currency' => $currency,
            'opening_capital' => $opening_capital,
            'tax_rate' => $tax_rate,
            'salary_month' => $salary_month
        ];
        
        foreach ($settings_data as $key => $value) {
            $group = 'financial';
            if ($key === 'opening_capital') $group = 'capital';
            if ($key === 'tax_rate' || $key === 'salary_month') $group = 'salary';
            
            $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ? AND setting_group = ?");
            $stmt->execute([$value, $key, $group]);
        }
        
        logActivity($_SESSION['user_id'], 'Update Financial Settings', 'Settings', null, null, json_encode($settings_data));
        $success = 'Financial settings updated successfully!';
        
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        error_log("Error updating financial settings: " . $e->getMessage());
    }
}

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

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-coins" style="color:#bb0404;"></i> Financial Settings</h2>
                <p class="text-muted">Configure currency, capital, and tax settings</p>
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

        <div class="form-card">
            <form method="POST" action="" class="settings-form">
                <!-- Currency Settings -->
                <div class="form-section">
                    <h4><i class="fas fa-money-bill-wave" style="color:#bb0404;"></i> Currency Settings</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Default Currency <span class="required">*</span></label>
                            <select name="currency" class="form-control" required>
                                <?php foreach ($currencies as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($settings['currency'] ?? 'TSh') == $value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Capital Settings -->
                <div class="form-section">
                    <h4><i class="fas fa-building" style="color:#bb0404;"></i> Capital Settings</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opening Capital <span class="required">*</span></label>
                            <input type="number" name="opening_capital" class="form-control" value="<?php echo htmlspecialchars($settings['opening_capital'] ?? 0); ?>" step="0.01" min="0" required>
                            <small class="form-text">Initial capital when starting the business</small>
                        </div>
                    </div>
                </div>

                <!-- Tax & Salary Settings -->
                <div class="form-section">
                    <h4><i class="fas fa-file-invoice-dollar" style="color:#bb0404;"></i> Tax & Salary Settings</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tax Rate (%)</label>
                            <input type="number" name="tax_rate" class="form-control" value="<?php echo htmlspecialchars($settings['tax_rate'] ?? 0); ?>" step="0.01" min="0" max="100">
                            <small class="form-text">Tax rate applied to salaries (e.g., 10 for 10%)</small>
                        </div>
                        <div class="form-group">
                            <label>Current Salary Month</label>
                            <input type="date" name="salary_month" class="form-control" value="<?php echo htmlspecialchars($settings['salary_month'] ?? date('Y-m-01')); ?>">
                            <small class="form-text">First day of the current salary month</small>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="form-section">
                    <h4><i class="fas fa-chart-pie" style="color:#bb0404;"></i> Financial Summary</h4>
                    <?php
                    try {
                        // Get total capital
                        $stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management WHERE transaction_type IN ('opening', 'additional', 'profit_allocation')");
                        $stmt->execute();
                        $total_in = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                        
                        $stmt = $db->prepare("SELECT SUM(amount) as total FROM capital_management WHERE transaction_type IN ('cash_out', 'adjustment')");
                        $stmt->execute();
                        $total_out = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                        
                        $current_capital = $total_in - $total_out;
                        
                        // Get total expenses this month
                        $month_start = date('Y-m-01');
                        $stmt = $db->prepare("SELECT SUM(amount) as total FROM expenses WHERE expense_date >= ? AND is_business_expense = 1");
                        $stmt->execute([$month_start]);
                        $month_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                        
                    } catch (PDOException $e) {
                        $current_capital = 0;
                        $month_expenses = 0;
                    }
                    ?>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <span class="label">Current Capital</span>
                            <span class="value"><?php echo formatCurrency($current_capital); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="label">Total Capital In</span>
                            <span class="value positive"><?php echo formatCurrency($total_in); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="label">Total Capital Out</span>
                            <span class="value negative"><?php echo formatCurrency($total_out); ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="label">This Month Expenses</span>
                            <span class="value negative"><?php echo formatCurrency($month_expenses); ?></span>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                </div>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* Reuse styles from general.php */
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
    max-width: 800px;
}

.form-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.form-section h4 i {
    margin-right: 8px;
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

.form-text {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
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

/* Summary Grid */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.summary-item {
    background: var(--bg-table-even);
    padding: 12px 16px;
    border-radius: 8px;
    text-align: center;
}

.summary-item .label {
    font-size: 11px;
    color: var(--text-muted);
    display: block;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.summary-item .value {
    font-size: 16px;
    font-weight: 700;
    color: var(--text-primary);
    display: block;
    margin-top: 2px;
}

.summary-item .value.positive { color: #10B981; }
.summary-item .value.negative { color: #DC2626; }

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
    .summary-grid {
        grid-template-columns: 1fr 1fr;
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