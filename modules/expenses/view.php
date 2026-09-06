<?php
// ================================================================
// FILE: modules/expenses/view.php
// VIEW EXPENSE DETAILS
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    header('Location: index.php');
    exit();
}

try {
    $sql = "SELECT e.*, 
            emp.full_name as employee_name,
            b.branch_name
            FROM expenses e
            LEFT JOIN employees emp ON e.employee_id = emp.id
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE e.id = ?";
    
    // Employees can only view their own expenses
    if ($role === 'employee') {
        $sql .= " AND e.employee_id = ?";
        $params = [$id, $user_id];
    } else {
        $params = [$id];
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching expense: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$expense) {
    header('Location: index.php');
    exit();
}

// Check if expense is salary related and get salary info
$salary_info = null;
if ($expense['is_salary_related'] && $expense['salary_reference']) {
    try {
        $stmt = $db->prepare("SELECT * FROM employee_salaries WHERE id = ?");
        $stmt->execute([$expense['salary_reference']]);
        $salary_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching salary info: " . $e->getMessage());
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
                <h2><i class="fas fa-money-bill-wave" style="color:#bb0404;"></i> Expense Details</h2>
                <p class="text-muted">View expense information</p>
            </div>
            <div class="header-right">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="view-card">
            <div class="view-header">
                <div class="view-number">
                    <span class="label">Expense Number</span>
                    <span class="value"><?php echo htmlspecialchars($expense['expense_number']); ?></span>
                </div>
                <div class="view-status">
                    <span class="type-badge <?php echo $expense['is_business_expense'] ? 'business' : 'personal'; ?>">
                        <i class="fas <?php echo $expense['is_business_expense'] ? 'fa-briefcase' : 'fa-user'; ?>"></i>
                        <?php echo $expense['is_business_expense'] ? 'Business' : 'Personal'; ?>
                    </span>
                    <?php if ($expense['is_salary_related']): ?>
                        <span class="type-badge salary" style="background:#EDE9FE;color:#6D28D9;">
                            <i class="fas fa-user-tie"></i> Salary Related
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="view-grid">
                <div class="view-item">
                    <span class="label">Date</span>
                    <span class="value"><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Category</span>
                    <span class="value"><span class="category-badge"><?php echo htmlspecialchars($expense['category']); ?></span></span>
                </div>
                <div class="view-item">
                    <span class="label">Amount</span>
                    <span class="value text-danger font-bold"><?php echo formatCurrency($expense['amount']); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Branch</span>
                    <span class="value"><?php echo htmlspecialchars($expense['branch_name'] ?? 'Main'); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Employee</span>
                    <span class="value"><?php echo htmlspecialchars($expense['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="view-item full">
                    <span class="label">Expense Name</span>
                    <span class="value"><?php echo htmlspecialchars($expense['expense_name']); ?></span>
                </div>
                <?php if ($expense['description']): ?>
                <div class="view-item full">
                    <span class="label">Description</span>
                    <span class="value"><?php echo nl2br(htmlspecialchars($expense['description'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($expense['notes']): ?>
                <div class="view-item full">
                    <span class="label">Notes</span>
                    <span class="value"><?php echo nl2br(htmlspecialchars($expense['notes'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($expense['receipt_path']): ?>
                <div class="view-item full">
                    <span class="label">Receipt</span>
                    <span class="value">
                        <a href="../../<?php echo htmlspecialchars($expense['receipt_path']); ?>" target="_blank" class="btn btn-info btn-sm">
                            <i class="fas fa-file-pdf"></i> View Receipt
                        </a>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($salary_info): ?>
                <div class="view-item full">
                    <span class="label">Salary Reference</span>
                    <span class="value">
                        <a href="../salaries/view.php?id=<?php echo $salary_info['id']; ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-eye"></i> <?php echo htmlspecialchars($salary_info['salary_number']); ?>
                        </a>
                    </span>
                </div>
                <?php endif; ?>
                <div class="view-item">
                    <span class="label">Created At</span>
                    <span class="value"><?php echo date('d M Y H:i', strtotime($expense['created_at'])); ?></span>
                </div>
                <div class="view-item">
                    <span class="label">Last Updated</span>
                    <span class="value"><?php echo date('d M Y H:i', strtotime($expense['updated_at'])); ?></span>
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
    flex-wrap: wrap;
    gap: 12px;
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

.view-status {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.type-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.type-badge.business { background: #D1FAE5; color: #065F46; }
.type-badge.personal { background: #FEF3C7; color: #92400E; }
.type-badge.salary { background: #EDE9FE; color: #6D28D9; }

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

.category-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    background: #EDE9FE;
    color: #6D28D9;
}

.text-danger { color: #DC2626; }
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

.btn-info {
    background: #3B82F6;
    color: white;
}
.btn-info:hover { background: #2563EB; }

.btn-sm { padding: 5px 12px; font-size: 12px; }

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
    .view-grid {
        grid-template-columns: 1fr;
    }
    .view-item.full {
        grid-column: span 1;
    }
    .view-header {
        flex-direction: column;
        text-align: center;
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