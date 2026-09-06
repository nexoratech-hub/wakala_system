<?php
// ================================================================
// FILE: modules/capital_management/index.php
// CAPITAL MANAGEMENT - LIST ALL TRANSACTIONS
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

// Check permission
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// Get filters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

try {
    // Build query
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
            WHERE cm.transaction_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND cm.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($type_filter)) {
        $sql .= " AND cm.transaction_type = ?";
        $params[] = $type_filter;
    }

    $sql .= " ORDER BY cm.transaction_date DESC, cm.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get summaries
    $sql_summary = "SELECT 
            SUM(CASE WHEN transaction_type IN ('opening', 'additional', 'profit_allocation') THEN amount ELSE 0 END) as total_in,
            SUM(CASE WHEN transaction_type IN ('cash_out', 'adjustment') THEN amount ELSE 0 END) as total_out,
            COUNT(*) as total_transactions
            FROM capital_management
            WHERE transaction_date BETWEEN ? AND ?";
    $params_summary = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql_summary .= " AND branch_id = ?";
        $params_summary[] = $selected_branch;
    }

    $stmt = $db->prepare($sql_summary);
    $stmt->execute($params_summary);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get opening capital
    $sql_opening = "SELECT amount FROM capital_management 
                    WHERE transaction_type = 'opening' 
                    ORDER BY transaction_date ASC LIMIT 1";
    $stmt = $db->prepare($sql_opening);
    $stmt->execute();
    $opening = $stmt->fetch(PDO::FETCH_ASSOC);
    $opening_amount = $opening['amount'] ?? 0;

    // Get branches for filter
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error in capital management index: " . $e->getMessage());
    $transactions = [];
    $summary = ['total_in' => 0, 'total_out' => 0, 'total_transactions' => 0];
    $opening_amount = 0;
    $branches = [];
}

$total_in = $summary['total_in'] ?? 0;
$total_out = $summary['total_out'] ?? 0;
$current_capital = $opening_amount + $total_in - $total_out;

$branch_name = 'All Branches';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            break;
        }
    }
}

// Type labels
$type_labels = [
    'opening' => ['label' => 'Opening', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
PAGE CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== DARK MODE TOGGLE ===== -->
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-building" style="color:#bb0404;"></i> Capital Management</h2>
                <p class="text-muted">Manage and track all capital transactions</p>
            </div>
            <div class="header-right">
                <a href="add.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Transaction
                </a>
                <a href="history.php" class="btn btn-info">
                    <i class="fas fa-history"></i> Full History
                </a>
                <div class="dropdown export-dropdown">
                    <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a href="#" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> CSV</a>
                        <a href="#" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="#" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</a>
                        <a href="#" onclick="window.print()"><i class="fas fa-print"></i> Print</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== CAPITAL SUMMARY CARD ===== -->
        <div class="capital-summary-card">
            <div class="capital-summary-content">
                <div class="summary-item opening">
                    <span class="summary-label">Opening Capital</span>
                    <span class="summary-value"><?php echo formatCurrency($opening_amount); ?></span>
                </div>
                <div class="summary-item incoming">
                    <span class="summary-label">Total Incoming</span>
                    <span class="summary-value text-success">+ <?php echo formatCurrency($total_in); ?></span>
                </div>
                <div class="summary-item outgoing">
                    <span class="summary-label">Total Outgoing</span>
                    <span class="summary-value text-danger">- <?php echo formatCurrency($total_out); ?></span>
                </div>
                <div class="summary-item current">
                    <span class="summary-label">Current Capital</span>
                    <span class="summary-value current-value"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
        </div>

        <!-- ===== FILTERS ===== -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="branch" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="">All Types</option>
                        <?php foreach ($type_labels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter == $key ? 'selected' : ''; ?>>
                                <?php echo $label['label']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- ===== TRANSACTIONS TABLE ===== -->
        <div class="table-container">
            <div class="table-header">
                <h4><i class="fas fa-list"></i> Capital Transactions</h4>
                <span class="record-count"><?php echo count($transactions); ?> records</span>
            </div>
            
            <div class="table-responsive">
                <table class="data-table" id="capitalTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Capital No.</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($transactions) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php 
                                    $type_info = $type_labels[$transaction['transaction_type']] ?? ['label' => $transaction['transaction_type'], 'color' => 'gray'];
                                    $amount_class = in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? 'text-danger' : 'text-success';
                                    $amount_sign = in_array($transaction['transaction_type'], ['cash_out', 'adjustment']) ? '-' : '+';
                                    $badge_class = 'type-' . $type_info['color'];
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><span class="capital-number"><?php echo htmlspecialchars($transaction['capital_number']); ?></span></td>
                                    <td><?php echo date('d M Y', strtotime($transaction['transaction_date'])); ?></td>
                                    <td><span class="branch-badge"><?php echo htmlspecialchars($transaction['branch_name'] ?? 'Main'); ?></span></td>
                                    <td><?php echo htmlspecialchars($transaction['employee_name'] ?? 'N/A'); ?></td>
                                    <td><span class="type-badge <?php echo $badge_class; ?>"><?php echo $type_info['label']; ?></span></td>
                                    <td class="<?php echo $amount_class; ?> font-bold"><?php echo $amount_sign . ' ' . formatCurrency($transaction['amount']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($transaction['description'] ?? 'N/A', 0, 30)) . (strlen($transaction['description'] ?? '') > 30 ? '...' : ''); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $transaction['id']; ?>" class="btn-action btn-view" title="View"><i class="fas fa-eye"></i></a>
                                            <a href="edit.php?id=<?php echo $transaction['id']; ?>" class="btn-action btn-edit" title="Edit"><i class="fas fa-edit"></i></a>
                                            <button onclick="deleteTransaction(<?php echo $transaction['id']; ?>)" class="btn-action btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center no-data">
                                    <i class="fas fa-inbox" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No capital transactions found</p>
                                    <a href="add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add First Transaction</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   DARK MODE VARIABLES
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

/* Dark Mode Toggle */
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

/* Page Header */
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

.header-right {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

/* Buttons */
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

.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }

.btn-export { background: #10B981; color: white; }
.btn-export:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }

.btn-filter { background: #bb0404; color: white; }
.btn-filter:hover { background: #8a0303; }

.btn-reset { background: var(--bg-table-even); color: var(--text-secondary); border: 1px solid var(--border-color); }
.btn-reset:hover { background: var(--bg-table-hover); }

.btn-sm { padding: 5px 12px; font-size: 12px; }

/* Export Dropdown */
.dropdown { position: relative; display: inline-block; }
.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--bg-card);
    min-width: 180px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--shadow-hover);
    border: 1px solid var(--border-color);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
}
.dropdown-menu.show { display: block; }
.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 14px;
    text-decoration: none;
    color: var(--text-primary);
    font-size: 13px;
    transition: background 0.2s ease;
}
.dropdown-menu a:hover { background: var(--bg-table-hover); }
.dropdown-menu a i { width: 18px; font-size: 15px; }

/* Capital Summary Card */
.capital-summary-card {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.3);
}

.capital-summary-content {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.summary-item {
    text-align: center;
    padding: 8px 12px;
}

.summary-item .summary-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    letter-spacing: 1px;
    font-weight: 600;
}

.summary-item .summary-value {
    display: block;
    font-size: 22px;
    font-weight: 700;
    color: #ffffff;
    margin-top: 4px;
}

.summary-item .summary-value.text-success { color: #6EE7B7; }
.summary-item .summary-value.text-danger { color: #FCA5A5; }
.summary-item.current .summary-value { font-size: 26px; color: #FCD34D; }

/* Filters Bar */
.filters-bar {
    background: var(--bg-card);
    padding: 16px 20px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
}

.filters-form {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    min-width: 150px;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

/* Table */
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

.capital-number { font-weight: 600; color: #bb0404; font-size: 12px; }
.branch-badge {
    background: var(--bg-table-even);
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 12px;
    color: var(--text-muted);
}

/* Type Badges */
.type-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.type-blue { background: #DBEAFE; color: #1D4ED8; }
.type-green { background: #D1FAE5; color: #065F46; }
.type-purple { background: #EDE9FE; color: #6D28D9; }
.type-red { background: #FEE2E2; color: #991B1B; }
.type-orange { background: #FEF3C7; color: #92400E; }
.type-gray { background: #F3F4F6; color: #6B7280; }

.text-success { color: #10B981; font-weight: 600; }
.text-danger { color: #DC2626; font-weight: 600; }
.font-bold { font-weight: 700; }

/* Action Buttons */
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

.btn-view { background: #DBEAFE; color: #1D4ED8; }
.btn-view:hover { background: #1D4ED8; color: #ffffff; }

.btn-edit { background: #D1FAE5; color: #065F46; }
.btn-edit:hover { background: #065F46; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

/* Responsive */
@media (max-width: 768px) {
    .capital-summary-content {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .filters-form {
        flex-direction: column;
    }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; flex-wrap: wrap; }
    .header-right .btn { flex: 1; justify-content: center; }
    .dropdown { flex: 1; }
    .dropdown-toggle { width: 100%; justify-content: center; }
}

.no-data { padding: 40px 20px; text-align: center; }
</style>

<script>
// ============================================================
// DARK MODE TOGGLE
// ============================================================
function toggleDarkMode() {
    const body = document.body;
    const btn = document.getElementById('darkModeToggle');
    const icon = btn.querySelector('i');
    const text = btn.querySelector('span');
    
    body.classList.toggle('dark-mode');
    
    if (body.classList.contains('dark-mode')) {
        icon.className = 'fas fa-sun';
        text.textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        icon.className = 'fas fa-moon';
        text.textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

// Check saved preference
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

// ============================================================
// EXPORT DROPDOWN
// ============================================================
function toggleDropdown() {
    document.getElementById('exportMenu').classList.toggle('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-dropdown')) {
        document.getElementById('exportMenu').classList.remove('show');
    }
});

function exportData(format) {
    document.getElementById('exportMenu').classList.remove('show');
    const params = new URLSearchParams(window.location.search);
    window.location.href = 'export.php?format=' + format + '&' + params.toString();
}

// ============================================================
// DELETE FUNCTION
// ============================================================
function deleteTransaction(id) {
    if (confirm('Are you sure you want to delete this capital transaction? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + id;
    }
}
</script>

</body>
</html>