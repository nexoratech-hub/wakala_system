<?php
// ================================================================
// FILE: modules/daily_report/edit_provider.php
// EDIT PROVIDER (Daily Report Provider)
// ✅ FIXED: Ondoa `notes` kwenye UPDATE (haipo kwenye daily_report_providers)
// ✅ FIXED: Dark mode inatumia html.dark-mode
// ✅ NEW: Modern design na soft background cards
// ✅ NEW: Better validation na confirmation
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
try {
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
} catch (PDOException $e) {
    error_log("Error fetching provider: " . $e->getMessage());
    $_SESSION['error_message'] = 'Database error.';
    header('Location: index.php');
    exit();
}

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
        
        // Validation
        if ($new_morning_float < 0) {
            throw new Exception('Morning Float haiwezi kuwa negative.');
        }
        if ($new_current_float < 0) {
            throw new Exception('Current Float haiwezi kuwa negative.');
        }
        
        $old_morning_float = floatval($provider_data['morning_float'] ?? 0);
        $old_current_float = floatval($provider_data['current_float'] ?? 0);
        
        // ✅ FIXED: Ondoa `notes` (haipo kwenye daily_report_providers)
        $stmt = $db->prepare("
            UPDATE daily_report_providers 
            SET morning_float = ?,
                current_float = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $new_morning_float,
            $new_current_float,
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
try {
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
} catch (PDOException $e) {
    error_log("Error refreshing data: " . $e->getMessage());
}

// ============================================================
// GET RELATED TRANSACTIONS FOR THIS PROVIDER
// ============================================================
$recent_transactions = [];
try {
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
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

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
        CURRENT VALUES SUMMARY - SOFT BACKGROUND
        ============================================================ -->
        <div class="stats-grid-soft">
            <!-- Morning Float -->
            <div class="stat-card-soft stat-card-soft-morning">
                <div class="stat-icon-soft">
                    <i class="fas fa-sun"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Morning Float</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($provider_data['morning_float'] ?? 0); ?></span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Current Float -->
            <div class="stat-card-soft stat-card-soft-current">
                <div class="stat-icon-soft">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Current Float</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($provider_data['current_float'] ?? 0); ?></span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Total Deposits -->
            <div class="stat-card-soft stat-card-soft-deposit">
                <div class="stat-icon-soft">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Deposits</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($provider_data['total_deposits'] ?? 0); ?></span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
            
            <!-- Total Withdrawals -->
            <div class="stat-card-soft stat-card-soft-withdraw">
                <div class="stat-icon-soft">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stat-info-soft">
                    <span class="stat-label-soft">Total Withdrawals</span>
                    <span class="stat-value-soft"><?php echo formatCurrency($provider_data['total_withdrawals'] ?? 0); ?></span>
                </div>
                <div class="stat-decoration-soft"></div>
            </div>
        </div>

        <!-- ============================================================
        EDIT FORM
        ============================================================ -->
        <div class="form-container">
            <div class="form-header" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($provider_data['color_code'] ?? '#0B5ED7'); ?> 0%, <?php echo htmlspecialchars($provider_data['color_code'] ?? '#0B5ED7'); ?>dd 100%);">
                <div class="form-header-left">
                    <div class="form-header-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div>
                        <h3>Edit Provider Information</h3>
                        <p>Update float values for this provider</p>
                    </div>
                </div>
                <span class="form-badge">
                    <i class="fas fa-info-circle"></i>
                    Editable: Morning & Current Float
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
                        <span class="readonly-badge">
                            <i class="fas fa-lock"></i> Read-only
                        </span>
                    </div>
                    
                    <div class="provider-info-grid">
                        <div class="provider-info-card">
                            <div class="provider-info-icon">
                                <i class="fas fa-university"></i>
                            </div>
                            <div class="provider-info-content">
                                <span class="provider-info-label">Provider Name</span>
                                <span class="provider-info-value"><?php echo htmlspecialchars($provider_data['provider_name']); ?></span>
                            </div>
                        </div>
                        
                        <div class="provider-info-card">
                            <div class="provider-info-icon code-icon">
                                <i class="fas fa-tag"></i>
                            </div>
                            <div class="provider-info-content">
                                <span class="provider-info-label">Provider Code</span>
                                <span class="provider-info-value code-value"><?php echo htmlspecialchars($provider_data['provider_code']); ?></span>
                            </div>
                        </div>
                        
                        <div class="provider-info-card">
                            <div class="provider-info-icon type-icon">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div class="provider-info-content">
                                <span class="provider-info-label">Provider Type</span>
                                <span class="provider-info-value"><?php echo ucfirst(str_replace('_', ' ', $provider_data['provider_type'] ?? 'Bank')); ?></span>
                            </div>
                        </div>
                        
                        <div class="provider-info-card">
                            <div class="provider-info-icon user-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="provider-info-content">
                                <span class="provider-info-label">Created By</span>
                                <span class="provider-info-value"><?php echo htmlspecialchars($provider_data['employee_name'] ?? 'N/A'); ?></span>
                            </div>
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
                                <i class="fas fa-sun" style="color:#F59E0B;"></i>
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
                                <i class="fas fa-coins" style="color:#059669;"></i>
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
                    
                    <!-- Difference Indicator -->
                    <div class="difference-indicator" id="differenceIndicator" style="display:none;">
                        <div class="difference-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="difference-content">
                            <span class="difference-label">Float Difference</span>
                            <span class="difference-value" id="differenceValue">TSh 0</span>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
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
   STATS GRID - SOFT BACKGROUND
   ============================================================ */
.stats-grid-soft {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card-soft {
    position: relative;
    border-radius: 14px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.stat-card-soft:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
}

/* SOFT ORANGE - Morning Float */
.stat-card-soft-morning {
    background: rgba(245, 158, 11, 0.08);
    border-color: rgba(245, 158, 11, 0.2);
}
.stat-card-soft-morning .stat-icon-soft {
    background: rgba(245, 158, 11, 0.15);
    color: #D97706;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}
.stat-card-soft-morning .stat-value-soft { color: #B45309; }

/* SOFT BLUE - Current Float */
.stat-card-soft-current {
    background: rgba(37, 99, 235, 0.08);
    border-color: rgba(37, 99, 235, 0.2);
}
.stat-card-soft-current .stat-icon-soft {
    background: rgba(37, 99, 235, 0.15);
    color: #2563EB;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}
.stat-card-soft-current .stat-value-soft { color: #1D4ED8; }

/* SOFT GREEN - Deposits */
.stat-card-soft-deposit {
    background: rgba(5, 150, 105, 0.08);
    border-color: rgba(5, 150, 105, 0.2);
}
.stat-card-soft-deposit .stat-icon-soft {
    background: rgba(5, 150, 105, 0.15);
    color: #059669;
    border: 1.5px solid rgba(5, 150, 105, 0.3);
}
.stat-card-soft-deposit .stat-value-soft { color: #047857; }

/* SOFT RED - Withdrawals */
.stat-card-soft-withdraw {
    background: rgba(220, 38, 38, 0.08);
    border-color: rgba(220, 38, 38, 0.2);
}
.stat-card-soft-withdraw .stat-icon-soft {
    background: rgba(220, 38, 38, 0.15);
    color: #DC2626;
    border: 1.5px solid rgba(220, 38, 38, 0.3);
}
.stat-card-soft-withdraw .stat-value-soft { color: #B91C1C; }

/* Dark mode */
html.dark-mode .stat-card-soft-morning { background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.3); }
html.dark-mode .stat-card-soft-current { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .stat-card-soft-deposit { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .stat-card-soft-withdraw { background: rgba(220, 38, 38, 0.15); border-color: rgba(220, 38, 38, 0.3); }
html.dark-mode .stat-card-soft-morning .stat-value-soft { color: #FBBF24; }
html.dark-mode .stat-card-soft-current .stat-value-soft { color: #60A5FA; }
html.dark-mode .stat-card-soft-deposit .stat-value-soft { color: #34D399; }
html.dark-mode .stat-card-soft-withdraw .stat-value-soft { color: #FCA5A5; }

.stat-icon-soft {
    width: 50px;
    height: 50px;
    border-radius: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.stat-card-soft:hover .stat-icon-soft {
    transform: scale(1.08) rotate(-4deg);
}

.stat-info-soft {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}

.stat-label-soft {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-value-soft {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-word;
}

.stat-decoration-soft {
    position: absolute;
    top: -30px;
    right: -30px;
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    pointer-events: none;
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
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}

.form-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.form-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    position: relative;
    z-index: 1;
}

.form-header-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.2);
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
}

.form-header h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: #FFFFFF;
}

.form-header p {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.85);
    margin: 2px 0 0 0;
}

.form-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.18);
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: relative;
    z-index: 1;
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
    font-weight: 500;
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

.readonly-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: rgba(37, 99, 235, 0.15);
    color: #1D4ED8;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border: 1.5px solid rgba(37, 99, 235, 0.3);
}

html.dark-mode .readonly-badge {
    background: rgba(96, 165, 250, 0.2);
    color: #93C5FD;
    border-color: rgba(96, 165, 250, 0.4);
}

/* Provider Info Grid */
.provider-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.provider-info-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
}

.provider-info-card:hover {
    border-color: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
}

.provider-info-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid #93C5FD;
}

.provider-info-icon.code-icon {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    border-color: #C4B5FD;
}

.provider-info-icon.type-icon {
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    color: #D97706;
    border-color: #FCD34D;
}

.provider-info-icon.user-icon {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border-color: #6EE7B7;
}

html.dark-mode .provider-info-icon {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #60A5FA;
    border-color: #3B82F6;
}
html.dark-mode .provider-info-icon.code-icon {
    background: linear-gradient(135deg, #2D1B5F, #4C1D95);
    color: #C4B5FD;
    border-color: #A78BFA;
}
html.dark-mode .provider-info-icon.type-icon {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FBBF24;
    border-color: #F59E0B;
}
html.dark-mode .provider-info-icon.user-icon {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #34D399;
    border-color: #10B981;
}

.provider-info-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
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
    font-weight: 800;
    color: var(--text-primary);
    word-break: break-word;
}

.provider-info-value.code-value {
    font-family: 'Courier New', monospace;
    color: #7C3AED;
    background: #EDE9FE;
    padding: 2px 10px;
    border-radius: 6px;
    align-self: flex-start;
    font-size: 12px;
}

html.dark-mode .provider-info-value.code-value {
    background: #2D1B5F;
    color: #C4B5FD;
}

/* ============================================================
   FORM ROWS
   ============================================================ */
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
}

.form-group label {
    font-size: 12px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
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
    padding: 14px 18px 14px 44px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 16px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', 'Courier New', monospace;
    font-weight: 800;
    transition: all 0.25s ease;
    outline: none;
}

.form-control:focus {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    background: var(--bg-card);
}

.input-group:focus-within .input-icon {
    color: #2563EB;
}

.money-input {
    text-align: right;
    padding-right: 20px;
    font-size: 17px;
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

/* Difference Indicator */
.difference-indicator {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1.5px solid #FCD34D;
    border-radius: 10px;
    margin-top: 8px;
    animation: slideDown 0.3s ease;
}

html.dark-mode .difference-indicator {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #F59E0B;
}

.difference-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(245, 158, 11, 0.2);
    color: #D97706;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid rgba(245, 158, 11, 0.3);
}

html.dark-mode .difference-icon { color: #FBBF24; }

.difference-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}

.difference-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #78350F;
}

html.dark-mode .difference-label { color: #FCD34D; }

.difference-value {
    font-size: 17px;
    font-weight: 900;
    color: #78350F;
    font-family: 'Inter', 'Courier New', monospace;
}

html.dark-mode .difference-value { color: #FCD34D; }

.difference-value.positive { color: #059669; }
.difference-value.negative { color: #DC2626; }
html.dark-mode .difference-value.positive { color: #34D399; }
html.dark-mode .difference-value.negative { color: #FCA5A5; }

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    background: var(--bg-input);
    border-top: 1.5px solid var(--border-color);
    justify-content: flex-end;
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
    text-transform: uppercase;
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
}

.section-header h2 i { color: #2563EB; font-size: 17px; }

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

.txn-meta-item i { font-size: 10px; color: var(--text-muted); }

.txn-employee {
    background: #FEF3C7;
    color: #92400E;
    padding: 3px 10px;
    border-radius: 8px;
    font-weight: 700;
    border: 1px solid #FDE68A;
}

.txn-employee i { color: #D97706; font-size: 11px; }

html.dark-mode .txn-employee { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .txn-employee i { color: #FBBF24; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .stats-grid-soft { grid-template-columns: repeat(2, 1fr); }
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
    
    .stats-grid-soft { grid-template-columns: 1fr; }
    
    .provider-info-grid { grid-template-columns: 1fr; }
    
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
    .stat-value-soft { font-size: 16px; }
    .stat-icon-soft { width: 44px; height: 44px; font-size: 18px; }
    .form-control { font-size: 14px; }
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
        updateDifference();
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
    
    updateDifference();
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

function formatMoney(num) {
    return 'TSh ' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// UPDATE DIFFERENCE INDICATOR
// ============================================================
function updateDifference() {
    const morningInput = document.getElementById('morning_float');
    const currentInput = document.getElementById('current_float');
    const indicator = document.getElementById('differenceIndicator');
    const valueEl = document.getElementById('differenceValue');
    
    if (!morningInput || !currentInput || !indicator || !valueEl) return;
    
    const morningValue = parseMoney(morningInput.value);
    const currentValue = parseMoney(currentInput.value);
    
    if (morningValue > 0 || currentValue > 0) {
        const diff = currentValue - morningValue;
        indicator.style.display = 'flex';
        
        if (diff > 0) {
            valueEl.textContent = '+' + formatMoney(diff);
            valueEl.className = 'difference-value positive';
        } else if (diff < 0) {
            valueEl.textContent = formatMoney(diff);
            valueEl.className = 'difference-value negative';
        } else {
            valueEl.textContent = formatMoney(0);
            valueEl.className = 'difference-value';
        }
    } else {
        indicator.style.display = 'none';
    }
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
    const msg = 'Una uhakika unataka kuhifadhi mabadiliko haya?\n\n' +
                'Morning Float: ' + formatMoney(morningValue) + '\n' +
                'Current Float: ' + formatMoney(currentValue) + '\n\n' +
                'Continue?';
    
    if (!confirm(msg)) {
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
    
    // Initialize difference indicator
    updateDifference();
    
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
});
</script>

</body>
</html>