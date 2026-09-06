<?php
// ================================================================
// FILE: modules/salaries/view.php
// WAKALA FINANCIAL SYSTEM - VIEW SALARY
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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET SALARY ID
// ============================================================
$salary_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($salary_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET SALARY DATA
// ============================================================
$sql = "SELECT 
            s.*,
            e.full_name as employee_name,
            e.employee_id as employee_code,
            b.branch_name as branch_name,
            paid_by.full_name as paid_by_name,
            approved_by.full_name as approved_by_name
        FROM employee_salaries s
        LEFT JOIN employees e ON s.employee_id = e.id
        LEFT JOIN branches b ON s.branch_id = b.id
        LEFT JOIN employees paid_by ON s.paid_by = paid_by.id
        LEFT JOIN employees approved_by ON s.approved_by = approved_by.id
        WHERE s.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$salary_id]);
$salary = $stmt->fetch();

if (!$salary) {
    header('Location: index.php');
    exit();
}

// Status colors
$status = ucfirst($salary['status'] ?? 'pending');
$status_colors = [
    'paid' => 'status-paid',
    'pending' => 'status-pending',
    'cancelled' => 'status-cancelled',
    'reversed' => 'status-reversed'
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
                <h2><i class="fas fa-wallet"></i> Salary Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($salary['salary_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $salary_id; ?>" class="btn btn-edit">
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
            
            <!-- ===== SALARY HEADER ===== -->
            <div class="view-header">
                <div class="view-title">
                    <h3>Salary Slip</h3>
                    <span class="salary-number">#<?php echo htmlspecialchars($salary['salary_number']); ?></span>
                </div>
                <div class="view-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('M Y', strtotime($salary['salary_month'])); ?>
                </div>
            </div>
            
            <!-- ===== SALARY INFO GRID ===== -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-info-circle"></i> Salary Information</h3>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo $status; ?>
                    </span>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Employee</span>
                            <span class="info-value"><?php echo htmlspecialchars($salary['employee_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-id-card"></i> Employee ID</span>
                            <span class="info-value"><?php echo htmlspecialchars($salary['employee_code'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                            <span class="info-value"><?php echo htmlspecialchars($salary['branch_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-alt"></i> Month</span>
                            <span class="info-value"><?php echo date('F Y', strtotime($salary['salary_month'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-day"></i> Payment Date</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($salary['payment_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-credit-card"></i> Payment Method</span>
                            <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $salary['payment_method'] ?? 'Cash')); ?></span>
                        </div>
                        <?php if (!empty($salary['transaction_reference'])): ?>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-hashtag"></i> Transaction Ref</span>
                            <span class="info-value"><?php echo htmlspecialchars($salary['transaction_reference']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($salary['description'])): ?>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-file-alt"></i> Description</span>
                            <span class="info-value"><?php echo htmlspecialchars($salary['description']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($salary['notes'])): ?>
                        <div class="info-item full-width">
                            <span class="info-label"><i class="fas fa-sticky-note"></i> Notes</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($salary['notes'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- ===== SALARY BREAKDOWN ===== -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-calculator"></i> Salary Breakdown</h3>
                    <span class="net-pay-badge">Net Pay: <?php echo formatCurrency($salary['net_pay'] ?? 0); ?></span>
                </div>
                <div class="view-card-body">
                    <div class="breakdown-grid">
                        <div class="breakdown-item earnings">
                            <h4><i class="fas fa-plus-circle"></i> Earnings</h4>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Base Salary</span>
                                <span class="breakdown-amount"><?php echo formatCurrency($salary['base_salary'] ?? 0); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Bonus</span>
                                <span class="breakdown-amount"><?php echo formatCurrency($salary['bonus'] ?? 0); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Overtime Pay</span>
                                <span class="breakdown-amount"><?php echo formatCurrency($salary['overtime_pay'] ?? 0); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Allowances</span>
                                <span class="breakdown-amount"><?php echo formatCurrency($salary['allowances'] ?? 0); ?></span>
                            </div>
                            <div class="breakdown-row total">
                                <span class="breakdown-label"><strong>Gross Pay</strong></span>
                                <span class="breakdown-amount"><strong><?php echo formatCurrency($salary['total_gross'] ?? 0); ?></strong></span>
                            </div>
                        </div>
                        
                        <div class="breakdown-item deductions">
                            <h4><i class="fas fa-minus-circle"></i> Deductions</h4>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Tax</span>
                                <span class="breakdown-amount"><?php echo formatCurrency($salary['tax'] ?? 0); ?></span>
                            </div>
                            <div class="breakdown-row">
                                <span class="breakdown-label">Other Deductions</span>
                                <span class="breakdown-amount"><?php echo formatCurrency($salary['deductions'] ?? 0); ?></span>
                            </div>
                            <div class="breakdown-row total">
                                <span class="breakdown-label"><strong>Total Deductions</strong></span>
                                <span class="breakdown-amount"><strong><?php echo formatCurrency(($salary['tax'] ?? 0) + ($salary['deductions'] ?? 0)); ?></strong></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="net-pay-section">
                        <div class="net-pay-row">
                            <span class="net-pay-label">Net Pay</span>
                            <span class="net-pay-amount"><?php echo formatCurrency($salary['net_pay'] ?? 0); ?></span>
                        </div>
                        <div class="net-pay-details">
                            <span>Gross Pay: <?php echo formatCurrency($salary['total_gross'] ?? 0); ?></span>
                            <span>|</span>
                            <span>Deductions: <?php echo formatCurrency(($salary['tax'] ?? 0) + ($salary['deductions'] ?? 0)); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===== ACTION BUTTONS ===== -->
            <div class="view-actions">
                <a href="edit.php?id=<?php echo $salary_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Salary
                </a>
                <a href="delete.php?id=<?php echo $salary_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $salary_id; ?>)">
                    <i class="fas fa-trash"></i> Delete Salary
                </a>
                <a href="#" class="btn btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Slip
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

.main-wrapper { background: var(--view-bg) !important; }
.main-content { background: var(--view-bg) !important; }

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
}

.page-header-left h2 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 3px 12px;
    border-radius: 12px;
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

.view-container {
    background: var(--view-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--view-shadow);
    border: 1px solid var(--view-border);
    overflow: hidden;
}

.view-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    background: var(--view-card-header);
    border-bottom: 1px solid var(--view-border);
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

.salary-number {
    font-size: 13px;
    font-weight: 600;
    color: #7F1D1D;
    background: rgba(127,29,29,0.1);
    padding: 2px 12px;
    border-radius: 12px;
}

.view-date {
    font-size: 14px;
    color: var(--view-text-secondary);
}

.view-date i {
    color: #7F1D1D;
    margin-right: 6px;
}

.view-card {
    border-bottom: 1px solid var(--view-border);
}

.view-card:last-child { border-bottom: none; }

.view-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 24px;
    background: var(--view-card-header);
    border-bottom: 1px solid var(--view-border);
}

.view-card-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0;
}

.view-card-header h3 i {
    color: #7F1D1D;
    margin-right: 8px;
}

.net-pay-badge {
    font-size: 12px;
    font-weight: 600;
    color: #059669;
    background: #D1FAE5;
    padding: 2px 14px;
    border-radius: 12px;
}

html.dark-mode .net-pay-badge {
    background: #065F46;
    color: #D1FAE5;
}

.view-card-body {
    padding: 20px 24px;
}

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

.breakdown-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.breakdown-item {
    padding: 16px;
    border-radius: 10px;
    border: 1px solid var(--view-border);
}

.breakdown-item.earnings {
    background: rgba(16,185,129,0.05);
    border-color: #10B981;
}

.breakdown-item.deductions {
    background: rgba(239,68,68,0.05);
    border-color: #EF4444;
}

html.dark-mode .breakdown-item.earnings {
    background: rgba(16,185,129,0.1);
}

html.dark-mode .breakdown-item.deductions {
    background: rgba(239,68,68,0.1);
}

.breakdown-item h4 {
    font-size: 13px;
    font-weight: 600;
    color: var(--view-text);
    margin: 0 0 10px 0;
}

.breakdown-item h4 i {
    margin-right: 6px;
}

.breakdown-item.earnings h4 i { color: #10B981; }
.breakdown-item.deductions h4 i { color: #EF4444; }

.breakdown-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 13px;
    color: var(--view-text);
}

.breakdown-row.total {
    border-top: 1px solid var(--view-border);
    padding-top: 8px;
    margin-top: 4px;
}

.breakdown-row.total .breakdown-label {
    font-size: 14px;
}

.breakdown-row.total .breakdown-amount {
    font-size: 14px;
}

.net-pay-section {
    margin-top: 20px;
    padding: 16px 20px;
    background: var(--view-success);
    border-radius: 10px;
    border: 1px solid #10B981;
    text-align: center;
}

html.dark-mode .net-pay-section {
    background: #065F46;
}

.net-pay-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 16px;
}

.net-pay-label {
    font-size: 16px;
    font-weight: 600;
    color: var(--view-text);
}

.net-pay-amount {
    font-size: 28px;
    font-weight: 700;
    color: #10B981;
}

.net-pay-details {
    font-size: 12px;
    color: var(--view-text-secondary);
    margin-top: 4px;
    display: flex;
    gap: 8px;
    justify-content: center;
}

.status-badge {
    display: inline-block;
    padding: 3px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-paid {
    background: #D1FAE5;
    color: #065F46;
}

.status-pending {
    background: #FEF3C7;
    color: #92400E;
}

.status-cancelled {
    background: #FEE2E2;
    color: #991B1B;
}

.status-reversed {
    background: #EDE9FE;
    color: #5B21B6;
}

.view-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--view-border);
    background: var(--view-card-header);
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
        grid-template-columns: 1fr;
    }
    
    .info-item.full-width {
        grid-column: span 1;
    }
    
    .breakdown-grid {
        grid-template-columns: 1fr;
    }
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .net-pay-row {
        flex-direction: column;
        gap: 4px;
    }
    
    .net-pay-amount {
        font-size: 24px;
    }
}

@media (max-width: 480px) {
    .view-header {
        padding: 14px 16px;
    }
    
    .view-card-body {
        padding: 12px 14px;
    }
    
    .breakdown-item {
        padding: 12px;
    }
    
    .net-pay-section {
        padding: 12px;
    }
    
    .net-pay-amount {
        font-size: 20px;
    }
}

@media print {
    .admin-topbar, .wakala-sidebar, .page-header, .view-actions, .btn-back, .btn-edit, .btn-print, .btn-delete {
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
    
    body { background: #ffffff !important; }
    .main-content { padding: 0 !important; }
}

function confirmDelete(id) {
    return confirm('Are you sure you want to delete this salary record? This action cannot be undone.');
}
</script>
</body>
</html>