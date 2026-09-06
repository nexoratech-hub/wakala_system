<?php
// ================================================================
// FILE: modules/commissions/view.php
// WAKALA FINANCIAL SYSTEM - VIEW COMMISSION
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
// GET COMMISSION ID
// ============================================================
$commission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($commission_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET COMMISSION DATA
// ============================================================
$sql = "SELECT c.*, e.full_name as employee_name, b.branch_name as branch_name
        FROM commissions c
        LEFT JOIN employees e ON c.employee_id = e.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE c.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$commission_id]);
$commission = $stmt->fetch();

if (!$commission) {
    header('Location: index.php');
    exit();
}

// Check permission - employee can only view their own commissions
if ($role == 'employee' && $commission['employee_id'] != $user_id) {
    header('Location: index.php');
    exit();
}

// ============================================================
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($commission['provider_data'] ?? '{}', true);

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

$total_commission = $commission['total_commission'] ?? 0;
$other_income = $commission['other_income'] ?? 0;
$total_business_income = $commission['total_business_income'] ?? 0;
$allocate_to_capital = $commission['allocate_to_capital'] ?? 'yes';
$allocated_amount = $commission['allocated_amount'] ?? 0;

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-hand-holding-usd"></i> Commission Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($commission['commission_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $commission_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <div class="view-container">
            
            <!-- Commission Info -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-info-circle"></i> Commission Information</h3>
                    <span class="commission-number">#<?php echo htmlspecialchars($commission['commission_number']); ?></span>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-alt"></i> Date</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($commission['commission_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                            <span class="info-value"><?php echo htmlspecialchars($commission['branch_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Employee</span>
                            <span class="info-value"><?php echo htmlspecialchars($commission['employee_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Created</span>
                            <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($commission['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-building"></i> Allocate to Capital</span>
                            <span class="info-value"><?php echo ucfirst($allocate_to_capital); ?></span>
                        </div>
                        <?php if ($allocate_to_capital == 'yes'): ?>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-coins"></i> Allocated Amount</span>
                            <span class="info-value"><?php echo formatCurrency($allocated_amount); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($commission['notes'])): ?>
                        <div class="info-item full-width">
                            <span class="info-label"><i class="fas fa-sticky-note"></i> Notes</span>
                            <span class="info-value"><?php echo nl2br(htmlspecialchars($commission['notes'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Providers Table -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-university"></i> Provider Commissions</h3>
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
                                    <th>Commission</th>
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
                                    <td colspan="3" class="text-right"><strong>Total Commission</strong></td>
                                    <td class="amount total"><?php echo formatCurrency($total_commission); ?></td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="text-right"><strong>Other Income</strong></td>
                                    <td class="amount other-income"><?php echo formatCurrency($other_income); ?></td>
                                </tr>
                                <tr class="grand-total">
                                    <td colspan="3" class="text-right"><strong>Total Business Income</strong></td>
                                    <td class="amount grand-total"><?php echo formatCurrency($total_business_income); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="view-actions">
                <a href="edit.php?id=<?php echo $commission_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Commission
                </a>
                <a href="delete.php?id=<?php echo $commission_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $commission_id; ?>)">
                    <i class="fas fa-trash"></i> Delete Commission
                </a>
            </div>
            
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES
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
    color: #10B981;
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

.view-container {
    background: var(--view-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--view-shadow);
    border: 1px solid var(--view-border);
    overflow: hidden;
    transition: all 0.3s ease;
}

.view-card {
    border-bottom: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.view-card:last-child { border-bottom: none; }

.view-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
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
    color: #10B981;
    margin-right: 8px;
}

.commission-number {
    font-size: 12px;
    font-weight: 600;
    color: #10B981;
    background: rgba(16,185,129,0.1);
    padding: 2px 12px;
    border-radius: 12px;
}

.provider-count {
    font-size: 12px;
    color: var(--view-text-secondary);
}

.view-card-body {
    padding: 20px;
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

.providers-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.providers-table thead th {
    background: #10B981;
    color: #FFFFFF;
    padding: 10px 14px;
    text-align: left;
    font-weight: 600;
}

.providers-table tbody td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--view-border);
    color: var(--view-text);
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

.providers-table .amount.other-income {
    color: #7C3AED;
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
    background: rgba(16,185,129,0.05);
}

.providers-table .grand-total td {
    border-top: 2px solid #10B981;
}

.view-actions {
    display: flex;
    gap: 12px;
    padding: 16px 20px;
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

function confirmDelete(id) {
    return confirm('Are you sure you want to delete this commission record? This action cannot be undone.');
}
</style>
</body>
</html>