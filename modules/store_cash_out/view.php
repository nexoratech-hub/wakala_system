<?php
// ================================================================
// FILE: modules/store_cash_out/view.php
// WAKALA FINANCIAL SYSTEM - VIEW CASH OUT
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
// GET CASH OUT ID
// ============================================================
$cashout_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($cashout_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET CASH OUT DATA
// ============================================================
$sql = "SELECT 
            sco.*,
            e.full_name as employee_name,
            b.branch_name as branch_name
        FROM store_cash_out sco
        LEFT JOIN employees e ON sco.employee_id = e.id
        LEFT JOIN branches b ON sco.branch_id = b.id
        WHERE sco.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$cashout_id]);
$cashout = $stmt->fetch();

if (!$cashout) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only view their own cash outs
if ($role == 'employee' && $cashout['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// Status colors
$status = ucfirst($cashout['status'] ?? 'pending');
$status_colors = [
    'pending' => 'status-pending',
    'approved' => 'status-approved',
    'rejected' => 'status-rejected',
    'cancelled' => 'status-cancelled'
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
                <h2><i class="fas fa-money-bill-wave"></i> Cash Out Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($cashout['cashout_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <?php if (strtolower($cashout['status'] ?? '') == 'pending'): ?>
                <a href="edit.php?id=<?php echo $cashout_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <?php endif; ?>
                <a href="#" class="btn btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
        </div>

        <!-- ============================================================
        VIEW CONTAINER
        ============================================================ -->
        <div class="view-container" id="printableArea">
            
            <!-- ===== CASH OUT HEADER ===== -->
            <div class="view-header">
                <div class="view-title">
                    <h3>Cash Out Record</h3>
                    <span class="cashout-number">#<?php echo htmlspecialchars($cashout['cashout_number']); ?></span>
                </div>
                <div class="view-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo date('d M Y', strtotime($cashout['cashout_date'])); ?>
                </div>
            </div>
            
            <!-- ===== CASH OUT INFO ===== -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-info-circle"></i> Cash Out Information</h3>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <?php echo $status; ?>
                    </span>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-alt"></i> Date</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($cashout['cashout_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                            <span class="info-value"><?php echo htmlspecialchars($cashout['branch_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Employee</span>
                            <span class="info-value"><?php echo htmlspecialchars($cashout['employee_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-money-bill-wave"></i> Amount</span>
                            <span class="info-value amount"><?php echo formatCurrency($cashout['amount']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-question-circle"></i> Reason</span>
                            <span class="info-value"><?php echo htmlspecialchars($cashout['reason']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Taken By</span>
                            <span class="info-value"><?php echo htmlspecialchars($cashout['taken_by'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user-check"></i> Approved By</span>
                            <span class="info-value"><?php echo htmlspecialchars($cashout['approved_by'] ?? 'N/A'); ?></span>
                        </div>
                        <?php if ($cashout['approved_date']): ?>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-check-circle"></i> Approved Date</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($cashout['approved_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($cashout['description'])): ?>
                        <div class="info-item full-width">
                            <span class="info-label"><i class="fas fa-file-alt"></i> Description</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($cashout['description'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($cashout['notes'])): ?>
                        <div class="info-item full-width">
                            <span class="info-label"><i class="fas fa-sticky-note"></i> Notes</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($cashout['notes'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- ===== TIMELINE ===== -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-clock"></i> Timeline</h3>
                </div>
                <div class="view-card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-icon created">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">Record Created</div>
                                <div class="timeline-date"><?php echo date('d M Y H:i:s', strtotime($cashout['created_at'])); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($cashout['status'] == 'approved' || $cashout['status'] == 'rejected'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon <?php echo ($cashout['status'] == 'approved') ? 'approved' : 'rejected'; ?>">
                                <i class="fas <?php echo ($cashout['status'] == 'approved') ? 'fa-check' : 'fa-times'; ?>"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo ucfirst($cashout['status']); ?></div>
                                <div class="timeline-date"><?php echo date('d M Y H:i:s', strtotime($cashout['updated_at'])); ?></div>
                                <?php if ($cashout['approved_by']): ?>
                                <div class="timeline-detail">By: <?php echo htmlspecialchars($cashout['approved_by']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($cashout['status'] == 'cancelled'): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon cancelled">
                                <i class="fas fa-ban"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">Cancelled</div>
                                <div class="timeline-date"><?php echo date('d M Y H:i:s', strtotime($cashout['updated_at'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="timeline-item">
                            <div class="timeline-icon current">
                                <i class="fas fa-flag"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">Current Status</div>
                                <div class="timeline-date">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===== ACTION BUTTONS ===== -->
            <div class="view-actions">
                <?php if (strtolower($cashout['status'] ?? '') == 'pending'): ?>
                <a href="edit.php?id=<?php echo $cashout_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Cash Out
                </a>
                <?php endif; ?>
                <a href="delete.php?id=<?php echo $cashout_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $cashout_id; ?>)">
                    <i class="fas fa-trash"></i> Delete Cash Out
                </a>
                <a href="#" class="btn btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Record
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

.cashout-number {
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

.info-value.amount {
    color: #DC2626;
    font-weight: 700;
}

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

.status-rejected {
    background: #FEE2E2;
    color: #991B1B;
}

.status-cancelled {
    background: #F3F4F6;
    color: #6B7280;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--view-border);
}

.timeline-item {
    display: flex;
    gap: 16px;
    padding-bottom: 20px;
    position: relative;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    flex-shrink: 0;
    z-index: 1;
}

.timeline-icon.created {
    background: #3B82F6;
}

.timeline-icon.approved {
    background: #10B981;
}

.timeline-icon.rejected {
    background: #EF4444;
}

.timeline-icon.cancelled {
    background: #6B7280;
}

.timeline-icon.current {
    background: #7F1D1D;
}

.timeline-content {
    flex: 1;
}

.timeline-title {
    font-weight: 600;
    color: var(--view-text);
    font-size: 14px;
}

.timeline-date {
    font-size: 12px;
    color: var(--view-text-secondary);
}

.timeline-detail {
    font-size: 12px;
    color: var(--view-text-light);
    margin-top: 2px;
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
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        width: 100%;
        justify-content: center;
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
    return confirm('Are you sure you want to delete this cash out record? This action cannot be undone.');
}
</script>
</body>
</html>