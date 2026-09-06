<?php
// ================================================================
// FILE: modules/daily_report/view.php
// VIEW DAILY REPORT DETAILS
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    $stmt = $db->prepare("SELECT dr.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            p.provider_name,
            p.provider_code,
            mr.report_number as morning_report_number,
            es.stock_number as evening_stock_number,
            c.commission_number as commission_number
            FROM daily_reports dr
            LEFT JOIN employees e ON dr.employee_id = e.id
            LEFT JOIN branches b ON dr.branch_id = b.id
            LEFT JOIN providers p ON dr.provider_id = p.id
            LEFT JOIN morning_reports mr ON dr.morning_report_id = mr.id
            LEFT JOIN evening_stocks es ON dr.evening_stock_id = es.id
            LEFT JOIN commissions c ON dr.commission_id = c.id
            WHERE dr.id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching report: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$report) {
    header('Location: index.php');
    exit();
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
                <h2><i class="fas fa-file-alt" style="color:#bb0404;"></i> Daily Report Details</h2>
                <p class="text-muted">View daily report information</p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="view-card">
            <div class="view-header">
                <div class="view-number">
                    <span class="label">Report Number</span>
                    <span class="value"><?php echo htmlspecialchars($report['report_number']); ?></span>
                </div>
                <div class="view-date">
                    <span class="label">Report Date</span>
                    <span class="value"><?php echo date('d M Y', strtotime($report['report_date'])); ?></span>
                </div>
                <div class="view-branch">
                    <span class="label">Branch</span>
                    <span class="value"><?php echo htmlspecialchars($report['branch_name'] ?? 'Main'); ?></span>
                </div>
            </div>

            <!-- References -->
            <div class="view-section">
                <h4><i class="fas fa-link"></i> References</h4>
                <div class="view-grid">
                    <div class="view-item">
                        <span class="label">Provider</span>
                        <span class="value"><?php echo htmlspecialchars($report['provider_name'] ?? $report['provider_code'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Morning Report</span>
                        <span class="value"><?php echo htmlspecialchars($report['morning_report_number'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Evening Stock</span>
                        <span class="value"><?php echo htmlspecialchars($report['evening_stock_number'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Commission</span>
                        <span class="value"><?php echo htmlspecialchars($report['commission_number'] ?? 'N/A'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Stock Information -->
            <div class="view-section">
                <h4><i class="fas fa-cubes"></i> Stock Information</h4>
                <div class="view-grid">
                    <div class="view-item">
                        <span class="label">Morning Total</span>
                        <span class="value"><?php echo formatCurrency($report['morning_total']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Evening Total</span>
                        <span class="value"><?php echo formatCurrency($report['evening_total']); ?></span>
                    </div>
                    <div class="view-item full">
                        <span class="label">Float Difference</span>
                        <span class="value <?php echo $report['float_difference'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($report['float_difference']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Financial Information -->
            <div class="view-section">
                <h4><i class="fas fa-coins"></i> Financial Information</h4>
                <div class="view-grid">
                    <div class="view-item">
                        <span class="label">Total Deposits</span>
                        <span class="value text-success"><?php echo formatCurrency($report['total_deposits']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Total Withdrawals</span>
                        <span class="value text-danger"><?php echo formatCurrency($report['total_withdrawals']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Total Commission</span>
                        <span class="value"><?php echo formatCurrency($report['total_commission']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Other Income</span>
                        <span class="value text-success"><?php echo formatCurrency($report['other_income']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Total Business Income</span>
                        <span class="value text-success"><?php echo formatCurrency($report['total_business_income']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Total Expenses</span>
                        <span class="value text-danger"><?php echo formatCurrency($report['total_expenses']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Total Cash Out</span>
                        <span class="value text-danger"><?php echo formatCurrency($report['total_cash_out']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Total Salaries</span>
                        <span class="value text-danger"><?php echo formatCurrency($report['total_salaries']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Net Profit</span>
                        <span class="value <?php echo $report['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($report['net_profit']); ?>
                        </span>
                    </div>
                    <div class="view-item">
                        <span class="label">Net Profit After Salaries</span>
                        <span class="value <?php echo $report['net_profit_after_salaries'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($report['net_profit_after_salaries']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Capital Information -->
            <div class="view-section">
                <h4><i class="fas fa-building"></i> Capital Information</h4>
                <div class="view-grid">
                    <div class="view-item">
                        <span class="label">Opening Capital</span>
                        <span class="value"><?php echo formatCurrency($report['opening_capital']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Additional Capital</span>
                        <span class="value text-success"><?php echo formatCurrency($report['additional_capital']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Profit Allocated</span>
                        <span class="value text-success"><?php echo formatCurrency($report['profit_allocated']); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Current Capital</span>
                        <span class="value font-bold" style="color:#bb0404;"><?php echo formatCurrency($report['current_capital']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <?php if ($report['notes']): ?>
            <div class="view-section">
                <h4><i class="fas fa-sticky-note"></i> Notes</h4>
                <div class="view-item">
                    <span class="value"><?php echo nl2br(htmlspecialchars($report['notes'])); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Metadata -->
            <div class="view-section">
                <h4><i class="fas fa-info-circle"></i> Metadata</h4>
                <div class="view-grid">
                    <div class="view-item">
                        <span class="label">Created By</span>
                        <span class="value"><?php echo htmlspecialchars($report['employee_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Created At</span>
                        <span class="value"><?php echo date('d M Y H:i', strtotime($report['created_at'])); ?></span>
                    </div>
                    <div class="view-item">
                        <span class="label">Last Updated</span>
                        <span class="value"><?php echo date('d M Y H:i', strtotime($report['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
.view-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
}

.view-header {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.view-header .label {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
}

.view-header .value {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
}

.view-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.view-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.view-section h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 16px 0;
}

.view-section h4 i {
    color: #bb0404;
    margin-right: 8px;
}

.view-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 24px;
}

.view-item.full {
    grid-column: span 2;
}

.view-item .label {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
    margin-bottom: 2px;
}

.view-item .value {
    font-size: 15px;
    font-weight: 500;
    color: var(--text-primary);
}

.text-success { color: #10B981; font-weight: 600; }
.text-danger { color: #DC2626; font-weight: 600; }
.font-bold { font-weight: 700; }

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

.btn-warning {
    background: #F59E0B;
    color: #1F2937;
}
.btn-warning:hover { background: #D97706; color: white; }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

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
    .view-header {
        grid-template-columns: 1fr;
        text-align: center;
    }
    .view-grid {
        grid-template-columns: 1fr;
    }
    .view-item.full {
        grid-column: span 1;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
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