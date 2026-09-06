<?php
// ================================================================
// FILE: modules/employees/view.php
// WAKALA FINANCIAL SYSTEM - VIEW EMPLOYEE
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
// GET EMPLOYEE ID
// ============================================================
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($employee_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$sql = "SELECT 
            e.*,
            b.branch_name as branch_name
        FROM employees e
        LEFT JOIN branches b ON e.branch_id = b.id
        WHERE e.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();

if (!$employee) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET EMPLOYEE STATS
// ============================================================
// Count morning reports
$stmt = $db->prepare("SELECT COUNT(*) FROM morning_reports WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$morning_count = $stmt->fetchColumn();

// Count evening stocks
$stmt = $db->prepare("SELECT COUNT(*) FROM evening_stocks WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$evening_count = $stmt->fetchColumn();

// Count commissions
$stmt = $db->prepare("SELECT COUNT(*) FROM commissions WHERE employee_id = ?");
$stmt->execute([$employee_id]);
$commission_count = $stmt->fetchColumn();

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
                <h2><i class="fas fa-user-circle"></i> Employee Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($employee['full_name']); ?></span>
                <span class="employee-id-badge"><?php echo htmlspecialchars($employee['employee_id']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $employee_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <div class="view-container">
            
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar-large">
                    <?php if (!empty($employee['profile_pic'])): ?>
                        <img src="<?php echo htmlspecialchars($employee['profile_pic']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="avatar-placeholder" style="background: #3B82F6;">
                            <?php echo strtoupper(substr($employee['full_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h3><?php echo htmlspecialchars($employee['full_name']); ?></h3>
                    <div class="profile-meta">
                        <span class="profile-role"><?php echo ucfirst($employee['role']); ?></span>
                        <span class="profile-status <?php echo $employee['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                        <span class="profile-branch"><?php echo htmlspecialchars($employee['branch_name'] ?? 'Main'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-sun"></i></div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo number_format($morning_count); ?></span>
                        <span class="stat-label">Morning Reports</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-moon"></i></div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo number_format($evening_count); ?></span>
                        <span class="stat-label">Evening Stocks</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="stat-info">
                        <span class="stat-value"><?php echo number_format($commission_count); ?></span>
                        <span class="stat-label">Commissions</span>
                    </div>
                </div>
            </div>
            
            <!-- Employee Info -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-info-circle"></i> Personal Information</h3>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Full Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['full_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['phone'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-map-marker-alt"></i> Address</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['address'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user-tag"></i> Username</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['username']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user-shield"></i> Role</span>
                            <span class="info-value"><?php echo ucfirst($employee['role']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Employment Info -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-store-alt"></i> Branch</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['branch_name'] ?? 'Main'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-money-bill"></i> Base Salary</span>
                            <span class="info-value"><?php echo formatCurrency($employee['base_salary'] ?? 0); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-alt"></i> Hire Date</span>
                            <span class="info-value"><?php echo $employee['hire_date'] ? date('d M Y', strtotime($employee['hire_date'])) : 'Not specified'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user-check"></i> Employment Status</span>
                            <span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $employee['employment_status'] ?? 'active')); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Last Login</span>
                            <span class="info-value"><?php echo $employee['last_login'] ? date('d M Y H:i:s', strtotime($employee['last_login'])) : 'Never'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-check"></i> Joined</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($employee['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Emergency Contact -->
            <?php if (!empty($employee['emergency_contact']) || !empty($employee['emergency_phone'])): ?>
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-phone-alt"></i> Emergency Contact</h3>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user"></i> Contact Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['emergency_contact'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($employee['emergency_phone'] ?? 'Not specified'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div class="view-actions">
                <a href="edit.php?id=<?php echo $employee_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Employee
                </a>
                <a href="delete.php?id=<?php echo $employee_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $employee_id; ?>, '<?php echo addslashes($employee['full_name']); ?>')">
                    <i class="fas fa-trash"></i> Delete Employee
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
    color: #3B82F6;
    margin-right: 8px;
}

.page-subtitle {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 3px 12px;
    border-radius: 12px;
}

.employee-id-badge {
    font-size: 12px;
    font-weight: 600;
    color: #3B82F6;
    background: rgba(59,130,246,0.1);
    padding: 2px 12px;
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

.profile-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px;
    border-bottom: 1px solid var(--view-border);
    background: var(--view-card-header);
}

.profile-avatar-large {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
}

.profile-avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.avatar-placeholder {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 32px;
    font-weight: 700;
}

.profile-info h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--view-text);
    margin: 0 0 6px 0;
}

.profile-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.profile-role {
    font-size: 13px;
    font-weight: 600;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.profile-status {
    font-size: 13px;
    font-weight: 600;
    padding: 2px 12px;
    border-radius: 12px;
}

.profile-status.status-active {
    background: #D1FAE5;
    color: #065F46;
}

.profile-status.status-inactive {
    background: #FEE2E2;
    color: #991B1B;
}

.profile-branch {
    font-size: 13px;
    font-weight: 600;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--view-border);
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    background: var(--view-hover);
    border-radius: 10px;
    border: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.stat-card:hover {
    border-color: #3B82F6;
}

.stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #DBEAFE;
    color: #1D4ED8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.stat-info {
    flex: 1;
}

.stat-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--view-text);
    display: block;
}

.stat-label {
    font-size: 12px;
    color: var(--view-text-secondary);
}

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

.view-card-body {
    padding: 20px 24px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
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
    
    .profile-header {
        flex-direction: column;
        text-align: center;
    }
    
    .profile-meta {
        justify-content: center;
    }
    
    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        padding: 14px 16px;
    }
    
    .stats-grid .stat-card:last-child {
        grid-column: span 2;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
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
    .profile-avatar-large {
        width: 60px;
        height: 60px;
    }
    
    .avatar-placeholder {
        width: 60px;
        height: 60px;
        font-size: 24px;
    }
    
    .stat-card {
        padding: 10px 14px;
    }
    
    .stat-value {
        font-size: 17px;
    }
}

function confirmDelete(id, name) {
    return confirm('Are you sure you want to delete the employee "' + name + '"? This action cannot be undone.');
}
</style>
</body>
</html>