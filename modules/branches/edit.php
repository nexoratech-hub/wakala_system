<?php
// ================================================================
// FILE: modules/branches/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT BRANCH
// RED THEME + LIVE PREVIEW + MANAGER PICKER
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// GET BRANCH ID
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid branch ID.';
    header('Location: index.php');
    exit();
}

// ============================================================
// FETCH BRANCH
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// LOAD EMPLOYEES IN THIS BRANCH (for manager dropdown)
// ============================================================
$branch_employees = [];
try {
    $stmt = $db->prepare("
        SELECT id, employee_id, full_name, role
        FROM employees
        WHERE branch_id = ? AND is_active = 1
        ORDER BY full_name ASC
    ");
    $stmt->execute([$id]);
    $branch_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $branch_employees = [];
}

// ============================================================
// HANDLE UPDATE
// ============================================================
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_branch') {
    try {
        $db->beginTransaction();

        $branch_name = trim($_POST['branch_name'] ?? '');
        $branch_code = trim($_POST['branch_code'] ?? '');
        $location    = trim($_POST['location'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $manager_id  = intval($_POST['manager_id'] ?? 0);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        // Validation
        if ($branch_name === '') throw new Exception('Branch name is required.');
        if ($branch_code === '') throw new Exception('Branch code is required.');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }

        // Uniqueness
        $stmt = $db->prepare("SELECT COUNT(*) FROM branches WHERE branch_name = ? AND id != ?");
        $stmt->execute([$branch_name, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Branch name already exists.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM branches WHERE branch_code = ? AND id != ?");
        $stmt->execute([$branch_code, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Branch code already exists.');

        // Manager validation
        $manager_id_val = $manager_id > 0 ? $manager_id : null;

        // Update
        $stmt = $db->prepare("
            UPDATE branches SET
                branch_name = ?,
                branch_code = ?,
                location = ?,
                phone = ?,
                email = ?,
                address = ?,
                manager_id = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $branch_name, $branch_code, $location ?: null, $phone ?: null,
            $email ?: null, $address ?: null, $manager_id_val, $is_active, $id
        ]);

        // Log
        if (function_exists('logActivity')) {
            logActivity(
                $user_id,
                'Edit Branch',
                'Branches',
                $id,
                $branch['branch_code'],
                'Updated branch: ' . $branch_name
            );
        }

        $db->commit();

        $_SESSION['success_message'] = 'Branch "' . $branch_name . '" updated successfully!';
        header('Location: view.php?id=' . $id);
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
$form_name     = $form_data['branch_name'] ?? $branch['branch_name'] ?? '';
$form_code     = $form_data['branch_code'] ?? $branch['branch_code'] ?? '';
$form_location = $form_data['location']    ?? $branch['location']    ?? '';
$form_phone    = $form_data['phone']       ?? $branch['phone']       ?? '';
$form_email    = $form_data['email']       ?? $branch['email']       ?? '';
$form_address  = $form_data['address']     ?? $branch['address']     ?? '';
$form_manager  = isset($form_data['manager_id'])
               ? intval($form_data['manager_id'])
               : intval($branch['manager_id'] ?? 0);

$form_is_active = isset($form_data['is_active'])
                ? 1
                : intval($branch['is_active'] ?? 1);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit"></i> Edit Branch</h2>
                <p class="text-muted">
                    <i class="fas fa-tag"></i>
                    <?php echo htmlspecialchars($branch['branch_code'] ?? 'N/A'); ?>
                    &nbsp;·&nbsp;
                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                </p>
            </div>
            <div class="header-right">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-back">
                    <i class="fas fa-eye"></i> View
                </a>
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="branchForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="update_branch">

            <!-- ============================================================
            MAIN CARD
            ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-store-alt"></i>
                        </div>
                        <div>
                            <h3>Branch Information</h3>
                            <p>Update branch details below</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-pen-to-square"></i> Editing
                    </div>
                </div>

                <div class="form-card-body">

                    <!-- Row: Name + Code -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Branch Name <span class="required">*</span></label>
                            <input type="text" name="branch_name" id="branchName"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($form_name); ?>"
                                   placeholder="e.g. Kariakoo Branch"
                                   required
                                   oninput="updatePreview()">
                        </div>
                        <div class="form-group">
                            <label>Branch Code <span class="required">*</span></label>
                            <input type="text" name="branch_code" id="branchCode"
                                   class="form-control code-input"
                                   value="<?php echo htmlspecialchars($form_code); ?>"
                                   placeholder="e.g. KRK001"
                                   required
                                   oninput="updatePreview()">
                            <p class="field-hint">
                                <i class="fas fa-exclamation-triangle" style="color:#F59E0B;"></i>
                                Changing the code may affect existing reports.
                            </p>
                        </div>
                    </div>

                    <!-- Row: Location + Phone -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" id="branchLocation"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($form_location); ?>"
                                   placeholder="e.g. Kariakoo, Dar es Salaam"
                                   oninput="updatePreview()">
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" id="branchPhone"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($form_phone); ?>"
                                   placeholder="+255 7XX XXX XXX"
                                   oninput="updatePreview()">
                        </div>
                    </div>

                    <!-- Row: Email + Manager -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($form_email); ?>"
                                   placeholder="e.g. kariakoo@wakala.co.tz">
                        </div>
                        <div class="form-group">
                            <label>Branch Manager</label>
                            <select name="manager_id" id="branchManager" class="form-control"
                                    onchange="updatePreview()">
                                <option value="0">— No Manager —</option>
                                <?php foreach ($branch_employees as $e): ?>
                                    <option value="<?php echo $e['id']; ?>"
                                        <?php echo $form_manager == $e['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($e['full_name']); ?>
                                        (<?php echo htmlspecialchars($e['employee_id'] ?? '-'); ?>)
                                        — <?php echo ucfirst($e['role'] ?? 'employee'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (count($branch_employees) === 0): ?>
                                <p class="field-hint">
                                    <i class="fas fa-info-circle"></i>
                                    No employees assigned to this branch yet.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Row: Address -->
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label>Physical Address</label>
                            <input type="text" name="address" class="form-control"
                                   value="<?php echo htmlspecialchars($form_address); ?>"
                                   placeholder="e.g. Plot 45, Msimbazi Street, Kariakoo">
                        </div>
                    </div>

                    <!-- Status Toggle -->
                    <div class="status-toggle-row">
                        <div class="status-toggle-info">
                            <div class="status-toggle-icon <?php echo $form_is_active ? 'icon-active' : 'icon-inactive'; ?>">
                                <i class="fas fa-<?php echo $form_is_active ? 'check-circle' : 'times-circle'; ?>" id="statusIcon"></i>
                            </div>
                            <div>
                                <span class="status-toggle-title" id="statusTitle">
                                    <?php echo $form_is_active ? 'Branch is Active' : 'Branch is Inactive'; ?>
                                </span>
                                <span class="status-toggle-desc">
                                    Inactive branches are hidden from dropdowns and reports.
                                </span>
                            </div>
                        </div>
                        <label class="toggle-switch-wrapper">
                            <input type="checkbox" name="is_active" value="1"
                                   id="isActiveToggle"
                                   <?php echo $form_is_active ? 'checked' : ''; ?>
                                   onchange="updateStatusLabel(); updatePreview();">
                            <span class="toggle-switch"></span>
                            <span class="toggle-label" id="toggleLabel">
                                <?php echo $form_is_active ? 'Active' : 'Inactive'; ?>
                            </span>
                        </label>
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
                            <p>How the branch card will look</p>
                        </div>
                    </div>
                </div>

                <div class="form-card-body">
                    <div class="preview-center">
                        <div class="preview-branch-card" id="previewCard">
                            <div class="preview-card-header">
                                <div class="preview-card-header-left">
                                    <div class="preview-icon">
                                        <i class="fas fa-store"></i>
                                    </div>
                                    <div class="preview-card-title">
                                        <div class="preview-name" id="previewName">
                                            <?php echo htmlspecialchars($form_name ?: 'Branch Name'); ?>
                                        </div>
                                        <?php if (!empty($form_code)): ?>
                                            <div class="preview-code" id="previewCode">
                                                <?php echo htmlspecialchars($form_code); ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="preview-code" id="previewCode">CODE</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="preview-status-pill <?php echo $form_is_active ? 'active' : 'inactive'; ?>"
                                      id="previewStatusPill">
                                    <i class="fas fa-<?php echo $form_is_active ? 'check-circle' : 'times-circle'; ?>" id="previewStatusIcon"></i>
                                    <span id="previewStatusText"><?php echo $form_is_active ? 'Active' : 'Inactive'; ?></span>
                                </span>
                            </div>

                            <?php if (!empty($form_location)): ?>
                            <div class="preview-location" id="previewLocationRow">
                                <i class="fas fa-map-marker-alt"></i>
                                <span id="previewLocation"><?php echo htmlspecialchars($form_location); ?></span>
                            </div>
                            <?php else: ?>
                            <div class="preview-location" id="previewLocationRow" style="display:none;">
                                <i class="fas fa-map-marker-alt"></i>
                                <span id="previewLocation"></span>
                            </div>
                            <?php endif; ?>

                            <div class="preview-meta">
                                <?php if (!empty($form_phone)): ?>
                                <div class="preview-meta-item" id="previewPhoneRow">
                                    <i class="fas fa-phone"></i>
                                    <span id="previewPhone"><?php echo htmlspecialchars($form_phone); ?></span>
                                </div>
                                <?php else: ?>
                                <div class="preview-meta-item" id="previewPhoneRow" style="display:none;">
                                    <i class="fas fa-phone"></i>
                                    <span id="previewPhone"></span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($form_email)): ?>
                                <div class="preview-meta-item" id="previewEmailRow">
                                    <i class="fas fa-envelope"></i>
                                    <span id="previewEmail"><?php echo htmlspecialchars($form_email); ?></span>
                                </div>
                                <?php else: ?>
                                <div class="preview-meta-item" id="previewEmailRow" style="display:none;">
                                    <i class="fas fa-envelope"></i>
                                    <span id="previewEmail"></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
            WARNING BANNER
            ============================================================ -->
            <div class="warning-banner">
                <div class="warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="warning-text">
                    <strong>Important:</strong>
                    Changing the branch code affects how this branch is referenced in reports.
                    Make sure other systems use the new code if you change it.
                </div>
            </div>

            <!-- ============================================================
            FORM ACTIONS
            ============================================================ -->
            <div class="form-actions">
                <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary-large">
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
    margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { color: var(--red-primary); margin-right: 10px; }
.page-header .header-left .text-muted {
    font-size: 13px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 6px;
    font-family: 'Courier New', monospace; font-weight: 600;
}
.header-right { display: flex; gap: 10px; flex-wrap: wrap; }

.btn {
    padding: 10px 20px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
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
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* FORM CARDS */
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
.form-group { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.form-group.full-width { grid-column: 1 / -1; }
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
select.form-control { cursor: pointer; }
.field-hint {
    font-size: 11px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 5px;
    font-weight: 500; line-height: 1.5;
}
.field-hint i { font-size: 10px; color: var(--red-primary); }

/* STATUS TOGGLE */
.status-toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 20px;
    padding: 18px 22px;
    background: var(--bg-input);
    border: 2px solid var(--border-color);
    border-radius: 12px;
    flex-wrap: wrap;
    margin-top: 8px;
}
.status-toggle-info {
    display: flex; align-items: center; gap: 14px;
    min-width: 0; flex: 1;
}
.status-toggle-icon {
    width: 52px; height: 52px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}
.status-toggle-icon.icon-active {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669;
    border: 2px solid #6EE7B7;
}
.status-toggle-icon.icon-inactive {
    background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%);
    color: #DC2626;
    border: 2px solid #FCA5A5;
}
html.dark-mode .status-toggle-icon.icon-active {
    background: #065F46; color: #34D399; border-color: #10B981;
}
html.dark-mode .status-toggle-icon.icon-inactive {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.status-toggle-title {
    display: block;
    font-size: 15px; font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 3px;
}
.status-toggle-desc {
    display: block;
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.4;
}

.toggle-switch-wrapper {
    display: inline-flex; align-items: center; gap: 12px;
    cursor: pointer; user-select: none;
    flex-shrink: 0;
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
.preview-branch-card {
    width: 100%;
    max-width: 400px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}
.preview-card-header {
    display: flex; justify-content: space-between;
    align-items: flex-start;
    padding: 14px 16px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    gap: 10px;
    position: relative; overflow: hidden;
}
.preview-card-header::before {
    content: ''; position: absolute;
    top: -40%; right: -15%;
    width: 140px; height: 140px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%; pointer-events: none;
}
.preview-card-header-left {
    display: flex; align-items: center; gap: 10px;
    min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.preview-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: #FFFFFF;
    flex-shrink: 0;
    border: 1px solid rgba(255, 255, 255, 0.25);
}
.preview-card-title { min-width: 0; flex: 1; }
.preview-name {
    font-size: 15px; font-weight: 800;
    color: #FFFFFF;
    margin-bottom: 4px;
    line-height: 1.2;
    word-break: break-word;
    text-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.preview-code {
    display: inline-block;
    font-size: 10px; font-weight: 800;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 8px; border-radius: 6px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.4px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.preview-status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 9.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    white-space: nowrap;
    flex-shrink: 0;
    position: relative; z-index: 1;
}
.preview-status-pill.active {
    background: rgba(255, 255, 255, 0.22);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.3);
}
.preview-status-pill.inactive {
    background: rgba(0, 0, 0, 0.2);
    color: #FCA5A5;
    border: 1px solid rgba(0, 0, 0, 0.15);
}
.preview-status-pill i { font-size: 8px; }

.preview-location {
    padding: 8px 16px;
    font-size: 12px;
    color: var(--text-secondary);
    display: flex; align-items: center; gap: 6px;
    background: var(--bg-input);
    border-bottom: 1px solid var(--border-color);
}
.preview-location i { color: #DC2626; font-size: 11px; }
.preview-location span {
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis; min-width: 0;
}

.preview-meta {
    display: flex; flex-direction: column;
    gap: 6px;
    padding: 12px 16px;
}
.preview-meta-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px;
    color: var(--text-secondary);
}
.preview-meta-item i {
    color: #DC2626;
    font-size: 11px;
    width: 14px; text-align: center;
    flex-shrink: 0;
}
.preview-meta-item span {
    white-space: nowrap; overflow: hidden;
    text-overflow: ellipsis;
}

/* WARNING BANNER */
.warning-banner {
    display: flex; gap: 14px;
    padding: 18px 22px;
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
    width: 48px; height: 48px;
    border-radius: 50%;
    background: #FFFFFF;
    color: #D97706;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
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
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .form-card-body { padding: 18px; }
    .form-card-header { padding: 16px 20px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .status-toggle-row { flex-direction: column; align-items: flex-start; }
    .toggle-switch-wrapper { width: 100%; justify-content: space-between; }

    .form-actions { flex-direction: column; position: static; padding: 16px; }
    .btn-secondary-large, .btn-reset-large, .btn-submit-large { width: 100%; }
}
@media (max-width: 480px) {
    .form-card-header h3 { font-size: 15px; }
    .status-toggle-icon { width: 44px; height: 44px; font-size: 20px; }
}
</style>

<script>
// ============================================================
// LIVE PREVIEW
// ============================================================
function updatePreview() {
    var name = (document.getElementById('branchName')?.value || '').trim();
    var code = (document.getElementById('branchCode')?.value || '').trim();
    var location = (document.getElementById('branchLocation')?.value || '').trim();
    var phone = (document.getElementById('branchPhone')?.value || '').trim();
    var isActive = document.getElementById('isActiveToggle')?.checked;

    // Name
    var nameEl = document.getElementById('previewName');
    if (nameEl) nameEl.textContent = name || 'Branch Name';

    // Code
    var codeEl = document.getElementById('previewCode');
    if (codeEl) codeEl.textContent = (code || 'CODE').toUpperCase();

    // Location
    var locRow = document.getElementById('previewLocationRow');
    var locEl = document.getElementById('previewLocation');
    if (locRow && locEl) {
        if (location) {
            locEl.textContent = location;
            locRow.style.display = 'flex';
        } else {
            locRow.style.display = 'none';
        }
    }

    // Phone
    var phoneRow = document.getElementById('previewPhoneRow');
    var phoneEl = document.getElementById('previewPhone');
    if (phoneRow && phoneEl) {
        if (phone) {
            phoneEl.textContent = phone;
            phoneRow.style.display = 'flex';
        } else {
            phoneRow.style.display = 'none';
        }
    }

    // Email
    var email = (document.querySelector('input[name="email"]')?.value || '').trim();
    var emailRow = document.getElementById('previewEmailRow');
    var emailEl = document.getElementById('previewEmail');
    if (emailRow && emailEl) {
        if (email) {
            emailEl.textContent = email;
            emailRow.style.display = 'flex';
        } else {
            emailRow.style.display = 'none';
        }
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
    var title = document.getElementById('statusTitle');
    var icon = document.getElementById('statusIcon');
    var iconBox = document.querySelector('.status-toggle-icon');

    if (!toggle) return;
    var isActive = toggle.checked;

    if (label) label.textContent = isActive ? 'Active' : 'Inactive';
    if (title) title.textContent = isActive ? 'Branch is Active' : 'Branch is Inactive';

    if (icon) {
        icon.className = isActive ? 'fas fa-check-circle' : 'fas fa-times-circle';
    }
    if (iconBox) {
        iconBox.className = 'status-toggle-icon ' + (isActive ? 'icon-active' : 'icon-inactive');
    }
}

// ============================================================
// RESET
// ============================================================
function resetForm() {
    if (!confirm('Reset all changes back to the original values?')) return;
    window.location.href = window.location.pathname + '?id=<?php echo $id; ?>';
}

// ============================================================
// VALIDATION
// ============================================================
function validateForm() {
    var name = document.getElementById('branchName').value.trim();
    var code = document.getElementById('branchCode').value.trim();

    if (name === '') { alert('Please enter the branch name.'); return false; }
    if (code === '') { alert('Please enter the branch code.'); return false; }

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

    // Email input live-sync
    var emailInput = document.querySelector('input[name="email"]');
    if (emailInput) {
        emailInput.addEventListener('input', updatePreview);
    }

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