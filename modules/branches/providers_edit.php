<?php
// ================================================================
// FILE: modules/branches/providers_edit.php
// WAKALA FINANCIAL SYSTEM - EDIT BRANCH PROVIDER
// RED THEME + LIVE PREVIEW + BALANCE INFO
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================================
// GET IDS
// ============================================================
$id        = isset($_GET['id']) ? intval($_GET['id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($id <= 0 || $branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider assignment.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH BRANCH PROVIDER RECORD
// ============================================================
$stmt = $db->prepare("
    SELECT bp.*,
           b.branch_name,
           b.branch_code AS branch_main_code,
           b.location AS branch_location,
           b.phone AS branch_phone,
           b.email AS branch_email,
           p.provider_name,
           p.provider_code AS provider_main_code,
           p.provider_type,
           p.icon_class,
           p.color_code
    FROM branch_providers bp
    LEFT JOIN branches b ON bp.branch_id = b.id
    LEFT JOIN providers p ON bp.provider_id = p.id
    WHERE bp.id = ? AND bp.branch_id = ?
");
$stmt->execute([$id, $branch_id]);
$bp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bp) {
    $_SESSION['error_message'] = 'Provider assignment not found for this branch.';
    header('Location: providers.php?branch_id=' . $branch_id);
    exit();
}

// ============================================================
// FETCH CURRENT BALANCE INFO (read-only)
// ============================================================
$current_float = 0;
$today_deposits = 0;
$today_withdrawals = 0;
$total_transactions = 0;

try {
    // Current float from latest morning report
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(mrp.float_balance), 0)
        FROM morning_reports mr
        JOIN morning_report_providers mrp ON mr.id = mrp.report_id
        WHERE mr.branch_id = ?
          AND mrp.provider_id = ?
          AND mr.report_date = CURDATE()
    ");
    $stmt->execute([$branch_id, $bp['provider_id']]);
    $current_float = floatval($stmt->fetchColumn());

    // Today's deposits
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM transactions
        WHERE branch_id = ?
          AND provider_id = ?
          AND transaction_type = 'deposit'
          AND status = 'approved'
          AND DATE(transaction_date) = CURDATE()
    ");
    $stmt->execute([$branch_id, $bp['provider_id']]);
    $today_deposits = floatval($stmt->fetchColumn());

    // Today's withdrawals
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM transactions
        WHERE branch_id = ?
          AND provider_id = ?
          AND transaction_type = 'withdrawal'
          AND status = 'approved'
          AND DATE(transaction_date) = CURDATE()
    ");
    $stmt->execute([$branch_id, $bp['provider_id']]);
    $today_withdrawals = floatval($stmt->fetchColumn());

    // Total transactions
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM transactions
        WHERE branch_id = ? AND provider_id = ?
    ");
    $stmt->execute([$branch_id, $bp['provider_id']]);
    $total_transactions = intval($stmt->fetchColumn());
} catch (Exception $e) {
    // Ignore
}

$has_activity = ($current_float > 0 || $today_deposits > 0 || $today_withdrawals > 0 || $total_transactions > 0);

// ============================================================
// HANDLE UPDATE
// ============================================================
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_branch_provider') {
    try {
        $db->beginTransaction();

        $provider_code = trim($_POST['provider_code'] ?? '');
        $is_active     = isset($_POST['is_active']) ? 1 : 0;

        if ($provider_code === '') {
            throw new Exception('Provider code is required.');
        }

        // Uniqueness within branch
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM branch_providers
            WHERE branch_id = ? AND provider_code = ? AND id != ?
        ");
        $stmt->execute([$branch_id, $provider_code, $id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('This provider code is already used in this branch.');
        }

        // Update
        $stmt = $db->prepare("
            UPDATE branch_providers SET
                provider_code = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE id = ? AND branch_id = ?
        ");
        $stmt->execute([$provider_code, $is_active, $id, $branch_id]);

        // Log
        if (function_exists('logActivity')) {
            logActivity(
                $user_id,
                'Edit Branch Provider',
                'Branches',
                $branch_id,
                $bp['branch_code'] ?? '',
                'Updated provider ' . ($bp['provider_name'] ?? '') .
                ' for branch ' . ($bp['branch_name'] ?? '') .
                ' (code: ' . $provider_code . ')'
            );
        }

        $db->commit();

        $_SESSION['success_message'] = 'Provider assignment updated successfully!';
        header('Location: providers.php?branch_id=' . $branch_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
        $form_data = $_POST;
    }
}

// ============================================================
// FORM VALUES
// ============================================================
$form_code = $form_data['provider_code'] ?? $bp['provider_code'] ?? '';
$form_is_active = isset($form_data['is_active'])
                ? 1
                : intval($bp['is_active'] ?? 1);

// ============================================================
// DERIVED
// ============================================================
$color = $bp['color_code'] ?? '#0B5ED7';
$icon  = $bp['icon_class'] ?? 'fas fa-university';
$type  = $bp['provider_type'] ?? 'bank';
$type_label = ucfirst(str_replace('_', ' ', $type));
$type_icon = $type === 'mobile_money' ? 'fa-mobile-alt' : ($type === 'other' ? 'fa-coins' : 'fa-landmark');

$provider_initial = strtoupper(substr($bp['provider_name'] ?? 'P', 0, 1));

// Flash
$success_message = '';
$error_message_flash = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message_flash = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit"></i> Edit Branch Provider</h2>
                <p class="text-muted">
                    Update the provider assignment details
                </p>
            </div>
            <div class="header-right">
                <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Providers
                </a>
                <a href="view.php?id=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-store-alt"></i> View Branch
                </a>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message_flash)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message_flash); ?></span>
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
        BRANCH INDICATOR
        ============================================================ -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($bp['branch_name'] ?? 'N/A'); ?></span>
                    <?php if (!empty($bp['branch_main_code'])): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($bp['branch_main_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($bp['branch_location'])): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($bp['branch_location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        PROVIDER CONTEXT CARD
        ============================================================ -->
        <div class="provider-context-card">
            <div class="provider-context-left">
                <div class="provider-context-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                    <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                </div>
                <div class="provider-context-info">
                    <span class="provider-context-label">Currently Editing</span>
                    <span class="provider-context-name"><?php echo htmlspecialchars($bp['provider_name'] ?? 'N/A'); ?></span>
                    <div class="provider-context-chips">
                        <span class="chip-mono"><?php echo htmlspecialchars($bp['provider_main_code'] ?? '-'); ?></span>
                        <span class="type-pill type-<?php echo $type; ?>">
                            <i class="fas <?php echo $type_icon; ?>"></i>
                            <?php echo htmlspecialchars($type_label); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="provider-context-right">
                <?php if ($has_activity): ?>
                    <span class="activity-badge activity-has">
                        <i class="fas fa-circle"></i>
                        Has activity
                    </span>
                <?php else: ?>
                    <span class="activity-badge activity-none">
                        <i class="fas fa-circle"></i>
                        No activity yet
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        FORM
        ============================================================ -->
        <form method="POST" action="" id="providerForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="update_branch_provider">

            <!-- MAIN CARD -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-pen-to-square"></i>
                        </div>
                        <div>
                            <h3>Assignment Details</h3>
                            <p>Configure how this provider works at this branch</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-pen-to-square"></i> Editing
                    </div>
                </div>

                <div class="form-card-body">

                    <!-- Row: Provider (read-only) + Branch (read-only) -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Provider</label>
                            <div class="readonly-field">
                                <div class="readonly-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                    <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                </div>
                                <div class="readonly-info">
                                    <span class="readonly-name"><?php echo htmlspecialchars($bp['provider_name']); ?></span>
                                    <span class="readonly-sub"><?php echo htmlspecialchars($bp['provider_main_code'] ?? '-'); ?></span>
                                </div>
                            </div>
                            <p class="field-hint">
                                <i class="fas fa-lock"></i> Provider cannot be changed. Delete and re-add to switch provider.
                            </p>
                        </div>

                        <div class="form-group">
                            <label>Branch</label>
                            <div class="readonly-field">
                                <div class="readonly-icon readonly-icon-red">
                                    <i class="fas fa-store-alt"></i>
                                </div>
                                <div class="readonly-info">
                                    <span class="readonly-name"><?php echo htmlspecialchars($bp['branch_name']); ?></span>
                                    <span class="readonly-sub"><?php echo htmlspecialchars($bp['branch_main_code'] ?? '-'); ?></span>
                                </div>
                            </div>
                            <p class="field-hint">
                                <i class="fas fa-lock"></i> Branch cannot be changed from this page.
                            </p>
                        </div>
                    </div>

                    <!-- Row: Provider Code + Status -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch Provider Code <span class="required">*</span></label>
                            <input type="text"
                                   name="provider_code"
                                   id="providerCodeInput"
                                   class="form-control code-input"
                                   value="<?php echo htmlspecialchars($form_code); ?>"
                                   placeholder="e.g. CRDB-KRK-01"
                                   required
                                   oninput="updatePreview()">
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Code used to identify this provider within this branch.
                            </p>
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <label class="toggle-switch-wrapper">
                                <input type="checkbox"
                                       name="is_active"
                                       value="1"
                                       id="isActiveToggle"
                                       <?php echo $form_is_active ? 'checked' : ''; ?>
                                       onchange="updateStatusLabel(); updatePreview();">
                                <span class="toggle-switch"></span>
                                <span class="toggle-label" id="toggleLabel">
                                    <?php echo $form_is_active ? 'Active' : 'Inactive'; ?>
                                </span>
                            </label>
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Inactive providers are hidden from daily reports.
                            </p>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ============================================================
            LIVE PREVIEW
            ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div>
                            <h3>Live Preview</h3>
                            <p>How the provider card will look</p>
                        </div>
                    </div>
                </div>

                <div class="form-card-body">
                    <div class="preview-center">
                        <div class="preview-card" id="previewCard">
                            <div class="preview-card-accent" style="background: <?php echo htmlspecialchars($color); ?>;"></div>
                            <div class="preview-card-body">

                                <div class="preview-card-top">
                                    <div class="preview-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                        <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                    </div>
                                    <div class="preview-info">
                                        <div class="preview-name"><?php echo htmlspecialchars($bp['provider_name']); ?></div>
                                        <div class="preview-code" id="previewCode">
                                            <?php echo htmlspecialchars($form_code ?: 'CODE'); ?>
                                        </div>
                                    </div>
                                    <span class="preview-status-pill <?php echo $form_is_active ? 'active' : 'inactive'; ?>"
                                          id="previewStatusPill">
                                        <i class="fas fa-<?php echo $form_is_active ? 'check-circle' : 'times-circle'; ?>"
                                           id="previewStatusIcon"></i>
                                        <span id="previewStatusText"><?php echo $form_is_active ? 'Active' : 'Inactive'; ?></span>
                                    </span>
                                </div>

                                <div class="preview-meta">
                                    <span class="preview-type type-<?php echo $type; ?>">
                                        <i class="fas <?php echo $type_icon; ?>"></i>
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </span>
                                    <?php if ($has_activity): ?>
                                        <span class="preview-meta-badge has-activity">
                                            <i class="fas fa-chart-line"></i> Has activity
                                        </span>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
            CURRENT BALANCE INFO
            ============================================================ -->
            <?php if ($has_activity): ?>
            <div class="balance-info-card">
                <div class="balance-info-header">
                    <div class="balance-info-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h3>Current Activity Summary</h3>
                        <p>Read-only — reflects today's live data</p>
                    </div>
                </div>
                <div class="balance-info-body">
                    <div class="balance-stat balance-stat-float">
                        <div class="balance-stat-label">Current Float</div>
                        <div class="balance-stat-value"><?php echo formatCurrency($current_float); ?></div>
                    </div>
                    <div class="balance-stat balance-stat-deposit">
                        <div class="balance-stat-label">Today's Deposits</div>
                        <div class="balance-stat-value"><?php echo formatCurrency($today_deposits); ?></div>
                    </div>
                    <div class="balance-stat balance-stat-withdrawal">
                        <div class="balance-stat-label">Today's Withdrawals</div>
                        <div class="balance-stat-value"><?php echo formatCurrency($today_withdrawals); ?></div>
                    </div>
                    <div class="balance-stat balance-stat-txn">
                        <div class="balance-stat-label">Total Transactions</div>
                        <div class="balance-stat-value"><?php echo number_format($total_transactions); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================================
            WARNING BANNER
            ============================================================ -->
            <div class="warning-banner">
                <div class="warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="warning-text">
                    <strong>Important:</strong>
                    Changing the provider code affects how this provider is referenced in reports and transactions.
                    Make sure downstream systems use the new code.
                </div>
            </div>

            <!-- ============================================================
            ACTIONS
            ============================================================ -->
            <div class="form-actions">
                <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary-large">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="button" class="btn btn-reset-large" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button type="submit" class="btn btn-submit-large" id="submitBtn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>

        </form>

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
    --red-primary: #DC2626;
    --red-dark: #B91C1C;
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

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; }
.header-right { display: flex; gap: 10px; flex-wrap: wrap; }

.btn {
    padding: 10px 20px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif; white-space: nowrap;
}
.btn-back {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-back:hover {
    background: #FEF2F2; color: var(--red-primary);
    border-color: var(--red-primary); transform: translateY(-2px);
}

/* ALERTS */
.alert {
    padding: 14px 18px; border-radius: 10px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 12px;
    animation: slideDown 0.4s ease forwards;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.3);
    flex-wrap: wrap; gap: 14px;
    position: relative; overflow: hidden;
}
.branch-indicator::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-indicator-left {
    display: flex; align-items: center; gap: 14px;
    flex-wrap: wrap; min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.branch-icon-wrapper {
    width: 44px; height: 44px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 19px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px;
    color: #FFFFFF;
    text-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.branch-location {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.85);
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px; white-space: nowrap;
}

/* PROVIDER CONTEXT CARD */
.provider-context-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    padding: 16px 22px;
    margin-bottom: 16px;
    display: flex; justify-content: space-between;
    align-items: center; gap: 16px;
    flex-wrap: wrap;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.provider-context-left {
    display: flex; align-items: center; gap: 14px;
    min-width: 0; flex: 1;
}
.provider-context-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.provider-context-info {
    display: flex; flex-direction: column; gap: 4px;
    min-width: 0; flex: 1;
}
.provider-context-label {
    font-size: 10px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
}
.provider-context-name {
    font-size: 17px; font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    word-break: break-word;
}
.provider-context-chips {
    display: flex; flex-wrap: wrap; gap: 6px;
    align-items: center;
}
.chip-mono {
    display: inline-block;
    padding: 3px 10px;
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 10.5px; font-weight: 800;
    letter-spacing: 0.4px;
}
.type-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap;
}
.type-pill i { font-size: 9px; }
.type-bank { background: #DBEAFE; color: #1D4ED8; border: 1.5px solid #BFDBFE; }
.type-mobile_money { background: #D1FAE5; color: #059669; border: 1.5px solid #A7F3D0; }
.type-other { background: #FEF3C7; color: #D97706; border: 1.5px solid #FDE68A; }
html.dark-mode .type-bank { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .type-mobile_money { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .type-other { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

.provider-context-right {
    flex-shrink: 0;
}
.activity-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    border-radius: 10px;
    font-size: 12px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    border: 1.5px solid;
    white-space: nowrap;
}
.activity-badge i { font-size: 8px; }
.activity-has {
    background: #FEF3C7; color: #D97706;
    border-color: #FDE68A;
}
.activity-none {
    background: #F3F4F6; color: #6B7280;
    border-color: #E5E7EB;
}
html.dark-mode .activity-has { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }
html.dark-mode .activity-none { background: #334155; color: #94A3B8; border-color: #475569; }

/* FORM CARD */
.form-card {
    background: var(--bg-card);
    border-radius: 16px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 4px 16px var(--shadow-color);
}
.form-card-header {
    padding: 18px 26px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.form-card-header::before {
    content: ''; position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.form-card-header-left { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; }
.form-card-icon {
    width: 48px; height: 48px;
    background: rgba(255,255,255,0.2);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.3);
}
.form-card-header h3 { font-size: 17px; font-weight: 800; margin: 0 0 2px 0; color: #FFFFFF; }
.form-card-header p { font-size: 12px; margin: 0; color: rgba(255,255,255,0.85); font-weight: 500; }
.form-card-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px;
    background: rgba(255,255,255,0.2);
    color: #FFFFFF; border-radius: 20px;
    font-size: 12px; font-weight: 700;
    border: 1px solid rgba(255,255,255,0.3);
    position: relative; z-index: 1;
}
.form-card-body { padding: 26px; }

/* FORM ELEMENTS */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
.form-row:last-child { margin-bottom: 0; }
.form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.form-group label {
    font-size: 12px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.form-group label .required { color: #DC2626; }

.form-control {
    padding: 12px 16px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%;
}
.form-control:focus {
    outline: none; border-color: var(--red-primary);
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--bg-card);
}
.form-control::placeholder { color: var(--text-light); font-size: 12px; }
.code-input {
    font-family: 'Courier New', monospace !important;
    font-weight: 800 !important;
    letter-spacing: 1px;
    color: var(--red-primary) !important;
    text-transform: uppercase;
}
.field-hint {
    font-size: 11px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 5px;
    font-weight: 500; line-height: 1.5;
}
.field-hint i { font-size: 10px; color: var(--red-primary); }

/* READONLY FIELD */
.readonly-field {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px;
    background: var(--bg-input);
    border: 1.5px dashed var(--border-color);
    border-radius: 10px;
    min-height: 62px;
}
.readonly-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(0,0,0,0.15);
}
.readonly-icon-red {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.readonly-info {
    display: flex; flex-direction: column; gap: 2px;
    min-width: 0; flex: 1;
}
.readonly-name {
    font-size: 13.5px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
}
.readonly-sub {
    font-size: 11px; font-weight: 700;
    color: var(--text-muted);
    font-family: 'Courier New', monospace;
}

/* TOGGLE */
.toggle-switch-wrapper {
    display: inline-flex; align-items: center; gap: 12px;
    cursor: pointer; user-select: none;
    padding: 8px 4px;
    align-self: flex-start;
}
.toggle-switch-wrapper input[type="checkbox"] {
    position: absolute; opacity: 0; pointer-events: none;
}
.toggle-switch {
    width: 56px; height: 30px;
    background: #D1D5DB;
    border-radius: 15px;
    position: relative;
    transition: all 0.3s ease;
    flex-shrink: 0;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
}
.toggle-switch::before {
    content: '';
    position: absolute;
    top: 3px; left: 3px;
    width: 24px; height: 24px;
    background: #FFFFFF;
    border-radius: 50%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.toggle-switch-wrapper input[type="checkbox"]:checked + .toggle-switch {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.toggle-switch-wrapper input[type="checkbox"]:checked + .toggle-switch::before {
    transform: translateX(26px);
}
.toggle-label {
    font-size: 14px; font-weight: 800;
    color: var(--text-primary);
    min-width: 60px;
}
html.dark-mode .toggle-switch { background: #475569; }

/* LIVE PREVIEW */
.preview-center {
    display: flex; justify-content: center; padding: 20px 0;
}
.preview-card {
    width: 100%;
    max-width: 380px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}
.preview-card-accent {
    height: 5px; width: 100%;
}
.preview-card-body { padding: 16px; }
.preview-card-top {
    display: flex; align-items: flex-start;
    gap: 12px; margin-bottom: 12px;
}
.preview-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}
.preview-info {
    display: flex; flex-direction: column; gap: 5px;
    min-width: 0; flex: 1;
}
.preview-name {
    font-size: 14px; font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    word-break: break-word;
}
.preview-code {
    display: inline-flex; align-items: center;
    padding: 3px 8px;
    background: #FEF2F2;
    color: #DC2626;
    border: 1px solid #FCA5A5;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    font-size: 9.5px; font-weight: 800;
    letter-spacing: 0.4px;
    align-self: flex-start;
    text-transform: uppercase;
}
html.dark-mode .preview-code {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.preview-status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 9.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap; flex-shrink: 0;
}
.preview-status-pill i { font-size: 8px; }
.preview-status-pill.active {
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.preview-status-pill.inactive {
    background: #F3F4F6; color: #6B7280;
    border: 1.5px solid #E5E7EB;
}
html.dark-mode .preview-status-pill.active {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .preview-status-pill.inactive {
    background: #334155; color: #94A3B8; border-color: #475569;
}
.preview-meta {
    display: flex; flex-wrap: wrap; gap: 6px;
    align-items: center;
}
.preview-type {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
}
.preview-type i { font-size: 9px; }
.preview-meta-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    background: #FEF3C7;
    color: #D97706;
    border: 1.5px solid #FDE68A;
}
.preview-meta-badge i { font-size: 9px; }
html.dark-mode .preview-meta-badge {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}

/* BALANCE INFO CARD */
.balance-info-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.balance-info-header {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 22px;
    background: var(--bg-input);
    border-bottom: 1.5px solid var(--border-color);
}
.balance-info-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FFFFFF;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
.balance-info-header h3 {
    font-size: 15px; font-weight: 800;
    margin: 0 0 2px 0;
    color: var(--text-primary);
}
.balance-info-header p {
    font-size: 11.5px;
    margin: 0;
    color: var(--text-muted);
    font-weight: 500;
}
.balance-info-body {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding: 18px 22px;
}
.balance-stat {
    display: flex; flex-direction: column; gap: 4px;
    padding: 14px 16px;
    border-radius: 10px;
    border: 1.5px solid;
    min-width: 0;
}
.balance-stat-label {
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.6px;
    opacity: 0.85;
}
.balance-stat-value {
    font-size: 16px; font-weight: 900;
    font-family: 'Courier New', monospace;
    word-break: break-all; line-height: 1.2;
}

.balance-stat-float {
    background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
    border-color: #93C5FD;
    color: #1E40AF;
}
.balance-stat-deposit {
    background: linear-gradient(135deg, #F0FDF4 0%, #D1FAE5 100%);
    border-color: #A7F3D0;
    color: #047857;
}
.balance-stat-withdrawal {
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    border-color: #FCA5A5;
    color: #991B1B;
}
.balance-stat-txn {
    background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
    border-color: #C4B5FD;
    color: #5B21B6;
}
html.dark-mode .balance-stat-float {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6; color: #93C5FD;
}
html.dark-mode .balance-stat-deposit {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%);
    border-color: #10B981; color: #6EE7B7;
}
html.dark-mode .balance-stat-withdrawal {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
    border-color: #DC2626; color: #FCA5A5;
}
html.dark-mode .balance-stat-txn {
    background: linear-gradient(135deg, #4C1D95 0%, #5B21B6 100%);
    border-color: #8B5CF6; color: #DDD6FE;
}

/* WARNING BANNER */
.warning-banner {
    display: flex; gap: 14px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    margin-bottom: 20px;
    align-items: center;
}
html.dark-mode .warning-banner {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #D97706;
}
.warning-icon {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: #FFFFFF;
    color: #D97706;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(217, 119, 6, 0.2);
}
html.dark-mode .warning-icon { background: #1e293b; }
.warning-text {
    font-size: 13px;
    color: #78350F;
    line-height: 1.5;
    flex: 1;
}
html.dark-mode .warning-text { color: #FDE68A; }
.warning-text strong { font-weight: 800; }

/* FORM ACTIONS */
.form-actions {
    display: flex; gap: 12px;
    padding: 22px 26px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    flex-wrap: wrap; justify-content: flex-end;
    position: sticky; bottom: 16px; z-index: 10;
}
.btn-secondary-large, .btn-reset-large, .btn-submit-large {
    padding: 13px 26px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    min-width: 140px;
    justify-content: center;
}
.btn-secondary-large {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary-large:hover {
    background: var(--bg-body);
    color: var(--text-primary);
    transform: translateY(-2px);
}
.btn-reset-large {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-reset-large:hover {
    background: #FEF3C7; color: #D97706;
    border-color: #FDE68A;
    transform: translateY(-2px);
}
html.dark-mode .btn-reset-large:hover {
    background: #5F3A1E; color: #FBBF24; border-color: #D97706;
}
.btn-submit-large {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
}
.btn-submit-large:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
}
.btn-submit-large:disabled {
    opacity: 0.6; cursor: not-allowed; transform: none;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .form-row { grid-template-columns: 1fr; }
    .balance-info-body { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .form-card-body { padding: 18px; }
    .form-card-header { padding: 16px 20px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .branch-indicator { flex-direction: column; align-items: flex-start; }

    .provider-context-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    .provider-context-right { width: 100%; }
    .activity-badge { width: 100%; justify-content: center; }

    .form-actions { flex-direction: column; position: static; padding: 16px; }
    .btn-secondary-large, .btn-reset-large, .btn-submit-large { width: 100%; }
}
@media (max-width: 480px) {
    .balance-info-body { grid-template-columns: 1fr; }
    .readonly-field { flex-direction: column; align-items: flex-start; }
    .readonly-icon { width: 36px; height: 36px; font-size: 15px; }
}
</style>

<script>
// ============================================================
// LIVE PREVIEW
// ============================================================
function updatePreview() {
    var codeInput = document.getElementById('providerCodeInput');
    var isActive = document.getElementById('isActiveToggle')?.checked;

    // Code
    var codeEl = document.getElementById('previewCode');
    if (codeEl && codeInput) {
        codeEl.textContent = (codeInput.value.trim() || 'CODE').toUpperCase();
    }

    // Status pill
    var pill = document.getElementById('previewStatusPill');
    var pillIcon = document.getElementById('previewStatusIcon');
    var pillText = document.getElementById('previewStatusText');
    if (pill && pillIcon && pillText) {
        if (isActive) {
            pill.className = 'preview-status-pill active';
            pillIcon.className = 'fas fa-check-circle';
            pillText.textContent = 'Active';
        } else {
            pill.className = 'preview-status-pill inactive';
            pillIcon.className = 'fas fa-times-circle';
            pillText.textContent = 'Inactive';
        }
    }
}

// ============================================================
// STATUS LABEL
// ============================================================
function updateStatusLabel() {
    var toggle = document.getElementById('isActiveToggle');
    var label = document.getElementById('toggleLabel');
    if (!toggle || !label) return;
    label.textContent = toggle.checked ? 'Active' : 'Inactive';
}

// ============================================================
// RESET
// ============================================================
function resetForm() {
    if (!confirm('Reset all changes back to the original values?')) return;
    window.location.href = window.location.pathname + '?id=<?php echo $id; ?>&branch_id=<?php echo $branch_id; ?>';
}

// ============================================================
// VALIDATION
// ============================================================
function validateForm() {
    var code = document.getElementById('providerCodeInput').value.trim();

    if (code === '') {
        alert('Please enter the provider code.');
        return false;
    }

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    return true;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updateStatusLabel();
    updatePreview();

    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true' || localStorage.getItem('darkMode') === 'enabled';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function() { syncDarkMode(); });
});
</script>
</body>
</html>