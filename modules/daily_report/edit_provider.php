<?php
// ================================================================
// FILE: modules/daily_report/edit_provider.php
// WAKALA FINANCIAL SYSTEM - EDIT PROVIDER (Daily Report Provider)
// ✅ Edit current_float, morning_float, notes for a provider in a report
// ✅ Shows provider info + all related data
// ✅ Full dark mode support
// ================================================================

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
// GET PARAMETERS
// ============================================================
$provider_row_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($provider_row_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDER ROW DATA
// ============================================================
$stmt = $db->prepare("
    SELECT 
        drp.*,
        p.provider_name,
        p.provider_code as main_code,
        p.provider_type,
        p.icon_class,
        p.color_code,
        dr.report_number,
        dr.report_date,
        dr.branch_id,
        dr.id as report_id,
        b.branch_name,
        b.branch_code,
        e.full_name as employee_name
    FROM daily_report_providers drp
    LEFT JOIN providers p ON drp.provider_id = p.id
    LEFT JOIN daily_reports dr ON drp.daily_report_id = dr.id
    LEFT JOIN branches b ON dr.branch_id = b.id
    LEFT JOIN employees e ON dr.employee_id = e.id
    WHERE drp.id = ?
");
$stmt->execute([$provider_row_id]);
$provider_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider_data) {
    $_SESSION['error_message'] = 'Provider record not found.';
    header('Location: index.php');
    exit();
}

// If branch_id not provided, get from data
if ($branch_id <= 0) {
    $branch_id = intval($provider_data['branch_id'] ?? 0);
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_provider') {
    try {
        $db->beginTransaction();
        
        $new_morning_float = floatval(str_replace(',', '', $_POST['morning_float'] ?? 0));
        $new_current_float = floatval(str_replace(',', '', $_POST['current_float'] ?? 0));
        $notes = trim($_POST['notes'] ?? '');
        
        // Validation
        if ($new_morning_float < 0) {
            throw new Exception('Morning Float haiwezi kuwa negative.');
        }
        if ($new_current_float < 0) {
            throw new Exception('Current Float haiwezi kuwa negative.');
        }
        
        $old_morning_float = floatval($provider_data['morning_float'] ?? 0);
        $old_current_float = floatval($provider_data['current_float'] ?? 0);
        
        // Update daily_report_providers
        $stmt = $db->prepare("
            UPDATE daily_report_providers 
            SET morning_float = ?,
                current_float = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $new_morning_float,
            $new_current_float,
            $notes,
            $provider_row_id
        ]);
        
        // Log activity
        logActivity(
            $user_id,
            'Edit Provider',
            'Daily Report Provider',
            $provider_row_id,
            'Morning Float: ' . number_format($old_morning_float, 0) . ' | Current Float: ' . number_format($old_current_float, 0),
            'Morning Float: ' . number_format($new_morning_float, 0) . ' | Current Float: ' . number_format($new_current_float, 0) . 
            ' | Provider: ' . $provider_data['provider_name']
        );
        
        $db->commit();
        
        $_SESSION['success_message'] = 'Provider "' . $provider_data['provider_name'] . '" imeupdate successfully!';
        header('Location: edit_provider.php?id=' . $provider_row_id . '&branch_id=' . $branch_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Refresh data after update
$stmt = $db->prepare("
    SELECT 
        drp.*,
        p.provider_name,
        p.provider_code as main_code,
        p.provider_type,
        p.icon_class,
        p.color_code,
        dr.report_number,
        dr.report_date,
        dr.branch_id,
        dr.id as report_id,
        b.branch_name,
        b.branch_code,
        e.full_name as employee_name
    FROM daily_report_providers drp
    LEFT JOIN providers p ON drp.provider_id = p.id
    LEFT JOIN daily_reports dr ON drp.daily_report_id = dr.id
    LEFT JOIN branches b ON dr.branch_id = b.id
    LEFT JOIN employees e ON dr.employee_id = e.id
    WHERE drp.id = ?
");
$stmt->execute([$provider_row_id]);
$provider_data = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// GET RELATED TRANSACTIONS FOR THIS PROVIDER
// ============================================================
$stmt = $db->prepare("
    SELECT 
        t.*,
        e.full_name as employee_name
    FROM transactions t
    LEFT JOIN employees e ON t.employee_id = e.id
    WHERE t.provider_id = ?
    AND t.branch_id = ?
    AND DATE(t.transaction_date) = ?
    ORDER BY t.created_at DESC
    LIMIT 10
");
$stmt->execute([
    $provider_data['provider_id'],
    $provider_data['branch_id'],
    $provider_data['report_date']
]);
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_deposits = 0;
$total_withdrawals = 0;
foreach ($recent_transactions as $t) {
    if ($t['transaction_type'] === 'deposit') {
        $total_deposits += floatval($t['amount']);
    } else {
        $total_withdrawals += floatval($t['amount']);
    }
}

// Session messages
$success_message_session = '';
$error_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message_session = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        PROVIDER HEADER CARD
        ============================================================ -->
        <div class="provider-header-card" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($provider_data['color_code'] ?? '#0B5ED7'); ?> 0%, <?php echo htmlspecialchars($provider_data['color_code'] ?? '#0B5ED7'); ?>dd 100%);">
            <div class="provider-header-left">
                <div class="provider-header-icon">
                    <i class="<?php echo htmlspecialchars($provider_data['icon_class'] ?? 'fas fa-university'); ?>"></i>
                </div>
                <div class="provider-header-info">
                    <span class="provider-header-label">Edit Provider</span>
                    <h1 class="provider-header-name"><?php echo htmlspecialchars($provider_data['provider_name']); ?></h1>
                    <div class="provider-header-meta">
                        <span class="provider-meta-item">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($provider_data['provider_code']); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-store-alt"></i>
                            <?php echo htmlspecialchars($provider_data['branch_name']); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d M Y', strtotime($provider_data['report_date'])); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-hashtag"></i>
                            <?php echo htmlspecialchars($provider_data['report_number']); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="provider-header-right">
                <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-header">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Reports</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
        MESSAGES
        ============================================================ -->
        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message_session); ?></span>
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
        CURRENT VALUES SUMMARY
        ============================================================ -->
        <div class="stats-grid">
            <div class="stat-card stat-card-morning">
                <div class="stat-card-icon">
                    <i class="fas fa-sun"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Morning Float</span>
                    <span class="stat-card-value"><?php echo formatCurrency($provider_data['morning_float'] ?? 0); ?></span>
                </div>
            </div>
            
            <div class="stat-card stat-card-current">
                <div class="stat-card-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Current Float</span>
                    <span class="stat-card-value"><?php echo formatCurrency($provider_data['current_float'] ?? 0); ?></span>
                </div>
            </div>
            
            <div class="stat-card stat-card-deposit">
                <div class="stat-card-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Deposits</span>
                    <span class="stat-card-value"><?php echo formatCurrency($provider_data['total_deposits'] ?? 0); ?></span>
                </div>
            </div>
            
            <div class="stat-card stat-card-withdraw">
                <div class="stat-card-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Withdrawals</span>
                    <span class="stat-card-value"><?php echo formatCurrency($provider_data['total_withdrawals'] ?? 0); ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        EDIT FORM
        ============================================================ -->
        <div class="form-container">
            <div class="form-header">
                <h3>
                    <i class="fas fa-edit"></i>
                    Edit Provider Information
                </h3>
                <span class="form-badge">
                    <i class="fas fa-info-circle"></i>
                    Update float values & notes
                </span>
            </div>
            
            <form method="POST" action="" class="edit-form" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="update_provider">
                
                <!-- Info Alert -->
                <div class="info-alert">
                    <div class="info-alert-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="info-alert-content">
                        <strong>Attention:</strong> Kubadilisha float itaathiri hesabu za reports. 
                        Hakikisha umejiridhisha na thamani mpya kabla ya ku-save.
                    </div>
                </div>
                
                <!-- Provider Info Display (Read-only) -->
                <div class="form-section">
                    <div class="section-header">
                        <h4>
                            <i class="fas fa-info-circle"></i>
                            Provider Information
                        </h4>
                    </div>
                    
                    <div class="provider-info-display">
                        <div class="provider-info-item">
                            <span class="provider-info-label">Provider Name</span>
                            <span class="provider-info-value"><?php echo htmlspecialchars($provider_data['provider_name']); ?></span>
                        </div>
                        <div class="provider-info-item">
                            <span class="provider-info-label">Provider Code</span>
                            <span class="provider-info-value code-value"><?php echo htmlspecialchars($provider_data['provider_code']); ?></span>
                        </div>
                        <div class="provider-info-item">
                            <span class="provider-info-label">Provider Type</span>
                            <span class="provider-info-value"><?php echo ucfirst(str_replace('_', ' ', $provider_data['provider_type'] ?? 'Bank')); ?></span>
                        </div>
                        <div class="provider-info-item">
                            <span class="provider-info-label">Employee</span>
                            <span class="provider-info-value"><?php echo htmlspecialchars($provider_data['employee_name'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Editable Fields -->
                <div class="form-section">
                    <div class="section-header">
                        <h4>
                            <i class="fas fa-sliders-h"></i>
                            Editable Fields
                        </h4>
                        <span class="section-badge">Required fields marked with *</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="morning_float">
                                Morning Float (TSh) <span class="required">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="fas fa-sun"></i>
                                </span>
                                <input type="text" 
                                       id="morning_float" 
                                       name="morning_float" 
                                       class="form-control money-input" 
                                       value="<?php echo number_format($provider_data['morning_float'] ?? 0, 0, '.', ','); ?>"
                                       placeholder="0"
                                       inputmode="numeric"
                                       oninput="formatMoneyInput(this)"
                                       required>
                            </div>
                            <small class="form-hint">
                                <i class="fas fa-info-circle"></i>
                                Float ya asubuhi (kabla ya transactions)
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="current_float">
                                Current Float (TSh) <span class="required">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="fas fa-coins"></i>
                                </span>
                                <input type="text" 
                                       id="current_float" 
                                       name="current_float" 
                                       class="form-control money-input" 
                                       value="<?php echo number_format($provider_data['current_float'] ?? 0, 0, '.', ','); ?>"
                                       placeholder="0"
                                       inputmode="numeric"
                                       oninput="formatMoneyInput(this)"
                                       required>
                            </div>
                            <small class="form-hint">
                                <i class="fas fa-info-circle"></i>
                                Float ya sasa (baada ya transactions)
                            </small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea id="notes" 
                                  name="notes" 
                                  class="form-control" 
                                  rows="4" 
                                  placeholder="Maelezo ya ziada kuhusu provider huyu..."><?php echo htmlspecialchars($provider_data['notes'] ?? ''); ?></textarea>
                        <small class="form-hint">
                            <i class="fas fa-info-circle"></i>
                            Optional notes kuhusu marekebisho
                        </small>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

        <!-- ============================================================
        RECENT TRANSACTIONS FOR THIS PROVIDER
        ============================================================ -->
        <?php if (count($recent_transactions) > 0): ?>
        <div class="transactions-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-history"></i>
                    Recent Transactions
                    <span class="section-count"><?php echo count($recent_transactions); ?></span>
                </h2>
                <a href="view_provider_transactions.php?provider_id=<?php echo $provider_data['provider_id']; ?>&branch_id=<?php echo $branch_id; ?>&report_id=<?php echo $provider_data['report_id']; ?>&report_date=<?php echo $provider_data['report_date']; ?>" 
                   class="btn-view-all">
                    <i class="fas fa-eye"></i> View All
                </a>
            </div>
            
            <div class="transactions-list">
                <?php foreach ($recent_transactions as $t): 
                    $is_deposit = $t['transaction_type'] === 'deposit';
                    $amount = floatval($t['amount'] ?? 0);
                    $created = $t['created_at'] ?? $t['transaction_date'];
                ?>
                    <div class="txn-item txn-item-<?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                        <div class="txn-item-icon">
                            <i class="fas fa-arrow-<?php echo $is_deposit ? 'down' : 'up'; ?>"></i>
                        </div>
                        <div class="txn-item-content">
                            <div class="txn-item-top">
                                <div class="txn-item-left">
                                    <span class="txn-badge txn-badge-<?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                                        <?php echo $is_deposit ? 'DEPOSIT' : 'WITHDRAWAL'; ?>
                                    </span>
                                    <span class="txn-number"><?php echo htmlspecialchars($t['transaction_number']); ?></span>
                                </div>
                                <div class="txn-item-amount txn-amount-<?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                                    <?php echo $is_deposit ? '+' : '-'; ?>
                                    <?php echo formatCurrency($amount); ?>
                                </div>
                            </div>
                            <div class="txn-item-bottom">
                                <span class="txn-meta-item">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('d M Y, h:i A', strtotime($created)); ?>
                                </span>
                                <span class="txn-meta-item txn-employee">
                                    <i class="fas fa-user-circle"></i>
                                    <strong><?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?></strong>
                                </span>
                                <?php if (!empty($t['reference_number'])): ?>
                                    <span class="txn-meta-item">
                                        <i class="fas fa-hashtag"></i>
                                        <?php echo htmlspecialchars($t['reference_number']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content {
    overflow-x: hidden !important; max-width: 100% !important;
    width: 100% !important; padding: 16px 20px !important;
}

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.12);
}

html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}

body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* ============================================================
   PROVIDER HEADER CARD
   ============================================================ */
.provider-header-card {
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}

.provider-header-card::before {
    content: '';
    position: absolute;
    top: -60%;
    right: -5%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.provider-header-left {
    display: flex;
    align-items: center;
    gap: 18px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}

.provider-header-icon {
    width: 68px;
    height: 68px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

.provider-header-info {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.provider-header-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.75);
}

.provider-header-name {
    font-size: 26px;
    font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.3px;
    line-height: 1.1;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}

.provider-header-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.provider-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(4px);
}

.btn-back-header {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.18);
    color: #FFFFFF;
    border-radius: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    transition: all 0.25s ease;
    backdrop-filter: blur(8px);
    position: relative;
    z-index: 1;
    white-space: nowrap;
}

.btn-back-header:hover {
    background: #FFFFFF;
    color: #1F2937;
    transform: translateX(-4px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    font-size: 13px;
    animation: slideDown 0.4s ease forwards;
    position: relative;
}

.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1.5px solid #A7F3D0;
}

.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FECACA;
}

html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }

.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
    transition: opacity 0.2s;
}

.alert-close:hover { opacity: 1; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   STATS GRID
   ============================================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px var(--shadow-color);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--shadow-hover);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
}

.stat-card-morning::before { background: #F59E0B; }
.stat-card-current::before { background: #1D4ED8; }
.stat-card-deposit::before { background: #059669; }
.stat-card-withdraw::before { background: #DC2626; }

.stat-card-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.stat-card-morning .stat-card-icon {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border: 1.5px solid #FBBF24;
}

.stat-card-current .stat-card-icon {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}

.stat-card-deposit .stat-card-icon {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border: 1.5px solid #6EE7B7;
}

.stat-card-withdraw .stat-card-icon {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #DC2626;
    border: 1.5px solid #FCA5A5;
}

html.dark-mode .stat-card-morning .stat-card-icon { background: linear-gradient(135deg, #5F3A1E, #78350F); color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .stat-card-current .stat-card-icon { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .stat-card-deposit .stat-card-icon { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }
html.dark-mode .stat-card-withdraw .stat-card-icon { background: linear-gradient(135deg, #7F1D1D, #991B1B); color: #FCA5A5; border-color: #DC2626; }

.stat-card-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.stat-card-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-card-value {
    font-size: 20px;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
}

/* ============================================================
   FORM CONTAINER
   ============================================================ */
.form-container {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

.form-header {
    padding: 18px 24px;
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.form-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.18);
    padding: 4px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Info Alert */
.info-alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    margin: 20px 24px 0 24px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1.5px solid #F59E0B;
    border-radius: 10px;
    color: #78350F;
    font-size: 13px;
}

html.dark-mode .info-alert {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #F59E0B;
    color: #FBBF24;
}

.info-alert-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(245, 158, 11, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.info-alert-content { flex: 1; line-height: 1.5; }

/* ============================================================
   FORM SECTIONS
   ============================================================ */
.form-section {
    padding: 20px 24px;
    border-bottom: 1.5px solid var(--border-color);
}

.form-section:last-of-type {
    border-bottom: none;
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.section-header h4 {
    font-size: 14px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.section-header h4 i { color: #2563EB; }

.section-badge {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    background: var(--bg-input);
    padding: 3px 12px;
    border-radius: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Provider Info Display */
.provider-info-display {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.provider-info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 14px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
}

.provider-info-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.provider-info-value {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    word-break: break-word;
}

.provider-info-value.code-value {
    font-family: 'Courier New', monospace;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 2px 10px;
    border-radius: 6px;
    align-self: flex-start;
    font-size: 12px;
}

html.dark-mode .provider-info-value.code-value {
    background: #1E3A5F;
    color: #60A5FA;
}

/* Form Rows */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 16px;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.form-group label .required { color: #DC2626; }

.input-group {
    position: relative;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 14px;
    color: var(--text-muted);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
    transition: color 0.25s ease;
}

.form-control {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 14px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', 'Courier New', monospace;
    font-weight: 700;
    transition: all 0.25s ease;
    outline: none;
}

textarea.form-control {
    padding: 12px 14px;
    font-family: 'Inter', sans-serif;
    font-weight: 500;
    resize: vertical;
    min-height: 90px;
    line-height: 1.5;
}

.form-control:focus {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    background: var(--bg-card);
}

.form-control:focus + .input-icon,
.input-group:focus-within .input-icon {
    color: #2563EB;
}

.money-input {
    text-align: right;
    padding-right: 18px;
    font-size: 16px;
    letter-spacing: 0.5px;
}

.form-hint {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 2px;
}

.form-hint i { color: #2563EB; font-size: 11px; }

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    background: var(--bg-input);
    border-top: 1.5px solid var(--border-color);
    flex-wrap: wrap;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-weight: 800;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    letter-spacing: 0.3px;
}

.btn-submit {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
    color: #FFFFFF;
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-reset {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.btn-reset:hover {
    background: var(--bg-body);
    color: var(--text-primary);
    transform: translateY(-2px);
}

.btn-cancel {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FECACA;
}

.btn-cancel:hover {
    background: #991B1B;
    color: #FFFFFF;
    transform: translateY(-2px);
}

html.dark-mode .btn-cancel { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }
html.dark-mode .btn-cancel:hover { background: #991B1B; color: #FFFFFF; }

/* ============================================================
   TRANSACTIONS SECTION
   ============================================================ */
.transactions-section {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 20px 22px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.section-header h2 {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: 0.2px;
}

.section-header h2 i {
    color: #2563EB;
    font-size: 17px;
}

.section-count {
    font-size: 12px;
    font-weight: 800;
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 4px 12px;
    border-radius: 12px;
    margin-left: 6px;
}

html.dark-mode .section-count { background: #1E3A5F; color: #60A5FA; }

.btn-view-all {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
    transition: all 0.25s ease;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    white-space: nowrap;
}

.btn-view-all:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45);
    color: #FFFFFF;
}

.transactions-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.txn-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    position: relative;
    overflow: hidden;
    transition: all 0.25s ease;
}

.txn-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 4px;
    height: 100%;
}

.txn-item-deposit::before { background: #10B981; }
.txn-item-withdrawal::before { background: #EF4444; }

.txn-item:hover {
    background: var(--bg-card);
    transform: translateX(4px);
    box-shadow: 0 6px 16px var(--shadow-color);
}

.txn-item-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    border: 2px solid;
}

.txn-item-deposit .txn-item-icon {
    background: linear-gradient(135deg, #DCFCE7, #BBF7D0);
    color: #15803D;
    border-color: #10B981;
}

.txn-item-withdrawal .txn-item-icon {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border-color: #EF4444;
}

html.dark-mode .txn-item-deposit .txn-item-icon {
    background: linear-gradient(135deg, #14532D, #166534);
    color: #4ADE80;
}

html.dark-mode .txn-item-withdrawal .txn-item-icon {
    background: linear-gradient(135deg, #7F1D1D, #991B1B);
    color: #FCA5A5;
}

.txn-item-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.txn-item-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.txn-item-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    min-width: 0;
}

.txn-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1px;
    white-space: nowrap;
}

.txn-badge-deposit {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #10B981;
}

.txn-badge-withdrawal {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #EF4444;
}

html.dark-mode .txn-badge-deposit { background: #14532D; color: #4ADE80; border-color: #10B981; }
html.dark-mode .txn-badge-withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #EF4444; }

.txn-number {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 800;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 3px 10px;
    border-radius: 6px;
    white-space: nowrap;
}

html.dark-mode .txn-number { background: #1E3A5F; color: #60A5FA; }

.txn-item-amount {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 16px;
    font-weight: 900;
    white-space: nowrap;
    letter-spacing: -0.3px;
}

.txn-amount-deposit { color: #15803D; }
.txn-amount-withdrawal { color: #991B1B; }
html.dark-mode .txn-amount-deposit { color: #4ADE80; }
html.dark-mode .txn-amount-withdrawal { color: #FCA5A5; }

.txn-item-bottom {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.txn-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.txn-meta-item i {
    font-size: 10px;
    color: var(--text-muted);
}

.txn-employee {
    background: #FEF3C7;
    color: #92400E;
    padding: 3px 10px;
    border-radius: 8px;
    font-weight: 700;
    border: 1px solid #FDE68A;
}

.txn-employee i {
    color: #D97706;
    font-size: 11px;
}

html.dark-mode .txn-employee { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .txn-employee i { color: #FBBF24; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 768px) {
    .provider-header-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px;
    }
    
    .provider-header-name { font-size: 20px; }
    .provider-header-icon { width: 56px; height: 56px; font-size: 22px; }
    .btn-back-header { width: 100%; justify-content: center; }
    
    .stats-grid { grid-template-columns: 1fr; }
    
    .provider-info-display { grid-template-columns: 1fr; }
    
    .form-row { grid-template-columns: 1fr; gap: 12px; }
    
    .form-section { padding: 16px 18px; }
    .form-actions { padding: 16px 18px; flex-direction: column; }
    .form-actions .btn { width: 100%; justify-content: center; }
    
    .info-alert { margin: 16px 18px 0 18px; }
    
    .section-header { flex-direction: column; align-items: flex-start; }
    .txn-item-top { flex-direction: column; align-items: flex-start; }
    .txn-item-amount { font-size: 14px; }
}

@media (max-width: 480px) {
    .provider-header-name { font-size: 17px; }
    .provider-meta-item { font-size: 10px; padding: 3px 9px; }
    .stat-card-value { font-size: 17px; }
    .stat-card-icon { width: 42px; height: 42px; font-size: 17px; }
    .form-control { font-size: 13px; }
    .money-input { font-size: 15px; }
    .btn { font-size: 12px; padding: 10px 18px; }
}
</style>

<script>
// ============================================================
// MONEY FORMAT INPUT
// ============================================================
function formatMoneyInput(input) {
    const cursorPos = input.selectionStart;
    const oldValue = input.value;
    const oldLength = oldValue.length;
    
    let value = input.value.replace(/[^0-9]/g, '');
    
    if (value === '') {
        input.value = '';
        return;
    }
    
    value = value.replace(/^0+/, '') || '0';
    
    if (value.length > 15) {
        value = value.substring(0, 15);
    }
    
    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) {
            formatted = ',' + formatted;
        }
        formatted = value[i] + formatted;
        count++;
    }
    
    input.value = formatted;
    
    const newLength = formatted.length;
    const newCursorPos = cursorPos + (newLength - oldLength);
    try {
        input.setSelectionRange(newCursorPos, newCursorPos);
    } catch (e) { }
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

function formatMoney(num) {
    return 'TSh ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    const morningInput = document.getElementById('morning_float');
    const currentInput = document.getElementById('current_float');
    
    const morningValue = parseMoney(morningInput.value);
    const currentValue = parseMoney(currentInput.value);
    
    if (morningValue < 0) {
        alert('Morning Float haiwezi kuwa negative.');
        morningInput.focus();
        return false;
    }
    
    if (currentValue < 0) {
        alert('Current Float haiwezi kuwa negative.');
        currentInput.focus();
        return false;
    }
    
    // Confirmation
    if (!confirm('Una uhakika unataka kuhifadhi mabadiliko haya?\n\nMorning Float: ' + formatMoney(morningValue) + '\nCurrent Float: ' + formatMoney(currentValue))) {
        return false;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    return true;
}

// ============================================================
// CONFIRM RESET
// ============================================================
function confirmReset() {
    return confirm('Una uhakika unataka ku-reset form?\n\nMabadiliko yote yatapotea.');
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    // Auto-hide success alert
    var successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.transition = 'opacity 0.4s ease';
            successAlert.style.opacity = '0';
            setTimeout(function() {
                if (successAlert.parentElement) successAlert.remove();
            }, 400);
        }, 6000);
    }
    
    console.log('%c✏️ Edit Provider', 'font-size:16px; font-weight:bold; color:#2563EB;');
    console.log('%cProvider: <?php echo htmlspecialchars($provider_data["provider_name"]); ?>', 'font-size:13px; color:#2563EB;');
    console.log('%cMorning Float: <?php echo formatCurrency($provider_data["morning_float"] ?? 0); ?>', 'font-size:13px; color:#D97706;');
    console.log('%cCurrent Float: <?php echo formatCurrency($provider_data["current_float"] ?? 0); ?>', 'font-size:13px; color:#059669;');
});
</script>

</body>
</html>