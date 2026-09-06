<?php
// ================================================================
// FILE: modules/providers/view.php
// WAKALA FINANCIAL SYSTEM - VIEW PROVIDER
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
// CHECK PERMISSION - Only admin and super_admin can access
// ============================================================
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET PROVIDER ID
// ============================================================
$provider_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($provider_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDER DATA
// ============================================================
$sql = "SELECT 
            p.*,
            e.full_name as created_by_name
        FROM providers p
        LEFT JOIN employees e ON p.created_by = e.id
        WHERE p.id = ?";

$stmt = $db->prepare($sql);
$stmt->execute([$provider_id]);
$provider = $stmt->fetch();

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-eye"></i> Provider Details</h2>
                <span class="record-count"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Providers
                </a>
                <a href="edit.php?id=<?php echo $provider_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <!-- ============================================================
        PROVIDER DETAILS CARD
        ============================================================ -->
        <div class="view-container">
            
            <!-- ===== HEADER WITH ICON ===== -->
            <div class="provider-header" style="border-left: 6px solid <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                <div class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>20;">
                    <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>" 
                       style="color: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;"></i>
                </div>
                <div class="provider-title">
                    <h1><?php echo htmlspecialchars($provider['provider_name']); ?></h1>
                    <div class="provider-meta">
                        <span class="badge-code"><?php echo htmlspecialchars($provider['provider_code']); ?></span>
                        <span class="badge-type <?php echo $provider['provider_type']; ?>">
                            <?php echo ucwords(str_replace('_', ' ', $provider['provider_type'])); ?>
                        </span>
                        <?php if ($provider['is_active']): ?>
                            <span class="badge-status active">
                                <i class="fas fa-check-circle"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge-status inactive">
                                <i class="fas fa-times-circle"></i> Inactive
                            </span>
                        <?php endif; ?>
                        <?php if ($provider['is_default']): ?>
                            <span class="badge-default">
                                <i class="fas fa-star"></i> Default
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ===== DETAILS GRID ===== -->
            <div class="details-grid">
                <!-- Basic Information -->
                <div class="detail-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i> Basic Information
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Provider Code</span>
                        <span class="detail-value" style="font-weight:700;color:<?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                            <?php echo htmlspecialchars($provider['provider_code']); ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Provider Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Provider Type</span>
                        <span class="detail-value">
                            <span class="type-badge <?php echo $provider['provider_type']; ?>">
                                <?php echo ucwords(str_replace('_', ' ', $provider['provider_type'])); ?>
                            </span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Category</span>
                        <span class="detail-value"><?php echo htmlspecialchars($provider['category'] ?? '—'); ?></span>
                    </div>
                </div>

                <!-- Appearance -->
                <div class="detail-section">
                    <div class="section-title">
                        <i class="fas fa-palette"></i> Appearance
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Icon</span>
                        <span class="detail-value">
                            <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>" 
                               style="color: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>; font-size:24px;"></i>
                            <code style="margin-left:10px;font-size:12px;background:var(--form-hover);padding:2px 8px;border-radius:4px;">
                                <?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>
                            </code>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Color</span>
                        <span class="detail-value">
                            <span class="color-preview" style="background:<?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;"></span>
                            <code style="margin-left:10px;font-size:12px;background:var(--form-hover);padding:2px 8px;border-radius:4px;">
                                <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?>
                            </code>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Display Order</span>
                        <span class="detail-value"><?php echo $provider['display_order']; ?></span>
                    </div>
                </div>

                <!-- Settings -->
                <div class="detail-section">
                    <div class="section-title">
                        <i class="fas fa-cog"></i> Settings
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">
                            <?php if ($provider['is_active']): ?>
                                <span class="status-badge active">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="status-badge inactive">
                                    <i class="fas fa-times-circle"></i> Inactive
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Default Provider</span>
                        <span class="detail-value">
                            <?php if ($provider['is_default']): ?>
                                <span class="default-badge yes">
                                    <i class="fas fa-star" style="color:#F59E0B;"></i> Yes
                                </span>
                            <?php else: ?>
                                <span class="default-badge no">
                                    <i class="fas fa-star" style="color:#9CA3AF;"></i> No
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Requires Cash Balance</span>
                        <span class="detail-value">
                            <?php if ($provider['requires_cash_balance']): ?>
                                <span class="cash-badge yes">
                                    <i class="fas fa-check-circle" style="color:#10B981;"></i> Yes
                                </span>
                            <?php else: ?>
                                <span class="cash-badge no">
                                    <i class="fas fa-times-circle" style="color:#9CA3AF;"></i> No
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="detail-section full-width">
                    <div class="section-title">
                        <i class="fas fa-sticky-note"></i> Notes
                    </div>
                    <div class="detail-item">
                        <div class="notes-content">
                            <?php echo !empty($provider['notes']) ? nl2br(htmlspecialchars($provider['notes'])) : '<em style="color:var(--form-text-light);">No notes provided.</em>'; ?>
                        </div>
                    </div>
                </div>

                <!-- Metadata -->
                <div class="detail-section full-width">
                    <div class="section-title">
                        <i class="fas fa-history"></i> Metadata
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Created By</span>
                        <span class="detail-value"><?php echo htmlspecialchars($provider['created_by_name'] ?? 'System'); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Created At</span>
                        <span class="detail-value"><?php echo formatDateTime($provider['created_at']); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last Updated</span>
                        <span class="detail-value"><?php echo formatDateTime($provider['updated_at']); ?></span>
                    </div>
                </div>
            </div>

            <!-- ===== ACTIONS ===== -->
            <div class="view-actions">
                <a href="edit.php?id=<?php echo $provider_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Provider
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> Back to List
                </a>
                <a href="delete.php?id=<?php echo $provider_id; ?>" class="btn btn-danger" 
                   onclick="return confirm('Are you sure you want to delete this provider?')">
                    <i class="fas fa-trash"></i> Delete
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
STYLES
============================================================ -->
<style>
/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --view-bg: #FFFFFF;
    --view-text: #1F2937;
    --view-text-secondary: #6B7280;
    --view-text-light: #9CA3AF;
    --view-border: #E5E7EB;
    --view-card-bg: #FFFFFF;
    --view-hover: #F3F4F6;
    --view-shadow: rgba(0,0,0,0.06);
    --view-shadow-lg: rgba(0,0,0,0.12);
    --view-section-border: #E5E7EB;
}

html.dark-mode {
    --view-bg: #1F2937;
    --view-text: #F9FAFB;
    --view-text-secondary: #9CA3AF;
    --view-text-light: #6B7280;
    --view-border: #374151;
    --view-card-bg: #1F2937;
    --view-hover: #374151;
    --view-shadow: rgba(0,0,0,0.3);
    --view-shadow-lg: rgba(0,0,0,0.4);
    --view-section-border: #374151;
}

body {
    background: var(--view-bg) !important;
    color: var(--view-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--view-bg) !important; }
.main-content { background: var(--view-bg) !important; }

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
    color: var(--view-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #3B82F6;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--view-text-secondary);
    background: var(--view-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.btn-back {
    background: var(--view-hover);
    color: var(--view-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: 1px solid var(--view-border);
}

.btn-back:hover {
    background: var(--view-border);
    color: var(--view-text);
}

.btn-edit {
    background: #DC2626;
    color: white;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-edit:hover {
    background: #B91C1C;
    color: white;
}

/* ============================================================
   VIEW CONTAINER
   ============================================================ */
.view-container {
    background: var(--view-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--view-shadow);
    border: 1px solid var(--view-border);
    padding: 24px;
    transition: all 0.3s ease;
}

/* ============================================================
   PROVIDER HEADER
   ============================================================ */
.provider-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: var(--view-hover);
    border-radius: 10px;
    margin-bottom: 24px;
    border-left: 6px solid #0B5ED7;
    transition: all 0.3s ease;
}

.provider-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    flex-shrink: 0;
}

.provider-title {
    flex: 1;
}

.provider-title h1 {
    font-size: 24px;
    font-weight: 700;
    color: var(--view-text);
    margin: 0 0 8px 0;
}

.provider-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.badge-code {
    background: var(--view-border);
    color: var(--view-text-secondary);
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-type {
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-type.bank {
    background: #DBEAFE;
    color: #1D4ED8;
}

.badge-type.mobile_money {
    background: #FEF3C7;
    color: #D97706;
}

.badge-type.other {
    background: #F3F4F6;
    color: #6B7280;
}

html.dark-mode .badge-type.bank {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .badge-type.mobile_money {
    background: #5F3A1E;
    color: #FBBF24;
}

html.dark-mode .badge-type.other {
    background: #374151;
    color: #9CA3AF;
}

.badge-status {
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-status.active {
    background: #D1FAE5;
    color: #065F46;
}

.badge-status.inactive {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .badge-status.active {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .badge-status.inactive {
    background: #7F1D1D;
    color: #FEE2E2;
}

.badge-default {
    background: #FEF3C7;
    color: #D97706;
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

html.dark-mode .badge-default {
    background: #5F3A1E;
    color: #FBBF24;
}

/* ============================================================
   DETAILS GRID
   ============================================================ */
.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px 30px;
}

.detail-section {
    background: var(--view-hover);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--view-border);
    transition: all 0.3s ease;
}

.detail-section.full-width {
    grid-column: 1 / -1;
}

.section-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--view-text);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title i {
    color: #3B82F6;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid var(--view-border);
    align-items: center;
}

.detail-item:last-child {
    border-bottom: none;
}

.detail-label {
    font-size: 13px;
    font-weight: 500;
    color: var(--view-text-secondary);
}

.detail-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--view-text);
}

/* Type Badge in details */
.type-badge {
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.type-badge.bank {
    background: #DBEAFE;
    color: #1D4ED8;
}

.type-badge.mobile_money {
    background: #FEF3C7;
    color: #D97706;
}

.type-badge.other {
    background: #F3F4F6;
    color: #6B7280;
}

html.dark-mode .type-badge.bank {
    background: #1E3A5F;
    color: #60A5FA;
}

html.dark-mode .type-badge.mobile_money {
    background: #5F3A1E;
    color: #FBBF24;
}

html.dark-mode .type-badge.other {
    background: #374151;
    color: #9CA3AF;
}

/* Color Preview */
.color-preview {
    display: inline-block;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 2px solid var(--view-border);
    vertical-align: middle;
}

/* Status Badge */
.status-badge {
    padding: 2px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
}

.status-badge.inactive {
    background: #FEE2E2;
    color: #991B1B;
}

html.dark-mode .status-badge.active {
    background: #065F46;
    color: #D1FAE5;
}

html.dark-mode .status-badge.inactive {
    background: #7F1D1D;
    color: #FEE2E2;
}

/* Default Badge */
.default-badge {
    font-weight: 600;
}

.default-badge.yes {
    color: #D97706;
}

.default-badge.no {
    color: #9CA3AF;
}

/* Cash Badge */
.cash-badge {
    font-weight: 600;
}

.cash-badge.yes {
    color: #10B981;
}

.cash-badge.no {
    color: #9CA3AF;
}

/* Notes */
.notes-content {
    padding: 8px 0;
    color: var(--view-text);
    font-size: 13px;
    line-height: 1.6;
    min-height: 40px;
}

/* ============================================================
   VIEW ACTIONS
   ============================================================ */
.view-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--view-section-border);
    flex-wrap: wrap;
}

.btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: #DC2626;
    color: white;
}

.btn-primary:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

.btn-secondary {
    background: var(--view-hover);
    color: var(--view-text);
    border: 1px solid var(--view-border);
}

.btn-secondary:hover {
    background: var(--view-border);
}

.btn-danger {
    background: #DC2626;
    color: white;
    margin-left: auto;
}

.btn-danger:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 768px) {
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .view-container {
        padding: 16px;
    }
    
    .provider-header {
        flex-direction: column;
        text-align: center;
        padding: 16px;
    }
    
    .provider-title h1 {
        font-size: 20px;
    }
    
    .provider-meta {
        justify-content: center;
    }
    
    .detail-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    
    .view-actions {
        flex-direction: column;
    }
    
    .view-actions .btn {
        justify-content: center;
        width: 100%;
    }
    
    .btn-danger {
        margin-left: 0;
    }
}

@media (max-width: 480px) {
    .view-container {
        padding: 12px;
    }
    
    .provider-header {
        padding: 12px;
    }
    
    .provider-icon {
        width: 48px;
        height: 48px;
        font-size: 20px;
    }
    
    .provider-title h1 {
        font-size: 18px;
    }
    
    .detail-section {
        padding: 12px 14px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dark Mode Sync
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