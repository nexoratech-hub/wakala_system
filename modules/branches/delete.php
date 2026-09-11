<?php
// ================================================================
// FILE: modules/branches/delete.php
// WAKALA FINANCIAL SYSTEM - DELETE BRANCH
// FIXED: Uses 'id' param, no variable collision with topbar
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

// Check permission - Only admin and super_admin can access
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET BRANCH ID
// ============================================================
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid branch selected.';
    header('Location: index.php');
    exit();
}

// ============================================================
// NO SESSION FORCING - URL IS SOURCE OF TRUTH
// ============================================================
unset($_SESSION['selected_branch']);

// ============================================================
// GET BRANCH DATA (uses $delete_branch to avoid collision)
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$delete_branch = $stmt->fetch();

if (!$delete_branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// CHECK IF BRANCH HAS DEPENDENT DATA
// ============================================================
$dependencies = [];
$can_delete = true;

try {
    // Check morning reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM morning_reports WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $morning_count = intval($stmt->fetchColumn());
    if ($morning_count > 0) {
        $dependencies[] = $morning_count . ' morning report' . ($morning_count != 1 ? 's' : '');
        $can_delete = false;
    }
    
    // Check evening stocks
    $stmt = $db->prepare("SELECT COUNT(*) FROM evening_stocks WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $evening_count = intval($stmt->fetchColumn());
    if ($evening_count > 0) {
        $dependencies[] = $evening_count . ' evening stock' . ($evening_count != 1 ? 's' : '');
        $can_delete = false;
    }
    
    // Check daily reports
    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_reports WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $daily_count = intval($stmt->fetchColumn());
    if ($daily_count > 0) {
        $dependencies[] = $daily_count . ' daily report' . ($daily_count != 1 ? 's' : '');
        $can_delete = false;
    }
    
    // Check employees
    $stmt = $db->prepare("SELECT COUNT(*) FROM employees WHERE branch_id = ? AND is_active = 1");
    $stmt->execute([$branch_id]);
    $employee_count = intval($stmt->fetchColumn());
    if ($employee_count > 0) {
        $dependencies[] = $employee_count . ' employee' . ($employee_count != 1 ? 's' : '');
        $can_delete = false;
    }
    
    // Check transactions
    $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $transaction_count = intval($stmt->fetchColumn());
    if ($transaction_count > 0) {
        $dependencies[] = $transaction_count . ' transaction' . ($transaction_count != 1 ? 's' : '');
        $can_delete = false;
    }
    
    // Check expenses
    $stmt = $db->prepare("SELECT COUNT(*) FROM expenses WHERE branch_id = ?");
    $stmt->execute([$branch_id]);
    $expense_count = intval($stmt->fetchColumn());
    if ($expense_count > 0) {
        $dependencies[] = $expense_count . ' expense' . ($expense_count != 1 ? 's' : '');
        $can_delete = false;
    }
    
} catch (Exception $e) {
    // If tables don't exist, proceed with deletion
    $can_delete = true;
}

// ============================================================
// IF CANNOT DELETE, REDIRECT WITH ERROR
// ============================================================
if (!$can_delete) {
    $_SESSION['error_message'] = 'Cannot delete branch "' . $delete_branch['branch_name'] . '" because it has: ' . implode(', ', $dependencies) . '. Please remove these first.';
    header('Location: index.php?branch_id=' . $branch_id);
    exit();
}

// ============================================================
// GET BRANCH PROVIDERS COUNT (for display)
// ============================================================
$stmt = $db->prepare("SELECT COUNT(*) FROM branch_providers WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$provider_count = intval($stmt->fetchColumn());

// ============================================================
// HANDLE DELETE CONFIRMATION
// ============================================================
$delete_confirmed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_delete') {
    try {
        // Delete branch providers first (foreign key)
        $stmt = $db->prepare("DELETE FROM branch_providers WHERE branch_id = ?");
        $stmt->execute([$branch_id]);
        
        // Delete user_providers for this branch (if any)
        try {
            $stmt = $db->prepare("DELETE FROM user_providers WHERE branch_id = ?");
            $stmt->execute([$branch_id]);
        } catch (Exception $e) {
            // Table might not exist or no records
        }
        
        // Log activity BEFORE deleting branch (foreign key constraint)
        logActivity($user_id, 'Delete Branch', 'Branches', $branch_id, '', 'Deleted branch: ' . $delete_branch['branch_name'] . ' (' . $delete_branch['branch_code'] . ')');
        
        // Delete branch
        $stmt = $db->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);
        
        $_SESSION['success_message'] = 'Branch "' . $delete_branch['branch_name'] . '" deleted successfully!';
        header('Location: index.php');
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error deleting branch: ' . $e->getMessage();
        header('Location: index.php?branch_id=' . $branch_id);
        exit();
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        PERSISTENT RED BRANCH STATUS CARD
        ============================================================ -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Deleting Branch</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($delete_branch['branch_name']); ?></span>
                <span class="branch-status-code"><?php echo htmlspecialchars($delete_branch['branch_code']); ?></span>
                <?php if (!empty($delete_branch['location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($delete_branch['location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="branch-status-stats">
                <div class="status-stat-item">
                    <span class="status-stat-number"><?php echo $provider_count; ?></span>
                    <span class="status-stat-label">Providers</span>
                </div>
            </div>
            <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Cancel</span>
            </a>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
                <span class="page-subtitle">This action cannot be undone</span>
            </div>
        </div>

        <!-- ============================================================
        WARNING CARD
        ============================================================ -->
        <div class="delete-warning-card">
            <div class="warning-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="warning-content">
                <h3>Are you sure you want to delete this branch?</h3>
                <p>
                    You are about to permanently delete the branch
                    <strong>"<?php echo htmlspecialchars($delete_branch['branch_name']); ?>"</strong>
                    (<?php echo htmlspecialchars($delete_branch['branch_code']); ?>).
                </p>
                
                <div class="warning-details">
                    <div class="warning-detail-item">
                        <i class="fas fa-info-circle"></i>
                        <span>This action <strong>cannot be undone</strong>.</span>
                    </div>
                    <?php if ($provider_count > 0): ?>
                        <div class="warning-detail-item">
                            <i class="fas fa-university"></i>
                            <span><strong><?php echo $provider_count; ?></strong> provider<?php echo $provider_count != 1 ? 's' : ''; ?> will be removed from this branch.</span>
                        </div>
                    <?php endif; ?>
                    <div class="warning-detail-item">
                        <i class="fas fa-check-circle"></i>
                        <span>All associated data has been verified and can be safely deleted.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        BRANCH INFO PREVIEW
        ============================================================ -->
        <div class="delete-info-card">
            <div class="delete-info-header">
                <h3><i class="fas fa-store-alt"></i> Branch Information</h3>
            </div>
            <div class="delete-info-body">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-tag"></i> Branch Code</span>
                        <span class="info-value"><?php echo htmlspecialchars($delete_branch['branch_code']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-store-alt"></i> Branch Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($delete_branch['branch_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                        <span class="info-value"><?php echo htmlspecialchars($delete_branch['location'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($delete_branch['phone'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                        <span class="info-value"><?php echo htmlspecialchars($delete_branch['email'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label"><i class="fas fa-calendar-alt"></i> Created</span>
                        <span class="info-value"><?php echo date('d M Y H:i', strtotime($delete_branch['created_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        DELETE ACTIONS
        ============================================================ -->
        <div class="delete-actions">
            <form method="POST" action="" class="delete-form">
                <input type="hidden" name="action" value="confirm_delete">
                
                <button type="submit" class="btn btn-confirm-delete" onclick="return finalConfirm()">
                    <i class="fas fa-trash-alt"></i> Yes, Delete Branch
                </button>
                <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-cancel-delete">
                    <i class="fas fa-times"></i> No, Cancel
                </a>
            </form>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --del-bg: #f3f4f6;
    --del-card-bg: #FFFFFF;
    --del-text: #1F2937;
    --del-text-secondary: #6B7280;
    --del-text-light: #9CA3AF;
    --del-border: #E5E7EB;
    --del-hover: #F3F4F6;
    --del-shadow: rgba(0,0,0,0.06);
    --del-danger-bg: #FEF2F2;
    --del-danger-border: #FECACA;
    --del-danger-text: #991B1B;
}

html.dark-mode {
    --del-bg: #0f172a;
    --del-card-bg: #1E293B;
    --del-text: #F1F5F9;
    --del-text-secondary: #94A3B8;
    --del-text-light: #64748B;
    --del-border: #334155;
    --del-hover: #2D3A4F;
    --del-shadow: rgba(0,0,0,0.3);
    --del-danger-bg: #450a0a;
    --del-danger-border: #7F1D1D;
    --del-danger-text: #FCA5A5;
}

body {
    background: var(--del-bg) !important;
    color: var(--del-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--del-bg) !important; }
.main-content { background: var(--del-bg) !important; }

/* ============================================================
   PERSISTENT RED BRANCH STATUS CARD
   ============================================================ */
.branch-status-card {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 18px 24px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative;
    overflow: hidden;
    animation: slideDown 0.3s ease forwards;
    flex-wrap: wrap;
}

.branch-status-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-card::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-status-icon {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;
    z-index: 1;
}

.branch-status-info {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
    flex: 1;
}

.branch-status-label {
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1px;
}

.branch-status-name {
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    letter-spacing: 0.3px;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

.branch-status-code {
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-status-location {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
}

.branch-status-stats {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;
    z-index: 1;
}

.status-stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.status-stat-number {
    font-size: 20px;
    font-weight: 700;
    color: #FFFFFF;
    line-height: 1.2;
}

.status-stat-label {
    font-size: 9px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.btn-back-card {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.3s ease;
    position: relative;
    z-index: 1;
}

.btn-back-card:hover {
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
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
    color: var(--del-text);
    margin: 0;
}

.page-header-left h2 i { color: #DC2626; margin-right: 8px; }

.page-subtitle {
    font-size: 13px;
    color: var(--del-text-secondary);
    background: var(--del-hover);
    padding: 3px 12px;
    border-radius: 12px;
}

/* ============================================================
   DELETE WARNING CARD
   ============================================================ */
.delete-warning-card {
    background: var(--del-danger-bg);
    border: 2px solid var(--del-danger-border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 16px;
    display: flex;
    gap: 20px;
    align-items: flex-start;
    animation: fadeInUp 0.4s ease forwards;
    position: relative;
    overflow: hidden;
}

.delete-warning-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 6px;
    height: 100%;
    background: #DC2626;
}

.warning-icon {
    width: 56px;
    height: 56px;
    background: #DC2626;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 24px;
    flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.35);
}

.warning-content {
    flex: 1;
}

.warning-content h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--del-danger-text);
    margin: 0 0 10px 0;
}

.warning-content > p {
    font-size: 14px;
    color: var(--del-danger-text);
    margin: 0 0 16px 0;
    line-height: 1.6;
}

.warning-content > p strong {
    font-weight: 700;
}

.warning-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 14px 16px;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 8px;
    border: 1px solid var(--del-danger-border);
}

html.dark-mode .warning-details {
    background: rgba(0, 0, 0, 0.2);
}

.warning-detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--del-danger-text);
    line-height: 1.5;
}

.warning-detail-item i {
    width: 16px;
    flex-shrink: 0;
    color: #DC2626;
}

.warning-detail-item strong {
    font-weight: 700;
}

/* ============================================================
   DELETE INFO CARD
   ============================================================ */
.delete-info-card {
    background: var(--del-card-bg);
    border: 1px solid var(--del-border);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px var(--del-shadow);
    animation: fadeInUp 0.45s ease forwards;
}

.delete-info-header {
    padding: 14px 20px;
    background: var(--del-hover);
    border-bottom: 1px solid var(--del-border);
}

.delete-info-header h3 {
    font-size: 15px;
    font-weight: 600;
    color: var(--del-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.delete-info-header h3 i {
    color: #DC2626;
}

.delete-info-body {
    padding: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 14px;
    background: var(--del-hover);
    border-radius: 8px;
    border: 1px solid var(--del-border);
}

.info-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--del-text-light);
    font-weight: 600;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.info-label i {
    color: #DC2626;
}

.info-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--del-text);
}

/* ============================================================
   DELETE ACTIONS
   ============================================================ */
.delete-actions {
    background: var(--del-card-bg);
    border: 1px solid var(--del-border);
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px var(--del-shadow);
    animation: fadeInUp 0.5s ease forwards;
}

.delete-form {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn {
    padding: 12px 28px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-confirm-delete {
    background: #DC2626;
    color: white;
}

.btn-confirm-delete:hover {
    background: #B91C1C;
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220,38,38,0.4);
}

.btn-confirm-delete:active {
    transform: translateY(0);
}

.btn-cancel-delete {
    background: var(--del-card-bg);
    color: var(--del-text-secondary);
    border: 1px solid var(--del-border);
}

.btn-cancel-delete:hover {
    background: var(--del-hover);
    color: var(--del-text);
    border-color: var(--del-text-light);
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .branch-status-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
    }
    
    .branch-status-info {
        width: 100%;
    }
    
    .branch-status-stats {
        width: 100%;
        justify-content: center;
    }
    
    .btn-back-card {
        width: 100%;
        justify-content: center;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .delete-warning-card {
        flex-direction: column;
        padding: 20px;
    }
    
    .warning-icon {
        width: 48px;
        height: 48px;
        font-size: 20px;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .delete-form {
        flex-direction: column;
    }
    
    .delete-form .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .branch-status-name {
        font-size: 16px;
    }
    
    .branch-status-icon {
        width: 44px;
        height: 44px;
        font-size: 18px;
    }
    
    .page-header-left h2 {
        font-size: 17px;
    }
    
    .warning-content h3 {
        font-size: 15px;
    }
    
    .warning-content > p {
        font-size: 13px;
    }
    
    .warning-detail-item {
        font-size: 12px;
    }
    
    .btn {
        padding: 10px 20px;
        font-size: 13px;
    }
}
</style>

<script>
// ============================================================
// FINAL CONFIRM - Double check before delete
// ============================================================
function finalConfirm() {
    return confirm(
        '⚠️ FINAL WARNING ⚠️\n\n' +
        'Are you ABSOLUTELY SURE you want to delete this branch?\n\n' +
        'Branch: <?php echo addslashes($delete_branch['branch_name']); ?>\n' +
        'Code: <?php echo addslashes($delete_branch['branch_code']); ?>\n\n' +
        'This action CANNOT be undone!'
    );
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