<?php
// ================================================================
// FILE: modules/expenses/view.php
// WAKALA FINANCIAL SYSTEM - VIEW EXPENSE
// ✅ RED theme only
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
$is_admin = ($role === 'admin' || $role === 'super_admin');

$expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($expense_id <= 0) {
    $_SESSION['error_message'] = 'Invalid expense selected.';
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("
    SELECT e.*, 
           emp.full_name as employee_name, 
           emp.employee_id as employee_code,
           emp.profile_pic as employee_avatar,
           b.branch_name as branch_display_name, 
           b.branch_code,
           b.location as branch_location
    FROM expenses e
    LEFT JOIN employees emp ON e.employee_id = emp.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.id = ?
");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    $_SESSION['error_message'] = 'Expense not found.';
    header('Location: index.php');
    exit();
}

// Employee can only view their own branch
if (!$is_admin && $expense['employee_id'] != $user_id) {
    $_SESSION['error_message'] = 'You do not have permission to view this expense.';
    header('Location: index_employee.php');
    exit();
}

$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }

$branch_qs = ($expense['branch_id'] > 0) ? '?branch_id=' . $expense['branch_id'] : '';
$back_url = $is_admin ? 'index.php' : 'index_employee.php';

if ($is_admin) {
    include_once '../../includes/admin_header.php';
    include_once '../../includes/admin_sidebar.php';
    include_once '../../includes/admin_topbar.php';
} else {
    include_once '../../includes/employee_header.php';
    include_once '../../includes/employee_sidebar.php';
    include_once '../../includes/employee_topbar.php';
}
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- RED BRANCH CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label"><?php echo $is_admin ? 'Expense Branch' : 'My Branch'; ?></span>
                <span class="branch-status-name"><?php echo htmlspecialchars($expense['branch_display_name'] ?? $expense['branch'] ?? 'Main'); ?></span>
                <?php if (!empty($expense['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($expense['branch_code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($expense['branch_location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($expense['branch_location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="<?php echo $back_url . $branch_qs; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-receipt"></i> Expense Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($expense['expense_number']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="edit.php?id=<?php echo $expense_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <?php if ($is_admin): ?>
                <a href="delete.php?id=<?php echo $expense_id; ?>" 
                   class="btn btn-delete" 
                   onclick="return confirmDelete('<?php echo addslashes($expense['expense_number']); ?>')">
                    <i class="fas fa-trash"></i> Delete
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- HERO CARD -->
        <div class="expense-hero">
            <div class="hero-icon-wrap">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="hero-info-wrap">
                <span class="hero-label">EXPENSE AMOUNT</span>
                <span class="hero-amount"><?php echo formatCurrency($expense['amount']); ?></span>
                <div class="hero-meta">
                    <span class="hero-meta-item">
                        <i class="fas fa-hashtag"></i>
                        <?php echo htmlspecialchars($expense['expense_number']); ?>
                    </span>
                    <span class="hero-meta-item">
                        <i class="far fa-calendar"></i>
                        <?php echo date('d M Y', strtotime($expense['expense_date'])); ?>
                    </span>
                    <span class="hero-meta-item badge-<?php echo $expense['is_business_expense'] == 1 ? 'business' : 'personal'; ?>">
                        <i class="fas <?php echo $expense['is_business_expense'] == 1 ? 'fa-briefcase' : 'fa-user'; ?>"></i>
                        <?php echo $expense['is_business_expense'] == 1 ? 'Business' : 'Personal / Other'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- DETAILS CARD -->
        <div class="details-card">
            <div class="section-title">
                <i class="fas fa-info-circle"></i> Expense Information
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-tag"></i> Expense Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($expense['expense_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-tags"></i> Category</span>
                    <span class="info-value">
                        <span class="category-badge">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($expense['category']); ?>
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-calendar-alt"></i> Date</span>
                    <span class="info-value"><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                    <span class="info-value"><?php echo htmlspecialchars($expense['branch_display_name'] ?? $expense['branch'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-user"></i> Employee</span>
                    <span class="info-value"><?php echo htmlspecialchars($expense['employee_name'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-clock"></i> Created</span>
                    <span class="info-value"><?php echo date('d M Y H:i', strtotime($expense['created_at'])); ?></span>
                </div>
            </div>
        </div>

        <!-- DESCRIPTION -->
        <?php if (!empty($expense['description'])): ?>
        <div class="details-card">
            <div class="section-title">
                <i class="fas fa-align-left"></i> Description
            </div>
            <div class="description-box">
                <?php echo nl2br(htmlspecialchars($expense['description'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- RECEIPT -->
        <?php if (!empty($expense['receipt_path'])): ?>
        <div class="details-card">
            <div class="section-title">
                <i class="fas fa-paperclip"></i> Receipt Attachment
            </div>
            <div class="receipt-box">
                <?php 
                $ext = strtolower(pathinfo($expense['receipt_path'], PATHINFO_EXTENSION));
                $receipt_url = '../../' . $expense['receipt_path'];
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): 
                ?>
                    <a href="<?php echo htmlspecialchars($receipt_url); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars($receipt_url); ?>" alt="Receipt" class="receipt-image">
                    </a>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($receipt_url); ?>" target="_blank" class="receipt-link">
                        <i class="fas fa-file-pdf"></i>
                        <span>View Receipt (<?php echo strtoupper($ext); ?>)</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- NOTES -->
        <?php if (!empty($expense['notes'])): ?>
        <div class="details-card">
            <div class="section-title">
                <i class="fas fa-sticky-note"></i> Notes
            </div>
            <div class="notes-box">
                <?php echo nl2br(htmlspecialchars($expense['notes'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACTIONS -->
        <div class="view-actions">
            <a href="<?php echo $back_url . $branch_qs; ?>" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="edit.php?id=<?php echo $expense_id; ?>" class="btn btn-edit">
                <i class="fas fa-edit"></i> Edit
            </a>
            <?php if ($is_admin): ?>
            <a href="delete.php?id=<?php echo $expense_id; ?>" 
               class="btn btn-delete" 
               onclick="return confirmDelete('<?php echo addslashes($expense['expense_number']); ?>')">
                <i class="fas fa-trash"></i> Delete
            </a>
            <?php endif; ?>
        </div>

    </div>
    <?php 
    if ($is_admin) include_once '../../includes/admin_footer.php';
    else include_once '../../includes/employee_footer.php';
    ?>
</div>

<style>
:root {
    --exp-bg: #f3f4f6;
    --exp-card-bg: #FFFFFF;
    --exp-text: #1F2937;
    --exp-text-secondary: #6B7280;
    --exp-text-light: #9CA3AF;
    --exp-border: #E5E7EB;
    --exp-hover: #F3F4F6;
    --exp-shadow: rgba(0,0,0,0.06);
}
html.dark-mode {
    --exp-bg: #0f172a;
    --exp-card-bg: #1E293B;
    --exp-text: #F1F5F9;
    --exp-text-secondary: #94A3B8;
    --exp-text-light: #64748B;
    --exp-border: #334155;
    --exp-hover: #2D3A4F;
    --exp-shadow: rgba(0,0,0,0.3);
}
* { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; }
.main-content { padding: 16px 20px !important; max-width: 100% !important; overflow-x: hidden !important; }
body { background: var(--exp-bg) !important; color: var(--exp-text); }
.main-wrapper, .main-content { background: var(--exp-bg) !important; }

.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative; overflow: hidden;
    flex-wrap: wrap; color: #FFFFFF;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1;
    position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name { font-size: 18px; font-weight: 700; color: #FFFFFF; }
.branch-status-code {
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
}
.branch-status-location {
    display: flex; align-items: center; gap: 4px;
    font-size: 12px; color: rgba(255, 255, 255, 0.7);
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; }

.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 16px;
    padding: 0 4px; flex-wrap: wrap; gap: 12px;
}
.page-header-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 20px; font-weight: 700;
    color: var(--exp-text); margin: 0;
}
.page-header-left h2 i { color: #DC2626; margin-right: 8px; }
.page-subtitle {
    font-size: 13px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 3px 12px; border-radius: 12px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
}
.page-header-right { display: flex; gap: 10px; flex-wrap: wrap; }

.btn {
    padding: 9px 20px; border-radius: 8px;
    font-weight: 600; font-size: 13px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; white-space: nowrap;
}
.btn-edit { background: #FEF3C7; color: #D97706; }
.btn-edit:hover { background: #FDE68A; transform: translateY(-1px); }
html.dark-mode .btn-edit { background: #5F3A1E; color: #FBBF24; }
.btn-delete { background: #FEE2E2; color: #DC2626; }
.btn-delete:hover { background: #FECACA; transform: translateY(-1px); }
html.dark-mode .btn-delete { background: #7F1D1D; color: #FCA5A5; }
.btn-back { background: var(--exp-hover); color: var(--exp-text-secondary); }
.btn-back:hover { background: var(--exp-border); color: var(--exp-text); }

.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 16px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none;
    font-size: 20px; color: inherit;
    cursor: pointer; padding: 0 4px; opacity: 0.6;
}

/* HERO CARD */
.expense-hero {
    display: flex; align-items: center; gap: 24px;
    padding: 28px 32px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(220, 38, 38, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative; overflow: hidden;
}
.expense-hero::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.hero-icon-wrap {
    width: 80px; height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px; color: #FCD34D;
    flex-shrink: 0; position: relative; z-index: 1;
    border: 2px solid rgba(255, 255, 255, 0.3);
}
.hero-info-wrap {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 6px;
    position: relative; z-index: 1;
}
.hero-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.hero-amount {
    font-size: clamp(28px, 3vw, 42px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px;
    line-height: 1.1;
    word-break: break-word;
    color: #FCD34D;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
}
.hero-meta {
    display: flex; align-items: center;
    gap: 10px; flex-wrap: wrap;
    margin-top: 6px;
}
.hero-meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.badge-business { background: rgba(252, 211, 77, 0.25) !important; color: #FCD34D !important; }
.badge-personal { background: rgba(255, 255, 255, 0.15) !important; }

/* DETAILS CARD */
.details-card {
    background: var(--exp-card-bg);
    border-radius: 12px;
    border: 1px solid var(--exp-border);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 1px 3px var(--exp-shadow);
}
.section-title {
    font-size: 13px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: var(--exp-text-secondary);
    padding: 16px 22px 12px;
    border-bottom: 2px solid var(--exp-border);
    display: flex; align-items: center; gap: 8px;
    background: var(--exp-hover);
}
.section-title i { color: #DC2626; font-size: 14px; }

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;
}
.info-item {
    padding: 16px 22px;
    display: flex; flex-direction: column; gap: 6px;
    border-bottom: 1px solid var(--exp-border);
    border-right: 1px solid var(--exp-border);
    min-width: 0;
}
.info-item:nth-child(2n) { border-right: none; }
.info-item:nth-last-child(-n+2) { border-bottom: none; }
.info-label {
    font-size: 10px; font-weight: 700;
    color: var(--exp-text-light);
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 5px;
}
.info-label i { color: #DC2626; font-size: 11px; }
.info-value {
    font-size: 14px; font-weight: 700;
    color: var(--exp-text);
    word-break: break-word;
}
.category-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    border: 1px solid #FECACA;
}
html.dark-mode .category-badge { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

.description-box {
    padding: 18px 22px;
    font-size: 14px; line-height: 1.7;
    color: var(--exp-text);
    background: var(--exp-card-bg);
}
.notes-box {
    padding: 18px 22px;
    font-size: 14px; line-height: 1.7;
    background: #FEF3C7;
    color: #78350F;
    border-left: 4px solid #F59E0B;
}
html.dark-mode .notes-box { background: #5F3A1E; color: #FDE68A; border-left-color: #FBBF24; }

.receipt-box { padding: 18px 22px; }
.receipt-image {
    max-width: 100%;
    max-height: 400px;
    border-radius: 8px;
    border: 2px solid var(--exp-border);
    display: block;
}
.receipt-link {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 12px 20px;
    background: #FEE2E2; color: #DC2626;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700; font-size: 14px;
    border: 1.5px solid #FECACA;
    transition: all 0.3s ease;
}
.receipt-link:hover { background: #FECACA; transform: translateY(-1px); }
.receipt-link i { font-size: 20px; }
html.dark-mode .receipt-link { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

.view-actions {
    display: flex; gap: 12px;
    padding: 16px 20px;
    background: var(--exp-card-bg);
    border-radius: 12px;
    border: 1px solid var(--exp-border);
    box-shadow: 0 1px 3px var(--exp-shadow);
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 12px; }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header-right { width: 100%; flex-direction: column; }
    .page-header-right .btn { width: 100%; justify-content: center; }
    .expense-hero { flex-direction: column; align-items: flex-start; padding: 20px 22px; }
    .hero-icon-wrap { width: 60px; height: 60px; font-size: 26px; }
    .info-grid { grid-template-columns: 1fr; }
    .info-item { border-right: none !important; }
    .info-item:last-child { border-bottom: none; }
    .view-actions { flex-direction: column; }
    .view-actions .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-name { font-size: 15px; }
    .branch-status-icon { width: 44px; height: 44px; font-size: 18px; }
    .page-header-left h2 { font-size: 17px; }
    .hero-amount { font-size: 24px; }
    .expense-hero { padding: 18px 18px; }
}
</style>

<script>
function confirmDelete(ref) {
    return confirm('Are you sure you want to delete this expense?\n\nRef: ' + ref + '\n\nThis action cannot be undone.');
}
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    var sa = document.querySelector('.alert-success');
    if (sa) setTimeout(function() { sa.style.display = 'none'; }, 5000);
    var ea = document.querySelector('.alert-danger');
    if (ea) setTimeout(function() { ea.style.display = 'none'; }, 8000);
});
</script>
</body>
</html>