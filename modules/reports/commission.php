<?php
// ================================================================
// FILE: modules/reports/commission.php
// WAKALA FINANCIAL SYSTEM - COMMISSION REPORT
// WITH FULL DARK MODE SUPPORT
// ================================================================

// ============================================================
// INCLUDE CONFIG BEFORE SESSION
// ============================================================
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ============================================================
// START SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CHECK LOGIN
// ============================================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

// ============================================================
// GET FILTER PARAMETERS
// ============================================================
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;

// ============================================================
// GET COMMISSIONS
// ============================================================
$sql = "SELECT 
            c.*,
            e.full_name as employee_name,
            b.branch_name as branch_name
        FROM commissions c
        LEFT JOIN employees e ON c.employee_id = e.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE c.commission_date BETWEEN ? AND ?";

$params = [$start_date, $end_date];

if ($employee_id > 0) {
    $sql .= " AND c.employee_id = ?";
    $params[] = $employee_id;
}

if ($role === 'employee') {
    $sql .= " AND c.employee_id = ?";
    $params[] = $user_id;
}

$sql .= " ORDER BY c.commission_date DESC, c.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$commissions = $stmt->fetchAll();

// ============================================================
// CALCULATE TOTALS
// ============================================================
$total_commission = 0;
$total_allocated = 0;

foreach ($commissions as $c) {
    $total_commission += floatval($c['total_commission'] ?? 0);
    $total_allocated += floatval($c['allocated_amount'] ?? 0);
}

// ============================================================
// GET EMPLOYEES FOR FILTER
// ============================================================
$employees = [];
if ($role === 'admin' || $role === 'super_admin') {
    $emp_stmt = $db->query("SELECT id, full_name FROM employees WHERE is_active = 1 ORDER BY full_name");
    $employees = $emp_stmt->fetchAll();
}

// ============================================================
// INCLUDE HEADER
// ============================================================
if ($role === 'employee') {
    include_once '../../includes/employee_header.php';
    include_once '../../includes/employee_sidebar.php';
    include_once '../../includes/employee_topbar.php';
} else {
    include_once '../../includes/admin_header.php';
    include_once '../../includes/admin_sidebar.php';
    include_once '../../includes/admin_topbar.php';
}
?>

<!-- ============================================================
CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-hand-holding-usd"></i> Commission Report</h2>
                <span class="record-count"><?php echo count($commissions); ?> records</span>
            </div>
            <div class="page-header-right">
                <a href="export.php?type=commission&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>" class="btn btn-export">
                    <i class="fas fa-file-export"></i> Export
                </a>
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- ============================================================
        FILTER FORM
        ============================================================ -->
        <div class="filter-container">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                <?php if ($role === 'admin' || $role === 'super_admin'): ?>
                <div class="filter-group">
                    <label>Employee</label>
                    <select name="employee_id">
                        <option value="0">All Employees</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $employee_id == $e['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($e['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-group filter-actions">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-filter"></i> Apply Filter
                    </button>
                    <a href="commission.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        SUMMARY CARDS - 3 CARDS
        ============================================================ -->
        <div class="summaries-grid-three">
            <div class="summary-card card-total">
                <div class="summary-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Commissions</div>
                    <div class="summary-value"><?php echo count($commissions); ?></div>
                    <div class="summary-sub">Records</div>
                </div>
            </div>

            <div class="summary-card card-commission">
                <div class="summary-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Total Amount</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_commission); ?></div>
                    <div class="summary-sub">All Commissions</div>
                </div>
            </div>

            <div class="summary-card card-allocated">
                <div class="summary-icon"><i class="fas fa-building"></i></div>
                <div class="summary-content">
                    <div class="summary-label">Allocated to Capital</div>
                    <div class="summary-value"><?php echo formatCurrencyShort($total_allocated); ?></div>
                    <div class="summary-sub">Capital Allocation</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        COMMISSIONS TABLE
        ============================================================ -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Commission Records</h3>
                <div class="table-actions">
                    <input type="text" id="searchInput" placeholder="Search..." class="search-input">
                </div>
            </div>

            <?php if (empty($commissions)): ?>
                <div class="empty-state">
                    <i class="fas fa-hand-holding-usd"></i>
                    <h3>No Commissions Found</h3>
                    <p>No commission records found for the selected filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" id="commissionsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Comm. No.</th>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Branch</th>
                                <th>Total Commission</th>
                                <th>Allocated</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($commissions as $c): 
                            ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="comm-number">
                                            <?php echo htmlspecialchars($c['commission_number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo formatDate($c['commission_date']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($c['employee_name'] ?? '—'); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($c['branch_name'] ?? 'Main'); ?>
                                    </td>
                                    <td>
                                        <span class="amount-comm">
                                            <?php echo formatCurrency($c['total_commission']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="amount-allocated">
                                            <?php echo formatCurrency($c['allocated_amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $c['allocate_to_capital'] == 'yes' ? 'status-yes' : 'status-no'; ?>">
                                            <?php echo $c['allocate_to_capital'] == 'yes' ? 'Allocated' : 'Not Allocated'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php if ($role === 'employee') {
        include_once '../../includes/employee_footer.php';
    } else {
        include_once '../../includes/admin_footer.php';
    } ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
:root {
    --comm-bg: #FFFFFF;
    --comm-text: #1F2937;
    --comm-text-secondary: #6B7280;
    --comm-text-light: #9CA3AF;
    --comm-border: #E5E7EB;
    --comm-card-bg: #FFFFFF;
    --comm-hover: #F3F4F6;
    --comm-shadow: rgba(0,0,0,0.06);
    --comm-shadow-lg: rgba(0,0,0,0.12);
}

html.dark-mode {
    --comm-bg: #1F2937;
    --comm-text: #F9FAFB;
    --comm-text-secondary: #9CA3AF;
    --comm-text-light: #6B7280;
    --comm-border: #374151;
    --comm-card-bg: #1F2937;
    --comm-hover: #374151;
    --comm-shadow: rgba(0,0,0,0.3);
    --comm-shadow-lg: rgba(0,0,0,0.4);
}

body {
    background: var(--comm-bg) !important;
    color: var(--comm-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--comm-bg) !important; }
.main-content { background: var(--comm-bg) !important; }

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding: 0 4px;
}

.page-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-header-left h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--comm-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #10B981;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--comm-text-secondary);
    background: var(--comm-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.btn-export {
    background: #1E40AF;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-export:hover {
    background: #1D4ED8;
    color: white;
}

.btn-back {
    background: var(--comm-hover);
    color: var(--comm-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--comm-border);
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--comm-border);
}

/* Filter */
.filter-container {
    background: var(--comm-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--comm-border);
    margin-bottom: 16px;
    box-shadow: 0 1px 3px var(--comm-shadow);
}

.filter-form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 20px;
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
    color: var(--comm-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--comm-border);
    font-size: 13px;
    font-family: 'Inter', sans-serif;
    background: var(--comm-hover);
    color: var(--comm-text);
    transition: all 0.3s ease;
    min-width: 140px;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.filter-actions {
    flex-direction: row;
    gap: 8px;
}

.btn-filter {
    background: #DC2626;
    color: white;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-filter:hover {
    background: #B91C1C;
}

.btn-reset {
    background: var(--comm-hover);
    color: var(--comm-text);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid var(--comm-border);
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-reset:hover {
    background: var(--comm-border);
}

/* Summaries */
.summaries-grid-three {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}

.summary-card {
    background: var(--comm-card-bg);
    border-radius: 10px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 1px 3px var(--comm-shadow);
    border: 1px solid var(--comm-border);
    transition: all 0.3s ease;
    min-height: 95px;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--comm-shadow-lg);
}

.summary-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.summary-content {
    flex: 1;
    min-width: 0;
}

.summary-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: var(--comm-text-secondary);
}

.summary-value {
    font-size: 18px;
    font-weight: 800;
    color: var(--comm-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.summary-sub {
    font-size: 10px;
    color: var(--comm-text-light);
    font-weight: 500;
}

.card-total .summary-icon { background: #DBEAFE; color: #1D4ED8; }
.card-total { border-left: 4px solid #3B82F6; }

.card-commission .summary-icon { background: #D1FAE5; color: #065F46; }
.card-commission { border-left: 4px solid #10B981; }

.card-allocated .summary-icon { background: #FEF3C7; color: #D97706; }
.card-allocated { border-left: 4px solid #F59E0B; }

html.dark-mode .card-allocated .summary-icon { background: #5F3A1E; color: #FBBF24; }

/* Table */
.table-container {
    background: var(--comm-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--comm-shadow);
    border: 1px solid var(--comm-border);
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--comm-border);
}

.table-header h3 {
    font-size: 14px;
    font-weight: 600;
    color: var(--comm-text);
    margin: 0;
}

.table-header h3 i {
    color: #10B981;
    margin-right: 8px;
}

.table-actions {
    display: flex;
    gap: 10px;
}

.search-input {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid var(--comm-border);
    font-size: 12px;
    outline: none;
    width: 180px;
    transition: all 0.3s ease;
    background: var(--comm-hover);
    color: var(--comm-text);
}

.search-input:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

.table-responsive {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.data-table thead {
    background: #DC2626;
}

.data-table thead th {
    padding: 8px 12px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--comm-border);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover {
    background: var(--comm-hover);
}

.data-table tbody td {
    padding: 8px 12px;
    color: var(--comm-text);
    font-size: 12px;
}

.comm-number {
    font-weight: 600;
    color: #3B82F6;
}

.amount-comm { color: #10B981; font-weight: 600; }
.amount-allocated { color: #F59E0B; font-weight: 600; }

.status-badge {
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.status-yes {
    background: #D1FAE5;
    color: #065F46;
}

.status-no {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .status-yes {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .status-no {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
}

.empty-state i {
    font-size: 48px;
    color: var(--comm-text-light);
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 18px;
    color: var(--comm-text);
    margin: 0 0 6px 0;
}

.empty-state p {
    color: var(--comm-text-secondary);
    font-size: 13px;
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .filter-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-group input,
    .filter-group select {
        width: 100%;
        min-width: auto;
    }
    
    .filter-actions {
        flex-direction: row;
    }
    
    .filter-actions .btn-filter,
    .filter-actions .btn-reset {
        flex: 1;
        justify-content: center;
    }
    
    .summary-card {
        min-height: 85px;
        padding: 12px 14px;
    }
    
    .summary-icon {
        width: 38px;
        height: 38px;
        font-size: 16px;
    }
    
    .summary-value {
        font-size: 16px;
    }
}

@media (max-width: 480px) {
    .summaries-grid-three {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .summary-card {
        min-height: 70px;
        padding: 8px 10px;
    }
    
    .summary-icon {
        width: 30px;
        height: 30px;
        font-size: 12px;
    }
    
    .summary-value {
        font-size: 13px;
    }
    
    .summary-label {
        font-size: 8px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#commissionsTable tbody tr');
            
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
            });
        });
    }
    
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) {
            html.classList.add('dark-mode');
        } else {
            html.classList.remove('dark-mode');
        }
    }
    
    syncDarkMode();
    
    document.addEventListener('darkModeChanged', function(e) {
        syncDarkMode();
    });
});
</script>

</body>
</html>