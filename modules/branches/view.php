<?php
// ================================================================
// FILE: modules/branches/view.php
// WAKALA FINANCIAL SYSTEM - VIEW BRANCH
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
// GET BRANCH ID
// ============================================================
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH DATA
// ============================================================
$sql = "SELECT 
            b.*,
            e.full_name as manager_name
        FROM branches b
        LEFT JOIN employees e ON b.manager_id = e.id
        WHERE b.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

if (!$branch) {
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH PROVIDERS
// ============================================================
$stmt = $db->prepare("SELECT p.* FROM providers p 
                      JOIN branch_providers bp ON p.id = bp.provider_id 
                      WHERE bp.branch_id = ? AND p.is_active = 1 
                      ORDER BY p.display_order, p.provider_name");
$stmt->execute([$branch_id]);
$branch_providers = $stmt->fetchAll();

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
                <h2><i class="fas fa-store-alt"></i> Branch Details</h2>
                <span class="page-subtitle"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
                <a href="edit.php?id=<?php echo $branch_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <div class="view-container">
            
            <!-- Branch Info -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-info-circle"></i> Branch Information</h3>
                    <span class="status-badge <?php echo $branch['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                        <i class="fas <?php echo $branch['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                        <?php echo $branch['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>
                <div class="view-card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-tag"></i> Branch Code</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-store-alt"></i> Branch Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['location'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['phone'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['email'] ?? 'Not specified'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-user-tie"></i> Manager</span>
                            <span class="info-value"><?php echo htmlspecialchars($branch['manager_name'] ?? 'Not assigned'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-calendar-alt"></i> Created</span>
                            <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($branch['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-clock"></i> Last Updated</span>
                            <span class="info-value"><?php echo date('d M Y H:i:s', strtotime($branch['updated_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Branch Providers -->
            <div class="view-card">
                <div class="view-card-header">
                    <h3><i class="fas fa-university"></i> Branch Providers</h3>
                    <span class="provider-count"><?php echo count($branch_providers); ?> providers</span>
                </div>
                <div class="view-card-body">
                    <?php if (empty($branch_providers)): ?>
                        <p class="text-muted">No providers assigned to this branch.</p>
                    <?php else: ?>
                        <div class="provider-grid">
                            <?php foreach ($branch_providers as $provider): ?>
                                <div class="provider-item">
                                    <div class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                                        <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                    </div>
                                    <div class="provider-info">
                                        <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                        <span class="provider-code"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="view-actions">
                <a href="edit.php?id=<?php echo $branch_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Branch
                </a>
                <a href="delete.php?id=<?php echo $branch_id; ?>" class="btn btn-delete" onclick="return confirmDelete(<?php echo $branch_id; ?>, '<?php echo addslashes($branch['branch_name']); ?>')">
                    <i class="fas fa-trash"></i> Delete Branch
                </a>
            </div>
            
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

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

.view-card:last-child {
    border-bottom: none;
}

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
    color: #3B82F6;
    margin-right: 8px;
}

.view-card-body {
    padding: 20px;
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

.status-badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge i {
    margin-right: 4px;
}

.status-active {
    background: #D1FAE5;
    color: #065F46;
}

.status-inactive {
    background: #FEE2E2;
    color: #991B1B;
}

.provider-count {
    font-size: 12px;
    color: var(--view-text-secondary);
}

.provider-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.provider-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: var(--view-hover);
    border-radius: 8px;
    border: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.provider-item:hover {
    border-color: #3B82F6;
}

.provider-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 12px;
    flex-shrink: 0;
}

.provider-info {
    flex: 1;
}

.provider-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--view-text);
    display: block;
}

.provider-code {
    font-size: 10px;
    color: var(--view-text-light);
    text-transform: uppercase;
}

.text-muted {
    color: var(--view-text-light);
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
    
    .provider-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .provider-grid {
        grid-template-columns: 1fr;
    }
}

function confirmDelete(id, name) {
    return confirm('Are you sure you want to delete the branch "' + name + '"? This action cannot be undone.');
}
</style>
</body>
</html>