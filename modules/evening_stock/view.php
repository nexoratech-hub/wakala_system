<?php
// ================================================================
// FILE: modules/evening_stock/view.php
// WAKALA FINANCIAL SYSTEM - VIEW EVENING STOCK
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
// CHECK PERMISSION
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin' && $role !== 'employee') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET STOCK ID
// ============================================================
$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET STOCK DATA
// ============================================================
$sql = "SELECT 
            es.*,
            e.full_name as employee_name,
            b.branch_name as branch_name
        FROM evening_stocks es
        LEFT JOIN employees e ON es.employee_id = e.id
        LEFT JOIN branches b ON es.branch_id = b.id
        WHERE es.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$stock_id]);
$stock = $stmt->fetch();

if (!$stock) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only view their own stocks
if ($role == 'employee' && $stock['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($stock['provider_data'] ?? '{}', true);

// Get provider details
$providers = [];
if (!empty($provider_data)) {
    $provider_ids = array_keys($provider_data);
    $placeholders = implode(',', array_fill(0, count($provider_ids), '?'));
    $stmt = $db->prepare("SELECT id, provider_name, provider_code, icon_class, color_code FROM providers WHERE id IN ($placeholders)");
    $stmt->execute($provider_ids);
    $providers_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($providers_list as $p) {
        $providers[$p['id']] = $p;
    }
}

$total_float = $stock['cumm_total'] ?? 0;
$cash_balance = $stock['cash_balance'] ?? 0;
$total_stock = $total_float + $cash_balance;

// Status colors
$status = ucfirst($stock['status'] ?? 'pending');
$status_colors = [
    'waiting' => 'status-waiting',
    'pending' => 'status-pending',
    'approved' => 'status-approved',
    'adjusted' => 'status-adjusted',
    'rejected' => 'status-rejected'
];
$status_class = $status_colors[strtolower($status)] ?? 'status-pending';

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-moon"></i> Evening Stock Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($stock['stock_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $stock_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="#" class="btn btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- ============================================================
        VIEW CONTAINER
        ============================================================ -->
        <div class="view-container" id="printableArea">
            
            <!-- ===== STOCK HEADER ===== -->
            <div class="view-header">
                <div class="view-title">
                    <h3>Evening Stock Report</h3>
                    <span class="stock-number">#<?php echo htmlspecialchars($stock['stock_number']); ?></span>
                </div>
                <div class="view-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('d M Y', strtotime($stock['stock_date'])); ?>
                </div>
            </div>
            
            <!-- ===== STOCK INFO GRID ===== -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-info-circle"></i> Stock Information</h3>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo $status; ?>
                    </span>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-alt"></i> Date</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($stock['stock_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                            <span class="info-value"><?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Employee</span>
                            <span class="info-value"><?php echo htmlspecialchars($stock['employee_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Submitted</span>
                            <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($stock['submitted_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                            <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($stock['updated_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-check-circle"></i> Status</span>
                            <span class="info-value">
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $status; ?>
                                </span>
                            </span>
                        </div>
                        <?php if (!empty($stock['notes'])): ?>
                        <div class="info-item full-width">
                            <span class="info-label"><i class="fas fa-sticky-note"></i> Notes</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($stock['notes'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- ===== PROVIDERS TABLE ===== -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-university"></i> Provider Balances</h3>
                    <span class="provider-count"><?php echo count($provider_data); ?> providers</span>
                </div>
                <div class="view-card-body">
                    <div class="table-responsive">
                        <table class="providers-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Provider</th>
                                    <th>Code</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($provider_data)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No provider data available</td>
                                    </tr>
                                <?php else: 
                                    $counter = 1;
                                    foreach ($provider_data as $provider_id => $amount): 
                                        $provider = $providers[$provider_id] ?? null;
                                ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td>
                                            <?php if ($provider): ?>
                                                <span class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>; display:inline-block;width:24px;height:24px;border-radius:50%;text-align:center;line-height:24px;color:white;font-size:11px;margin-right:8px;">
                                                    <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                                </span>
                                                <?php echo htmlspecialchars($provider['provider_name']); ?>
                                            <?php else: ?>
                                                Provider #<?php echo $provider_id; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($provider['provider_code'] ?? 'N/A'); ?></td>
                                        <td class="amount"><?php echo formatCurrency($amount); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-right"><strong>Total Float</strong></td>
                                    <td class="amount total"><?php echo formatCurrency($total_float); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="text-right"><strong>Cash Balance</strong></td>
                                    <td class="amount cash"><?php echo formatCurrency($cash_balance); ?></td>
                                </tr>
                                <tr class="grand-total">
                                    <td colspan="3" class="text-right"><strong>Total Stock</strong></td>
                                    <td class="amount grand-total"><?php echo formatCurrency($total_stock); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== ACTION BUTTONS ===== -->
            <div class="view-actions">
                <a href="edit.php?id=<?php echo $stock_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Stock
                </a>
                <a href="delete.php?id=<?php echo $stock_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $stock_id; ?>)">
                    <i class="fas fa-trash"></i> Delete Stock
                </a>
                <a href="#" class="btn btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </a>
            </div>
            
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES - WITH FULL DARK MODE SUPPORT
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --view-bg: #FFFFFF;
    --view-text: #1F2937;
    --view-text-secondary: #6B7280;
    --view-text-light: #9CA3AF;
    --view-border: #E5E7EB;
    --view-card-bg: #FFFFFF;
    --view-card-header: #FAFBFC;
    --view-hover: #F3F4F6;
    --view-shadow: rgba(0,0,0,0.06);
    --view-success: #D1FAE5;
    --view-success-text: #065F46;
}

html.dark-mode {
    --view-bg: #1F2937;
    --view-text: #F9FAFB;
    --view-text-secondary: #9CA3AF;
    --view-text-light: #6B7280;
    --view-border: #374151;
    --view-card-bg: #1F2937;
    --view-card-header: #374151;
    --view-hover: #374151;
    --view-shadow: rgba(0,0,0,0.3);
    --view-success: #065F46;
    --view-success-text: #D1FAE5;
}

body {
    background: var(--view-bg) !important;
    color: var(--view-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    background: var(--view-bg) !important;
    transition: background 0.3s ease;
}

.main-content {
    background: var(--view-bg) !important;
    transition: background 0.3s ease;
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
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
    color: var(--view-text);
    margin: 0;
    transition: color 0.3s ease;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 3px 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.btn-back {
    background: var(--view-hover);
    color: var(--view-text-secondary);
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--view-border);
    color: var(--view-text);
}

.btn-edit {
    background: #D1FAE5;
    color: #059669;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-edit:hover {
    background: #A7F3D0;
    color: #047857;
}

.btn-print {
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.btn-print:hover {
    background: #BFDBFE;
    color: #1E40AF;
}

/* ============================================================
   VIEW CONTAINER
   ============================================================ */
.view-container {
    background: var(--view-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--view-shadow);
    border: 1px solid var(--view-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

/* ============================================================
   VIEW HEADER
   ============================================================ */
.view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    background: var(--view-card-header);
    border-bottom: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.view-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.view-title h3 {
    font-size: 18px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0;
}

.stock-number {
    font-size: 13px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59,130,246,0.1);
    padding: 2px 12px;
    border-radius: 12px;
}

.view-date {
    font-size: 14px;
    color: var(--view-text-secondary);
}

.view-date i {
    color: #3B82F6;
    margin-right: 6px;
}

/* ============================================================
   VIEW CARD
   ============================================================ */
.view-card {
    border-bottom: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.view-card:last-child {
    border-bottom: none;
}

.view-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 24px;
    background: var(--view-card-header);
    border-bottom: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.view-card-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0;
}

.view-card-header h3 i {
    color: #3B82F6;
    margin-right: 8px;
}

.provider-count {
    font-size: 12px;
    color: var(--view-text-secondary);
}

.view-card-body {
    padding: 20px 24px;
}

/* ============================================================
   INFO GRID
   ============================================================ */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.info-item.full-width {
    grid-column: span 2;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--view-text-light);
    font-weight: 600;
    letter-spacing: 0.5px;
}

.info-label i {
    margin-right: 4px;
}

.info-value {
    font-size: 15px;
    font-weight: 500;
    color: var(--view-text);
}

/* ============================================================
   PROVIDERS TABLE
   ============================================================ */
.providers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.providers-table thead th {
    background: #3B82F6;
    color: #FFFFFF;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
}

.providers-table thead th:first-child {
    border-radius: 6px 0 0 0;
}

.providers-table thead th:last-child {
    border-radius: 0 6px 0 0;
}

.providers-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--view-border);
    color: var(--view-text);
    transition: border-color 0.3s ease, color 0.3s ease;
}

.providers-table tbody tr:hover {
    background: var(--view-hover);
}

.providers-table tfoot td {
    padding: 10px 14px;
    border-top: 1px solid var(--view-border);
    font-weight: 600;
}

.providers-table .amount {
    text-align: right;
    font-weight: 600;
}

.providers-table .amount.total {
    color: #1D4ED8;
}

.providers-table .amount.cash {
    color: #059669;
}

.providers-table .amount.grand-total {
    color: #10B981;
    font-size: 16px;
}

.providers-table .text-right {
    text-align: right;
}

.providers-table .text-center {
    text-align: center;
}

.providers-table .grand-total {
    background: var(--view-success);
}

.providers-table .grand-total td {
    border-top: 2px solid #10B981;
}

/* ============================================================
   STATUS BADGE
   ============================================================ */
.status-badge {
    display: inline-block;
    padding: 3px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-approved {
    background: #D1FAE5;
    color: #065F46;
}

.status-pending {
    background: #FEF3C7;
    color: #92400E;
}

.status-waiting {
    background: #DBEAFE;
    color: #1E40AF;
}

.status-adjusted {
    background: #EDE9FE;
    color: #5B21B6;
}

.status-rejected {
    background: #FEE2E2;
    color: #991B1B;
}

/* ============================================================
   VIEW ACTIONS
   ============================================================ */
.view-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--view-border);
    background: var(--view-card-header);
    transition: all 0.3s ease;
}

.btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}

.btn-delete {
    background: #FEE2E2;
    color: #DC2626;
}

.btn-delete:hover {
    background: #FECACA;
    color: #B91C1C;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .view-header {
        flex-direction: column;
        gap: 8px;
        align-items: flex-start;
    }
    
    .info-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .info-item.full-width {
        grid-column: span 2;
    }
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .view-card-body {
        padding: 14px 16px;
    }
    
    .view-card-header {
        padding: 12px 16px;
    }
}

@media (max-width: 480px) {
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .info-item.full-width {
        grid-column: span 1;
    }
    
    .view-header {
        padding: 14px 16px;
    }
    
    .view-card-body {
        padding: 12px 14px;
    }
    
    .providers-table thead th,
    .providers-table tbody td,
    .providers-table tfoot td {
        padding: 6px 10px;
        font-size: 12px;
    }
}

/* ============================================================
   PRINT STYLES
   ============================================================ */
@media print {
    .admin-topbar,
    .wakala-sidebar,
    .page-header,
    .view-actions,
    .btn-back,
    .btn-edit,
    .btn-print,
    .btn-delete {
        display: none !important;
    }
    
    .view-container {
        border: none !important;
        box-shadow: none !important;
        padding: 20px !important;
    }
    
    .view-header {
        background: #f8f9fa !important;
    }
    
    .providers-table thead th {
        background: #3B82F6 !important;
        color: #ffffff !important;
    }
    
    body {
        background: #ffffff !important;
    }
    
    .main-content {
        padding: 0 !important;
    }
}
</style>

<script>
function confirmDelete(id) {
    return confirm('Are you sure you want to delete this evening stock record? This action cannot be undone.');
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
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