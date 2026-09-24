<?php
// ================================================================
// FILE: modules/evening_stock/edit.php
// EVENING STOCK - EDIT (ADMIN)
// ✅ Edit closing balances
// ✅ Update status
// ✅ Edit notes
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET STOCK ID
// ============================================================
$stock_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($stock_id <= 0) {
    $_SESSION['error_message'] = 'Invalid stock ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET STOCK DATA
// ============================================================
try {
    $sql = "SELECT es.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location
            FROM evening_stocks es
            LEFT JOIN employees e ON es.employee_id = e.id
            LEFT JOIN branches b ON es.branch_id = b.id
            WHERE es.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$stock_id]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $stock = null;
}

if (!$stock) {
    $_SESSION['error_message'] = 'Evening stock not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDERS
// ============================================================
$stmt = $db->prepare("
    SELECT esp.*, 
           p.icon_class as provider_icon,
           p.color_code as provider_color
    FROM evening_stock_providers esp
    LEFT JOIN providers p ON esp.provider_id = p.id
    WHERE esp.evening_stock_id = ?
    ORDER BY esp.id ASC
");
$stmt->execute([$stock_id]);
$providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_stock') {
    try {
        $notes = trim($_POST['notes'] ?? '');
        $status = $_POST['status'] ?? 'waiting';
        
        if (!in_array($status, ['waiting', 'approved', 'adjusted', 'rejected'])) {
            throw new Exception('Invalid status.');
        }
        
        // ====================================================
        // START TRANSACTION
        // ====================================================
        $db->beginTransaction();
        
        $total_float = 0;
        $total_cash = 0;
        $provider_data_array = [];
        
        // Update each provider
        foreach ($providers as $p) {
            $pid = $p['id'];
            $provider_id = $p['provider_id'];
            
            $closing_float = floatval($_POST['closing_float'][$pid] ?? $p['closing_float']);
            $closing_cash = floatval($_POST['closing_cash'][$pid] ?? $p['closing_cash']);
            
            $total_float += $closing_float;
            $total_cash += $closing_cash;
            
            $provider_data_array[$provider_id] = [
                'provider_name' => $p['provider_name'],
                'provider_code' => $p['provider_code'],
                'opening_float' => floatval($p['opening_float']),
                'opening_cash' => floatval($p['opening_cash']),
                'closing_float' => $closing_float,
                'closing_cash' => $closing_cash,
                'total_deposits' => floatval($p['total_deposits']),
                'total_withdrawals' => floatval($p['total_withdrawals'])
            ];
            
            $stmt = $db->prepare("
                UPDATE evening_stock_providers 
                SET closing_float = ?, closing_cash = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$closing_float, $closing_cash, $pid]);
        }
        
        // Update evening stock
        $stmt = $db->prepare("
            UPDATE evening_stocks 
            SET provider_data = ?,
                cash_balance = ?,
                cumm_total = ?,
                status = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            json_encode($provider_data_array),
            $total_cash,
            $total_float,
            $status,
            $notes,
            $stock_id
        ]);
        
        // Log activity
        logActivity(
            $user_id,
            'Edit Evening Stock',
            'Evening Stock',
            $stock_id,
            '',
            'Evening stock updated: ' . $stock['stock_number'] . ' - Status: ' . $status
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = 'Evening stock updated successfully!';
        header('Location: view.php?id=' . $stock_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Success/error messages
$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        BRANCH CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-edit"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Editing Evening Stock For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($stock['branch_name'] ?? 'N/A'); ?></span>
                <?php if (!empty($stock['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($stock['branch_code']); ?></span>
                <?php endif; ?>
                <span class="branch-status-date">
                    <i class="fas fa-calendar"></i>
                    <?php echo date('d M Y', strtotime($stock['stock_date'])); ?>
                </span>
            </div>
            <a href="view.php?id=<?php echo $stock_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Details</span>
            </a>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit" style="color:#F59E0B;"></i> Edit Evening Stock</h2>
                <p class="text-muted">
                    Reference: <strong><?php echo htmlspecialchars($stock['stock_number']); ?></strong>
                </p>
            </div>
        </div>

        <!-- ============================================================
        ALERTS
        ============================================================ -->
        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message_session; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        EDIT FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="editForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="edit_stock">
                
                <!-- ===== PROVIDERS ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Closing Balances</h3>
                        <span class="section-badge"><?php echo count($providers); ?> Providers</span>
                    </div>
                    
                    <p class="section-hint">
                        <i class="fas fa-info-circle"></i>
                        Edit closing float and cash balances. Opening balances hazibadiliki.
                    </p>
                    
                    <div class="providers-table-wrapper">
                        <table class="providers-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th>Provider</th>
                                    <th class="text-right">Opening Float</th>
                                    <th class="text-right">Opening Cash</th>
                                    <th class="text-right">Deposits</th>
                                    <th class="text-right">Withdrawals</th>
                                    <th class="text-right">Closing Float</th>
                                    <th class="text-right">Closing Cash</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($providers as $p): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td>
                                            <div class="provider-cell">
                                                <div class="provider-icon-sm" style="background: <?php echo htmlspecialchars($p['provider_color'] ?? '#7C3AED'); ?>;">
                                                    <i class="<?php echo htmlspecialchars($p['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                                                </div>
                                                <div class="provider-info-cell">
                                                    <span class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                                    <span class="provider-code"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-readonly"><?php echo formatCurrency($p['opening_float']); ?></span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-readonly"><?php echo formatCurrency($p['opening_cash']); ?></span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-readonly text-success">+<?php echo formatCurrency($p['total_deposits']); ?></span>
                                        </td>
                                        <td class="text-right">
                                            <span class="amount-readonly text-danger">-<?php echo formatCurrency($p['total_withdrawals']); ?></span>
                                        </td>
                                        <td class="text-right">
                                            <input type="text" 
                                                   name="closing_float[<?php echo $p['id']; ?>]" 
                                                   class="money-input-table"
                                                   value="<?php echo number_format($p['closing_float'], 0, '.', ''); ?>"
                                                   oninput="formatMoneyInput(this); updateTotals();">
                                        </td>
                                        <td class="text-right">
                                            <input type="text" 
                                                   name="closing_cash[<?php echo $p['id']; ?>]" 
                                                   class="money-input-table"
                                                   value="<?php echo number_format($p['closing_cash'], 0, '.', ''); ?>"
                                                   oninput="formatMoneyInput(this); updateTotals();">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="totals-row">
                                    <td colspan="6" class="text-right"><strong>TOTALS</strong></td>
                                    <td class="text-right"><span class="total-value" id="totalFloat">TSh 0</span></td>
                                    <td class="text-right"><span class="total-value" id="totalCash">TSh 0</span></td>
                                </tr>
                                <tr class="grand-total-row">
                                    <td colspan="6" class="text-right"><strong>GRAND TOTAL</strong></td>
                                    <td colspan="2" class="text-right">
                                        <span class="grand-total" id="grandTotal">TSh 0</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                
                <!-- ===== STATUS & NOTES ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-tasks"></i> Status & Notes</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-tag"></i> Status <span class="required">*</span>
                            </label>
                            <select name="status" class="form-control" required>
                                <option value="waiting" <?php echo $stock['status'] == 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                                <option value="approved" <?php echo $stock['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="adjusted" <?php echo $stock['status'] == 'adjusted' ? 'selected' : ''; ?>>Adjusted</option>
                                <option value="rejected" <?php echo $stock['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-sticky-note"></i> Notes
                        </label>
                        <textarea name="notes" 
                                  class="form-control textarea-control" 
                                  rows="3" 
                                  placeholder="Additional notes..."><?php echo htmlspecialchars($stock['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- ===== ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Update Evening Stock
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="view.php?id=<?php echo $stock_id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
                
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ===== Same CSS base as add.php but adapted ===== */
:root {
    --ee-bg: #F3F4F6;
    --ee-text: #1F2937;
    --ee-text-secondary: #6B7280;
    --ee-text-light: #9CA3AF;
    --ee-border: #E5E7EB;
    --ee-card-bg: #FFFFFF;
    --ee-input-bg: #F9FAFB;
    --ee-hover: #F3F4F6;
    --ee-shadow: rgba(0,0,0,0.06);
}
html.dark-mode {
    --ee-bg: #0F172A;
    --ee-text: #F9FAFB;
    --ee-text-secondary: #9CA3AF;
    --ee-text-light: #6B7280;
    --ee-border: #334155;
    --ee-card-bg: #1E293B;
    --ee-input-bg: #334155;
    --ee-hover: #334155;
    --ee-shadow: rgba(0,0,0,0.3);
}

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
body { background: var(--ee-bg) !important; color: var(--ee-text); }
.main-wrapper { background: var(--ee-bg) !important; }
.main-content { background: var(--ee-bg) !important; padding: 16px 20px !important; }

.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(245, 158, 11, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
}
.branch-status-icon {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    position: relative;
    z-index: 1;
}
.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1;
    position: relative;
    z-index: 1;
}
.branch-status-label {
    font-size: 11px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.branch-status-name {
    font-size: 18px;
    font-weight: 800;
    color: #FFFFFF;
}
.branch-status-code {
    font-size: 11px;
    font-weight: 700;
    color: #FEF3C7;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}
.branch-status-date {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    font-weight: 600;
}
.btn-back-card {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.25); color: #FFFFFF; transform: translateX(-3px); }

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 16px;
    flex-wrap: wrap;
}
.header-left h2 {
    font-size: 22px;
    font-weight: 800;
    color: var(--ee-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header-left .text-muted { font-size: 13px; color: var(--ee-text-secondary); margin: 4px 0 0 0; }
.header-left .text-muted strong { color: #F59E0B; font-family: 'Courier New', monospace; font-weight: 800; }

.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; padding: 0 4px; opacity: 0.6; }

.form-container {
    background: var(--ee-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--ee-border);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--ee-shadow);
}
.form-section { padding: 22px 26px; border-bottom: 1px solid var(--ee-border); }
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    flex-wrap: wrap;
    gap: 8px;
}
.section-header h3 {
    font-size: 14px;
    font-weight: 800;
    color: var(--ee-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-header h3 i { color: #F59E0B; font-size: 15px; }
.section-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--ee-text-secondary);
    background: var(--ee-hover);
    padding: 3px 12px;
    border-radius: 12px;
    text-transform: uppercase;
}
.section-hint {
    font-size: 12px;
    color: var(--ee-text-secondary);
    background: var(--ee-hover);
    padding: 10px 14px;
    border-radius: 8px;
    margin: 0 0 16px 0;
    border-left: 3px solid #F59E0B;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-hint i { color: #F59E0B; }

.providers-table-wrapper {
    overflow-x: auto;
    border-radius: 10px;
    border: 1.5px solid var(--ee-border);
    background: var(--ee-card-bg);
}
.providers-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
}
.providers-table thead { background: linear-gradient(135deg, #F59E0B, #D97706); }
.providers-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.providers-table thead th.text-right { text-align: right; }
.providers-table tbody tr { border-bottom: 1px solid var(--ee-border); }
.providers-table tbody tr:hover { background: var(--ee-hover); }
.providers-table tbody td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--ee-text);
    vertical-align: middle;
}
.providers-table tbody td.text-right { text-align: right; }

.provider-cell { display: flex; align-items: center; gap: 10px; }
.provider-icon-sm {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.provider-info-cell { display: flex; flex-direction: column; gap: 2px; }
.provider-name { font-size: 12px; font-weight: 700; color: var(--ee-text); }
.provider-code {
    font-size: 9px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 1px 6px;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    align-self: flex-start;
}
html.dark-mode .provider-code { background: #1E3A5F; color: #60A5FA; }

.amount-readonly {
    font-size: 12px;
    font-weight: 700;
    color: var(--ee-text-secondary);
    font-family: 'Courier New', monospace;
}
.amount-readonly.text-success { color: #10B981; }
.amount-readonly.text-danger { color: #DC2626; }

.money-input-table {
    width: 130px;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--ee-border);
    font-size: 13px;
    font-weight: 800;
    font-family: 'Inter', 'Courier New', monospace;
    text-align: right;
    color: #F59E0B;
    background: var(--ee-input-bg);
    outline: none;
    transition: all 0.3s ease;
}
.money-input-table:focus {
    border-color: #F59E0B;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
    background: var(--ee-card-bg);
}

.providers-table tfoot { background: var(--ee-hover); }
.providers-table tfoot td {
    padding: 14px;
    border-top: 2px solid var(--ee-border);
}
.totals-row td { background: linear-gradient(135deg, #FEF3C7, #FDE68A); }
html.dark-mode .totals-row td { background: linear-gradient(135deg, #5F3A1E, #78350F); }
.total-value {
    font-size: 15px;
    font-weight: 900;
    font-family: 'Courier New', monospace;
    color: #D97706;
}
html.dark-mode .total-value { color: #FCD34D; }
.grand-total-row td {
    background: linear-gradient(135deg, #F59E0B, #D97706);
    color: #FFFFFF;
}
.grand-total-row strong {
    color: #FFFFFF;
    font-size: 13px;
    letter-spacing: 1px;
}
.grand-total {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Courier New', monospace;
    color: #FFFFFF;
    text-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.form-row:last-child { margin-bottom: 0; }
.form-row .full-width { grid-column: span 2; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--ee-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.form-label i { color: #F59E0B; font-size: 13px; }
.form-label .required { color: #DC2626; }
.form-control {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1.5px solid var(--ee-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--ee-input-bg);
    color: var(--ee-text);
    font-weight: 500;
}
.form-control:focus {
    border-color: #F59E0B;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.12);
    background: var(--ee-card-bg);
}
.form-control.textarea-control { min-height: 80px; resize: vertical; line-height: 1.6; }

.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 26px;
    border-top: 1px solid var(--ee-border);
    background: var(--ee-hover);
    flex-wrap: wrap;
}
.btn {
    padding: 12px 26px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-submit {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}
.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.45);
    color: white;
}
.btn-reset, .btn-cancel {
    background: var(--ee-card-bg);
    color: var(--ee-text-secondary);
    border: 1.5px solid var(--ee-border);
}
.btn-reset:hover { background: var(--ee-border); color: var(--ee-text); }
.btn-cancel:hover { background: #FEE2E2; color: #991B1B; }

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 12px; }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .form-row .full-width { grid-column: span 1; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    .money-input-table { width: 110px; font-size: 12px; }
}
@media (max-width: 480px) {
    .money-input-table { width: 90px; font-size: 11px; }
}
</style>

<script>
function formatMoneyInput(input) {
    var value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);
    var formatted = '';
    var count = 0;
    for (var i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    updateTotals();
}

function updateTotals() {
    var totalFloat = 0;
    var totalCash = 0;
    document.querySelectorAll('input[name^="closing_float"]').forEach(function(input) {
        totalFloat += parseFloat(input.value.replace(/,/g, '')) || 0;
    });
    document.querySelectorAll('input[name^="closing_cash"]').forEach(function(input) {
        totalCash += parseFloat(input.value.replace(/,/g, '')) || 0;
    });
    var grandTotal = totalFloat + totalCash;
    document.getElementById('totalFloat').textContent = 'TSh ' + totalFloat.toLocaleString('en-US');
    document.getElementById('totalCash').textContent = 'TSh ' + totalCash.toLocaleString('en-US');
    document.getElementById('grandTotal').textContent = 'TSh ' + grandTotal.toLocaleString('en-US');
}

function validateForm() {
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.disabled = true;
    return true;
}
function confirmReset() {
    return confirm('Reset form?\n\nAny unsaved changes will be lost.');
}

document.addEventListener('DOMContentLoaded', function() {
    updateTotals();
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
});
</script>

</body>
</html>