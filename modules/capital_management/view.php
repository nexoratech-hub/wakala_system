<?php
// ================================================================
// FILE: modules/capital_management/view.php
// VIEW CAPITAL TRANSACTION DETAILS
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
    $stmt = $db->prepare("SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
            WHERE cm.id = ?");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transaction: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$transaction) {
    header('Location: index.php');
    exit();
}

$type_labels = [
    'opening' => ['label' => 'Opening Capital', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional Capital', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Capital Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

$type_info = $type_labels[$transaction['transaction_type']] ?? ['label' => $transaction['transaction_type'], 'icon' => 'fa-circle', 'color' => 'gray'];

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
                <h2><i class="fas fa-building" style="color:#bb0404;"></i> Capital Transaction Details</h2>
                <p class="text-muted">View capital transaction information</p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="view-card">
            <div class="view-header">
                <div class="view-number">
                    <span class="label">Transaction Number</span>
                    <span class="value"><?php echo htmlspecialchars($transaction['capital_number']); ?></span>
                </div>
                <div class="view-status">
                    <span class="type-badge type-<?php echo $type_info['color']; ?>">
                        <i class="fas <?php echo $type_info['icon']; ?>"></i>
                        <?php echo $type_info['label']; ?>
                    </span>
                </div>
            </div>

            <div class="view-grid">
                <div class="view-item">
                    <span class="label">Date</span>
                    <span class="value"><?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Amount</span>
                    <span class="value <?php echo in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? 'text-danger' : 'text-success'; ?>">
                        <?php echo in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? '-' : '+'; ?>
                        <?php echo formatCurrency($transaction['amount']); ?>
                    </span>
                </div>
                <div class="view-item">
                    <span class="label">Branch</span>
                    <span class="value"><?php echo htmlspecialchars($transaction['branch_name'] ?? 'Main'); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Employee</span>
                    <span class="value"><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="view-item full">
                    <span class="label">Description</span>
                    <span class="value"><?php echo nl2br(htmlspecialchars($transaction['description'] ?? 'N/A')); ?></span>
                </div>
                <?php if ($transaction['notes']): ?>
                <div class="view-item full">
                    <span class="label">Notes</span>
                    <span class="value"><?php echo nl2br(htmlspecialchars($transaction['notes'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($transaction['reference_id']): ?>
                <div class="view-item">
                    <span class="label">Reference Module</span>
                    <span class="value"><?php echo htmlspecialchars($transaction['reference_module'] ?? 'N/A'); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Reference ID</span>
                    <span class="value"><?php echo htmlspecialchars($transaction['reference_id']); ?></span>
                </div>
                <?php endif; ?>
                <div class="view-item">
                    <span class="label">Created At</span>
                    <span class="value"><?php echo date('d M Y H:i', strtotime($transaction['created_at'])); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Last Updated</span>
                    <span class="value"><?php echo date('d M Y H:i', strtotime($transaction['updated_at'])); ?></span>
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
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.view-number .label {
    font-size: 12px;
    color: var(--text-muted);
    display: block;
}

.view-number .value {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
}

.type-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.type-blue { background: #DBEAFE; color: #1D4ED8; }
.type-green { background: #D1FAE5; color: #065F46; }
.type-purple { background: #EDE9FE; color: #6D28D9; }
.type-red { background: #FEE2E2; color: #991B1B; }
.type-orange { background: #FEF3C7; color: #92400E; }
.type-gray { background: #F3F4F6; color: #6B7280; }

.view-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
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

.btn-warning {
    background: #F59E0B;
    color: #1F2937;
}
.btn-warning:hover { background: #D97706; color: white; }

@media (max-width: 768px) {
    .view-grid {
        grid-template-columns: 1fr;
    }
    .view-item.full {
        grid-column: span 1;
    }
    .view-header {
        flex-direction: column;
        text-align: center;
        gap: 12px;
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