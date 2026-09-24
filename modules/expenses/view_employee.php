<?php
// ================================================================
// FILE: modules/expenses/view_employee.php
// WAKALA FINANCIAL SYSTEM - VIEW MY EXPENSE (EMPLOYEE)
// ✅ RED theme
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

if ($role !== 'employee') {
    header('Location: view.php?id=' . intval($_GET['id'] ?? 0));
    exit();
}

$expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($expense_id <= 0) {
    $_SESSION['error_message'] = 'Invalid expense.';
    header('Location: index_employee.php');
    exit();
}

$stmt = $db->prepare("
    SELECT e.*, b.branch_name, b.branch_code, b.location as branch_location
    FROM expenses e
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE e.id = ? AND e.employee_id = ?
");
$stmt->execute([$expense_id, $user_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    $_SESSION['error_message'] = 'Expense not found or access denied.';
    header('Location: index_employee.php');
    exit();
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">My Branch</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($expense['branch_name'] ?? 'My Branch'); ?></span>
                <?php if (!empty($expense['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($expense['branch_code']); ?></span>
                <?php endif; ?>
            </div>
            <a href="index_employee.php" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
        </div>

        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-receipt"></i> Expense Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($expense['expense_number']); ?></span>
            </div>
        </div>

        <!-- HERO -->
        <div class="expense-hero">
            <div class="hero-icon-wrap">
                <i class="fas fa-receipt"></i>
            </div>
            <div class="hero-info-wrap">
                <span class="hero-label">EXPENSE AMOUNT</span>
                <span class="hero-amount"><?php echo formatCurrency($expense['amount']); ?></span>
                <div class="hero-meta">
                    <span class="hero-meta-item"><i class="fas fa-hashtag"></i><?php echo htmlspecialchars($expense['expense_number']); ?></span>
                    <span class="hero-meta-item"><i class="far fa-calendar"></i><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></span>
                    <span class="hero-meta-item"><i class="fas fa-tag"></i><?php echo htmlspecialchars($expense['category']); ?></span>
                </div>
            </div>
        </div>

        <!-- DETAILS -->
        <div class="details-card">
            <div class="section-title"><i class="fas fa-info-circle"></i> Information</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-tag"></i> Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($expense['expense_name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-tags"></i> Category</span>
                    <span class="info-value"><span class="category-badge"><i class="fas fa-tag"></i><?php echo htmlspecialchars($expense['category']); ?></span></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-calendar"></i> Date</span>
                    <span class="info-value"><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><i class="fas fa-briefcase"></i> Type</span>
                    <span class="info-value"><?php echo $expense['is_business_expense'] == 1 ? 'Business' : 'Personal'; ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($expense['description'])): ?>
        <div class="details-card">
            <div class="section-title"><i class="fas fa-align-left"></i> Description</div>
            <div class="description-box"><?php echo nl2br(htmlspecialchars($expense['description'])); ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($expense['notes'])): ?>
        <div class="details-card">
            <div class="section-title"><i class="fas fa-sticky-note"></i> Notes</div>
            <div class="notes-box"><?php echo nl2br(htmlspecialchars($expense['notes'])); ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($expense['receipt_path'])): ?>
        <div class="details-card">
            <div class="section-title"><i class="fas fa-paperclip"></i> Receipt</div>
            <div class="receipt-box">
                <?php 
                $ext = strtolower(pathinfo($expense['receipt_path'], PATHINFO_EXTENSION));
                $url = '../../' . $expense['receipt_path'];
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars($url); ?>" class="receipt-image">
                    </a>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="receipt-link">
                        <i class="fas fa-file-pdf"></i> View Receipt
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="view-actions">
            <a href="index_employee.php" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
<?php include __DIR__ . '/_styles_view.php'; ?>
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
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