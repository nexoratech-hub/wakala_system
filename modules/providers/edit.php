<?php
// ================================================================
// FILE: modules/providers/edit.php
// WAKALA FINANCIAL SYSTEM - EDIT PROVIDER
// RED THEME + WORKING COLOR PICKER + LIVE PREVIEW
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
// GET PROVIDER
// ============================================================
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider ID.';
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM providers WHERE id = ?");
$stmt->execute([$id]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index.php');
    exit();
}

// ============================================================
// LOAD BRANCHES
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// ASSIGNED BRANCHES
// ============================================================
$assigned_branch_ids = [];
try {
    $stmt = $db->prepare("SELECT branch_id FROM branch_providers WHERE provider_id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $assigned_branch_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {
    $assigned_branch_ids = [];
}

// ============================================================
// HANDLE UPDATE
// ============================================================
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_provider') {
    try {
        $db->beginTransaction();

        $provider_name  = trim($_POST['provider_name'] ?? '');
        $provider_code  = trim($_POST['provider_code'] ?? '');
        $provider_type  = $_POST['provider_type'] ?? 'bank';
        $color_code     = trim($_POST['color_code'] ?? '#0B5ED7');
        $icon_class     = trim($_POST['icon_class'] ?? 'fas fa-university');
        $display_order  = intval($_POST['display_order'] ?? 0);
        $description    = trim($_POST['description'] ?? '');
        $is_active      = isset($_POST['is_active']) ? 1 : 0;

        if ($provider_name === '')  throw new Exception('Provider name is required.');
        if ($provider_code === '')  throw new Exception('Provider code is required.');

        if (!in_array($provider_type, ['bank', 'mobile_money', 'other'])) $provider_type = 'bank';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_code)) $color_code = '#0B5ED7';
        if (!preg_match('/^fa[srlb]? fa-[a-z0-9-]+$/', $icon_class)) $icon_class = 'fas fa-university';

        $stmt = $db->prepare("SELECT COUNT(*) FROM providers WHERE provider_name = ? AND id != ?");
        $stmt->execute([$provider_name, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Provider name already used.');

        $stmt = $db->prepare("SELECT COUNT(*) FROM providers WHERE provider_code = ? AND id != ?");
        $stmt->execute([$provider_code, $id]);
        if ($stmt->fetchColumn() > 0) throw new Exception('Provider code already used.');

        // UPDATE provider
        $stmt = $db->prepare("
            UPDATE providers SET
                provider_name = ?, provider_code = ?, provider_type = ?,
                color_code = ?, icon_class = ?, display_order = ?,
                description = ?, is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $provider_name, $provider_code, $provider_type,
            $color_code, $icon_class, $display_order,
            $description ?: null, $is_active, $id
        ]);

        // Branch assignments
        $selected_branch_ids = array_map('intval', $_POST['branch_ids'] ?? []);

        $stmt = $db->prepare("SELECT branch_id FROM branch_providers WHERE provider_id = ?");
        $stmt->execute([$id]);
        $existing_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $to_add    = array_diff($selected_branch_ids, $existing_ids);
        $to_remove = array_diff($existing_ids, $selected_branch_ids);

        if (!empty($to_remove)) {
            $ph = implode(',', array_fill(0, count($to_remove), '?'));
            $stmt = $db->prepare("DELETE FROM branch_providers WHERE provider_id = ? AND branch_id IN ($ph)");
            $stmt->execute(array_merge([$id], array_values($to_remove)));
        }

        foreach ($to_add as $branch_id) {
            $stmt = $db->prepare("SELECT id FROM branch_providers WHERE provider_id = ? AND branch_id = ?");
            $stmt->execute([$id, $branch_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $db->prepare("UPDATE branch_providers SET is_active = 1, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$existing['id']]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO branch_providers (provider_id, branch_id, provider_code, is_active, created_at)
                    VALUES (?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$id, $branch_id, $provider_code]);
            }
        }

        if (function_exists('logActivity')) {
            logActivity($user_id, 'Edit Provider', 'Providers', $id, $provider['provider_code'], 'Updated provider: ' . $provider_name);
        }

        $db->commit();

        $_SESSION['success_message'] = 'Provider "' . $provider_name . '" updated successfully!';
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
$form_name          = $form_data['provider_name']  ?? $provider['provider_name']  ?? '';
$form_code          = $form_data['provider_code']  ?? $provider['provider_code']  ?? '';
$form_type          = $form_data['provider_type']  ?? $provider['provider_type']  ?? 'bank';
$form_color         = $form_data['color_code']     ?? $provider['color_code']     ?? '#0B5ED7';
$form_icon          = $form_data['icon_class']     ?? $provider['icon_class']     ?? 'fas fa-university';
$form_display_order = $form_data['display_order']  ?? $provider['display_order']  ?? 0;
$form_description   = $form_data['description']    ?? $provider['description']    ?? '';
$form_is_active     = isset($form_data['is_active'])
                    ? 1
                    : intval($provider['is_active'] ?? 1);

$form_branch_ids = isset($form_data['branch_ids'])
                 ? array_map('intval', $form_data['branch_ids'])
                 : $assigned_branch_ids;

// Preset colors
$preset_colors = [
    '#0B5ED7', '#DC2626', '#059669', '#D97706',
    '#7C3AED', '#0891B2', '#DB2777', '#65A30D',
    '#EA580C', '#1E40AF', '#475569', '#000000',
];

// Preset icons
$preset_icons = [
    'fas fa-university'       => 'Bank',
    'fas fa-landmark'         => 'Landmark',
    'fas fa-mobile-alt'       => 'Mobile',
    'fas fa-credit-card'      => 'Card',
    'fas fa-wallet'           => 'Wallet',
    'fas fa-money-bill-wave'  => 'Cash',
    'fas fa-coins'            => 'Coins',
    'fas fa-piggy-bank'       => 'Savings',
    'fas fa-chart-line'       => 'Investments',
    'fas fa-hand-holding-usd' => 'Payments',
    'fas fa-exchange-alt'     => 'Transfer',
    'fas fa-building'         => 'Building',
];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-edit"></i> Edit Provider</h2>
                <p class="text-muted">
                    <i class="fas fa-tag"></i>
                    <?php echo htmlspecialchars($provider['provider_code'] ?? 'N/A'); ?>
                    &nbsp;·&nbsp;
                    <?php echo htmlspecialchars($provider['provider_name']); ?>
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

        <form method="POST" action="" id="providerForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="update_provider">

            <!-- ============================================================
            MAIN CARD
            ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-university"></i>
                        </div>
                        <div>
                            <h3>Provider Information</h3>
                            <p>Update provider details</p>
                        </div>
                    </div>
                    <div class="form-card-badge">
                        <i class="fas fa-pen-to-square"></i> Editing
                    </div>
                </div>

                <div class="form-card-body">

                    <!-- Name + Code -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Provider Name <span class="required">*</span></label>
                            <input type="text" name="provider_name" id="providerName"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($form_name); ?>"
                                   placeholder="e.g. CRDB Bank"
                                   required
                                   oninput="updatePreview()">
                        </div>
                        <div class="form-group">
                            <label>Provider Code <span class="required">*</span></label>
                            <input type="text" name="provider_code" id="providerCode"
                                   class="form-control code-input"
                                   value="<?php echo htmlspecialchars($form_code); ?>"
                                   placeholder="e.g. CRDB001"
                                   required
                                   oninput="updatePreview()">
                        </div>
                    </div>

                    <!-- Type selector -->
                    <div class="form-group">
                        <label>Provider Type <span class="required">*</span></label>
                        <div class="type-selector">
                            <label class="type-option">
                                <input type="radio" name="provider_type" value="bank"
                                       <?php echo $form_type === 'bank' ? 'checked' : ''; ?>
                                       onchange="updatePreview()">
                                <div class="type-card type-bank">
                                    <div class="type-icon"><i class="fas fa-landmark"></i></div>
                                    <div class="type-info">
                                        <span class="type-title">Bank</span>
                                        <span class="type-desc">Traditional banking</span>
                                    </div>
                                    <div class="type-check"><i class="fas fa-check-circle"></i></div>
                                </div>
                            </label>

                            <label class="type-option">
                                <input type="radio" name="provider_type" value="mobile_money"
                                       <?php echo $form_type === 'mobile_money' ? 'checked' : ''; ?>
                                       onchange="updatePreview()">
                                <div class="type-card type-mobile">
                                    <div class="type-icon"><i class="fas fa-mobile-alt"></i></div>
                                    <div class="type-info">
                                        <span class="type-title">Mobile Money</span>
                                        <span class="type-desc">Mobile payment</span>
                                    </div>
                                    <div class="type-check"><i class="fas fa-check-circle"></i></div>
                                </div>
                            </label>

                            <label class="type-option">
                                <input type="radio" name="provider_type" value="other"
                                       <?php echo $form_type === 'other' ? 'checked' : ''; ?>
                                       onchange="updatePreview()">
                                <div class="type-card type-other">
                                    <div class="type-icon"><i class="fas fa-coins"></i></div>
                                    <div class="type-info">
                                        <span class="type-title">Other</span>
                                        <span class="type-desc">Other financial services</span>
                                    </div>
                                    <div class="type-check"><i class="fas fa-check-circle"></i></div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="section-divider">
                        <span><i class="fas fa-palette"></i> Appearance</span>
                    </div>

                    <!-- Color picker + Icon -->
                    <div class="form-row">
                        <!-- COLOR PICKER -->
                        <div class="form-group">
                            <label>Brand Color</label>
                            <div class="color-picker-wrapper">

                                <!-- Native color input — click the swatch to open the OS picker -->
                                <label class="color-swatch-label" for="colorPicker" title="Click to pick a color">
                                    <input type="color"
                                           id="colorPicker"
                                           class="color-picker-input"
                                           value="<?php echo htmlspecialchars($form_color); ?>"
                                           oninput="onColorPick(this.value)"
                                           onchange="onColorPick(this.value)">
                                    <span class="color-swatch-preview" id="colorSwatchPreview"
                                          style="background: <?php echo htmlspecialchars($form_color); ?>;">
                                        <i class="fas fa-eye-dropper"></i>
                                    </span>
                                </label>

                                <!-- Hidden field that actually submits -->
                                <input type="hidden" name="color_code" id="colorCodeHidden"
                                       value="<?php echo htmlspecialchars($form_color); ?>">

                                <!-- Hex text input -->
                                <input type="text"
                                       id="colorText"
                                       class="form-control color-text-input"
                                       value="<?php echo htmlspecialchars($form_color); ?>"
                                       placeholder="#0B5ED7"
                                       pattern="^#[0-9A-Fa-f]{6}$"
                                       maxlength="7"
                                       oninput="onColorText(this.value)">
                            </div>
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Click the swatch to open the color picker, or type a hex code.
                            </p>

                            <!-- Preset swatches -->
                            <div class="preset-colors">
                                <?php foreach ($preset_colors as $hex): ?>
                                    <button type="button"
                                            class="preset-color-btn"
                                            style="background: <?php echo $hex; ?>;"
                                            data-color="<?php echo $hex; ?>"
                                            title="<?php echo $hex; ?>"
                                            onclick="pickColor('<?php echo $hex; ?>')"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ICON -->
                        <div class="form-group">
                            <label>Icon</label>
                            <div class="icon-preview-row">
                                <div class="icon-preview-box" id="iconPreviewBox"
                                     style="background: <?php echo htmlspecialchars($form_color); ?>;">
                                    <i class="<?php echo htmlspecialchars($form_icon); ?>" id="iconPreview"></i>
                                </div>
                                <input type="text" name="icon_class" id="iconInput"
                                       class="form-control icon-text-input"
                                       value="<?php echo htmlspecialchars($form_icon); ?>"
                                       placeholder="fas fa-university"
                                       oninput="updatePreview()">
                            </div>
                            <div class="preset-icons">
                                <?php foreach ($preset_icons as $cls => $label): ?>
                                    <button type="button" class="preset-icon-btn"
                                            title="<?php echo $label; ?>"
                                            data-icon="<?php echo $cls; ?>"
                                            onclick="pickIcon('<?php echo $cls; ?>')">
                                        <i class="<?php echo $cls; ?>"></i>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Display order + Active -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Display Order</label>
                            <input type="number" name="display_order" id="displayOrder"
                                   class="form-control"
                                   value="<?php echo intval($form_display_order); ?>"
                                   min="0" max="999"
                                   placeholder="0">
                            <p class="field-hint">
                                <i class="fas fa-info-circle"></i>
                                Lower numbers appear first.
                            </p>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <label class="toggle-switch-wrapper">
                                <input type="checkbox" name="is_active" value="1"
                                       id="isActiveToggle"
                                       <?php echo $form_is_active ? 'checked' : ''; ?>
                                       onchange="updatePreview()">
                                <span class="toggle-switch"></span>
                                <span class="toggle-label" id="toggleLabel">
                                    <?php echo $form_is_active ? 'Active' : 'Inactive'; ?>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="descriptionInput"
                                      class="form-control textarea-control"
                                      placeholder="Optional notes about this provider..."
                                      rows="3"
                                      oninput="updatePreview()"><?php echo htmlspecialchars($form_description); ?></textarea>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ============================================================
            LIVE PREVIEW
            ============================================================ -->
            <div class="form-card preview-card">
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
                        <div class="provider-card-preview" id="previewCard">
                            <div class="preview-accent" id="previewAccent"
                                 style="background: <?php echo htmlspecialchars($form_color); ?>;"></div>
                            <div class="preview-body">
                                <div class="preview-top">
                                    <div class="preview-icon-circle" id="previewIconCircle"
                                         style="background: <?php echo htmlspecialchars($form_color); ?>;">
                                        <i class="<?php echo htmlspecialchars($form_icon); ?>" id="previewIcon"></i>
                                    </div>
                                    <div class="preview-info">
                                        <div class="preview-name" id="previewName">
                                            <?php echo htmlspecialchars($form_name ?: 'Provider Name'); ?>
                                        </div>
                                        <div class="preview-code" id="previewCode">
                                            <?php echo htmlspecialchars($form_code ?: 'CODE-000'); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-meta">
                                    <span class="preview-type-badge" id="previewType">
                                        <i class="fas fa-landmark"></i> Bank
                                    </span>
                                    <span class="preview-status" id="previewStatus">
                                        <i class="fas fa-check-circle"></i> Active
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
            BRANCH ASSIGNMENT
            ============================================================ -->
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-left">
                        <div class="form-card-icon">
                            <i class="fas fa-store-alt"></i>
                        </div>
                        <div>
                            <h3>Branch Assignment</h3>
                            <p>Select which branches can use this provider</p>
                        </div>
                    </div>
                    <div class="form-card-badge" id="branchCountBadge">
                        <i class="fas fa-check"></i>
                        <span id="branchCount"><?php echo count($form_branch_ids); ?></span> selected
                    </div>
                </div>

                <div class="form-card-body">
                    <?php if (count($branches) > 0): ?>
                        <div class="branch-selector-toolbar">
                            <button type="button" class="btn-mini" onclick="selectAllBranches()">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" class="btn-mini" onclick="clearAllBranches()">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>

                        <div class="branches-grid">
                            <?php foreach ($branches as $b):
                                $is_selected = in_array(intval($b['id']), $form_branch_ids);
                            ?>
                            <label class="branch-option <?php echo $is_selected ? 'selected' : ''; ?>">
                                <input type="checkbox" name="branch_ids[]"
                                       value="<?php echo $b['id']; ?>"
                                       class="branch-checkbox"
                                       <?php echo $is_selected ? 'checked' : ''; ?>
                                       onchange="toggleBranchOption(this)">
                                <div class="branch-check-icon">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="branch-option-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="branch-option-info">
                                    <span class="branch-option-name">
                                        <?php echo htmlspecialchars($b['branch_name']); ?>
                                    </span>
                                    <span class="branch-option-code">
                                        <?php echo htmlspecialchars($b['branch_code'] ?? '-'); ?>
                                    </span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-branches">
                            <i class="fas fa-store-slash"></i>
                            <p>No active branches available</p>
                        </div>
                    <?php endif; ?>
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
    font-family: 'Courier New', monospace;
    font-weight: 600;
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
}
.textarea-control {
    resize: vertical;
    min-height: 80px;
    font-family: 'Inter', sans-serif;
    line-height: 1.6;
}
.field-hint {
    font-size: 11px; color: var(--text-muted);
    margin: 4px 0 0 0;
    display: flex; align-items: center; gap: 5px;
    font-weight: 500; line-height: 1.5;
}
.field-hint i { font-size: 10px; color: var(--red-primary); }

/* TYPE SELECTOR */
.type-selector {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.type-option { cursor: pointer; position: relative; }
.type-option input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.type-card {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px; border-radius: 12px;
    border: 2px solid var(--border-color);
    background: var(--bg-input);
    transition: all 0.3s ease; min-width: 0;
    position: relative;
}
.type-card:hover { border-color: #FCA5A5; transform: translateY(-2px); }
.type-option input[type="radio"]:checked + .type-card {
    border-color: #DC2626;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.2);
}
html.dark-mode .type-option input[type="radio"]:checked + .type-card {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
}
.type-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.type-bank .type-icon {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    color: #1D4ED8;
}
.type-mobile .type-icon {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    color: #059669;
}
.type-other .type-icon {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    color: #D97706;
}
html.dark-mode .type-bank .type-icon { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .type-mobile .type-icon { background: #065F46; color: #34D399; }
html.dark-mode .type-other .type-icon { background: #5F3A1E; color: #FBBF24; }
.type-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1; }
.type-title { font-size: 14px; font-weight: 800; color: var(--text-primary); }
.type-desc { font-size: 11px; font-weight: 500; color: var(--text-muted); }
.type-check {
    position: absolute;
    top: 8px; right: 8px;
    width: 22px; height: 22px;
    background: #DC2626;
    color: #FFFFFF;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px;
    opacity: 0;
    transform: scale(0.5);
    transition: all 0.2s ease;
}
.type-option input[type="radio"]:checked + .type-card .type-check {
    opacity: 1; transform: scale(1);
}

/* ============================================================
   COLOR PICKER — FULLY WORKING
   ============================================================ */
.color-picker-wrapper {
    display: flex;
    gap: 10px;
    align-items: stretch;
    flex-wrap: wrap;
}

.color-swatch-label {
    display: block;
    cursor: pointer;
    flex-shrink: 0;
    position: relative;
}

/* The real color input — hidden but clickable via label */
.color-picker-input {
    position: absolute;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    border: none;
    padding: 0;
    margin: 0;
    z-index: 2;
}

.color-swatch-preview {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 48px;
    border-radius: 10px;
    border: 2px solid var(--border-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    transition: all 0.2s ease;
    font-size: 20px;
    color: #FFFFFF;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.35);
    position: relative;
    overflow: hidden;
}
.color-swatch-preview::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, rgba(0,0,0,0.15) 100%);
    pointer-events: none;
}
.color-swatch-preview i {
    position: relative;
    z-index: 1;
    pointer-events: none;
}
.color-swatch-label:hover .color-swatch-preview {
    transform: translateY(-2px) scale(1.03);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
    border-color: #DC2626;
}
.color-swatch-label:active .color-swatch-preview {
    transform: translateY(0);
}

.color-text-input {
    flex: 1;
    min-width: 140px;
    height: 48px;
    font-family: 'Courier New', monospace !important;
    font-weight: 800 !important;
    font-size: 14px !important;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* Preset colors */
.preset-colors {
    display: flex; flex-wrap: wrap; gap: 8px;
    margin-top: 12px;
}
.preset-color-btn {
    width: 34px; height: 34px;
    border-radius: 8px;
    border: 2px solid var(--border-color);
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    padding: 0;
}
.preset-color-btn:hover {
    transform: scale(1.15) translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    border-color: #FFFFFF;
}
.preset-color-btn.active {
    border-color: #FFFFFF;
    box-shadow: 0 0 0 3px #DC2626, 0 4px 12px rgba(0, 0, 0, 0.2);
}

/* ICON PICKER */
.icon-preview-row {
    display: flex;
    gap: 10px;
    align-items: stretch;
}
.icon-preview-box {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FFFFFF;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
.icon-text-input {
    flex: 1;
    min-width: 0;
    font-family: 'Courier New', monospace !important;
    font-weight: 700 !important;
    font-size: 12px !important;
}
.preset-icons {
    display: flex; flex-wrap: wrap; gap: 6px;
    margin-top: 10px;
}
.preset-icon-btn {
    width: 38px; height: 38px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-input);
    color: var(--text-secondary);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    transition: all 0.2s ease;
}
.preset-icon-btn:hover {
    background: #FEF2F2;
    border-color: #DC2626;
    color: #DC2626;
    transform: translateY(-2px);
}
.preset-icon-btn.active {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border-color: #DC2626;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}
html.dark-mode .preset-icon-btn { background: #1e293b; }
html.dark-mode .preset-icon-btn:hover { background: #5F1E1E; }

/* TOGGLE SWITCH */
.toggle-switch-wrapper {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    padding: 8px 4px;
    user-select: none;
    align-self: flex-start;
}
.toggle-switch-wrapper input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.toggle-switch {
    width: 52px;
    height: 28px;
    background: #D1D5DB;
    border-radius: 14px;
    position: relative;
    transition: all 0.3s ease;
    flex-shrink: 0;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
}
.toggle-switch::before {
    content: '';
    position: absolute;
    top: 3px; left: 3px;
    width: 22px; height: 22px;
    background: #FFFFFF;
    border-radius: 50%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}
.toggle-switch-wrapper input[type="checkbox"]:checked + .toggle-switch {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.toggle-switch-wrapper input[type="checkbox"]:checked + .toggle-switch::before {
    transform: translateX(24px);
}
.toggle-label {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
}
html.dark-mode .toggle-switch { background: #475569; }

/* SECTION DIVIDER */
.section-divider {
    display: flex; align-items: center; gap: 12px;
    margin: 24px 0 18px 0;
}
.section-divider::before,
.section-divider::after {
    content: ''; flex: 1; height: 1.5px;
    background: linear-gradient(90deg, transparent, var(--border-color), transparent);
}
.section-divider span {
    font-size: 12px; font-weight: 800;
    color: var(--red-primary);
    text-transform: uppercase; letter-spacing: 1.2px;
    display: flex; align-items: center; gap: 6px;
    padding: 0 8px; white-space: nowrap;
}

/* ============================================================
   LIVE PREVIEW
   ============================================================ */
.preview-center {
    display: flex;
    justify-content: center;
    padding: 20px 0;
}
.provider-card-preview {
    width: 100%;
    max-width: 340px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}
.preview-accent {
    height: 6px;
    width: 100%;
    transition: background 0.2s ease;
}
.preview-body {
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.preview-top {
    display: flex;
    align-items: center;
    gap: 14px;
}
.preview-icon-circle {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 24px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: background 0.2s ease;
}
.preview-info { display: flex; flex-direction: column; gap: 6px; min-width: 0; flex: 1; }
.preview-name {
    font-size: 15px;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    word-break: break-word;
}
.preview-code {
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    color: #DC2626;
    background: #FEF2F2;
    padding: 3px 10px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    align-self: flex-start;
    border: 1px solid #FCA5A5;
    width: fit-content;
    text-transform: uppercase;
}
html.dark-mode .preview-code { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
.preview-meta {
    display: flex; flex-wrap: wrap; gap: 8px;
}
.preview-type-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    background: #DBEAFE; color: #1D4ED8;
    border: 1.5px solid #BFDBFE;
}
.preview-type-badge.mobile {
    background: #D1FAE5; color: #059669;
    border-color: #A7F3D0;
}
.preview-type-badge.other {
    background: #FEF3C7; color: #D97706;
    border-color: #FDE68A;
}
.preview-status {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.4px;
    background: #D1FAE5; color: #059669;
    border: 1.5px solid #A7F3D0;
}
.preview-status.inactive {
    background: #F3F4F6; color: #6B7280;
    border-color: #E5E7EB;
}
html.dark-mode .preview-status { background: #065F46; color: #34D399; border-color: #10B981; }
html.dark-mode .preview-status.inactive { background: #334155; color: #94A3B8; border-color: #475569; }

/* ============================================================
   BRANCH ASSIGNMENT
   ============================================================ */
.branch-selector-toolbar {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.btn-mini {
    padding: 8px 16px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-input);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
}
.btn-mini:hover {
    background: #FEF2F2; color: #DC2626;
    border-color: #DC2626;
    transform: translateY(-1px);
}
html.dark-mode .btn-mini { background: #1e293b; }
html.dark-mode .btn-mini:hover { background: #5F1E1E; color: #FCA5A5; border-color: #DC2626; }

.branches-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px;
}
.branch-option {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    border: 2px solid var(--border-color);
    background: var(--bg-input);
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    min-width: 0;
}
.branch-option:hover {
    border-color: #FCA5A5;
    transform: translateY(-2px);
}
.branch-option.selected {
    border-color: #DC2626;
    background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
    box-shadow: 0 4px 16px rgba(220, 38, 38, 0.15);
}
html.dark-mode .branch-option.selected {
    background: linear-gradient(135deg, #5F1E1E 0%, #7F1D1D 100%);
}
.branch-checkbox {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.branch-check-icon {
    width: 24px; height: 24px;
    border-radius: 6px;
    border: 2px solid var(--border-color);
    background: #FFFFFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
    color: transparent;
    flex-shrink: 0;
    transition: all 0.2s ease;
}
html.dark-mode .branch-check-icon { background: #1e293b; }
.branch-option.selected .branch-check-icon {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-color: #DC2626;
    color: #FFFFFF;
}
.branch-option-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(220, 38, 38, 0.25);
}
.branch-option-info {
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0; flex: 1;
}
.branch-option-name {
    font-size: 13px;
    font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.branch-option-code {
    font-size: 10px;
    font-weight: 800;
    color: #DC2626;
    font-family: 'Courier New', monospace;
    background: #FEF2F2;
    padding: 2px 8px;
    border-radius: 5px;
    align-self: flex-start;
    border: 1px solid #FCA5A5;
}
html.dark-mode .branch-option-code { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }

.empty-branches {
    padding: 50px 20px;
    text-align: center;
    color: var(--text-muted);
}
.empty-branches i {
    font-size: 48px;
    color: #FCA5A5;
    opacity: 0.5;
    display: block;
    margin-bottom: 12px;
}
.empty-branches p {
    font-size: 14px;
    margin: 0;
}

/* ============================================================
   FORM ACTIONS
   ============================================================ */
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

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .form-row { grid-template-columns: 1fr; }
    .type-selector { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .form-card-body { padding: 18px; }
    .form-card-header { padding: 16px 20px; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }
    .branches-grid { grid-template-columns: 1fr; }
    .form-actions { flex-direction: column; position: static; padding: 16px; }
    .btn-secondary-large, .btn-reset-large, .btn-submit-large { width: 100%; }
}
@media (max-width: 480px) {
    .color-text-input { font-size: 12px !important; }
    .icon-text-input { font-size: 11px !important; }
    .color-swatch-preview { width: 56px; height: 44px; font-size: 17px; }
}
</style>

<script>
// ============================================================
// COLOR PICKER
// ============================================================
function onColorPick(hex) {
    if (!hex) return;
    hex = hex.toUpperCase();

    // Update hidden field for form submission
    var hidden = document.getElementById('colorCodeHidden');
    if (hidden) hidden.value = hex;

    // Update text input
    var textInput = document.getElementById('colorText');
    if (textInput) textInput.value = hex;

    // Update the swatch preview background
    var swatch = document.getElementById('colorSwatchPreview');
    if (swatch) swatch.style.background = hex;

    // Update icon preview box background
    var iconBox = document.getElementById('iconPreviewBox');
    if (iconBox) iconBox.style.background = hex;

    // Update preview card accent + icon circle
    var accent = document.getElementById('previewAccent');
    if (accent) accent.style.background = hex;

    var iconCircle = document.getElementById('previewIconCircle');
    if (iconCircle) iconCircle.style.background = hex;

    // Highlight the matching preset swatch
    highlightPreset(hex);

    updatePreview();
}

function onColorText(value) {
    value = (value || '').trim();
    if (!/^#[0-9A-Fa-f]{6}$/.test(value)) return;
    var hex = value.toUpperCase();

    // Sync native color input
    var picker = document.getElementById('colorPicker');
    if (picker) picker.value = hex;

    // Sync hidden field
    var hidden = document.getElementById('colorCodeHidden');
    if (hidden) hidden.value = hex;

    // Sync swatch preview
    var swatch = document.getElementById('colorSwatchPreview');
    if (swatch) swatch.style.background = hex;

    var iconBox = document.getElementById('iconPreviewBox');
    if (iconBox) iconBox.style.background = hex;

    var accent = document.getElementById('previewAccent');
    if (accent) accent.style.background = hex;

    var iconCircle = document.getElementById('previewIconCircle');
    if (iconCircle) iconCircle.style.background = hex;

    highlightPreset(hex);
    updatePreview();
}

function pickColor(hex) {
    hex = hex.toUpperCase();

    var picker = document.getElementById('colorPicker');
    if (picker) picker.value = hex;

    onColorPick(hex);
}

function highlightPreset(hex) {
    hex = (hex || '').toLowerCase();
    document.querySelectorAll('.preset-color-btn').forEach(function(btn) {
        var c = (btn.getAttribute('data-color') || '').toLowerCase();
        if (c === hex) btn.classList.add('active');
        else btn.classList.remove('active');
    });
}

// ============================================================
// ICON PICKER
// ============================================================
function pickIcon(cls) {
    var input = document.getElementById('iconInput');
    if (input) input.value = cls;

    document.querySelectorAll('.preset-icon-btn').forEach(function(btn) {
        if (btn.getAttribute('data-icon') === cls) btn.classList.add('active');
        else btn.classList.remove('active');
    });

    updatePreview();
}

// ============================================================
// LIVE PREVIEW
// ============================================================
function updatePreview() {
    var name = (document.getElementById('providerName')?.value || 'Provider Name').trim();
    var code = (document.getElementById('providerCode')?.value || 'CODE-000').trim();
    var icon = (document.getElementById('iconInput')?.value || 'fas fa-university').trim();
    var isActive = document.getElementById('isActiveToggle')?.checked;

    var type = 'bank';
    var typeInput = document.querySelector('input[name="provider_type"]:checked');
    if (typeInput) type = typeInput.value;

    // Preview name
    var nameEl = document.getElementById('previewName');
    if (nameEl) nameEl.textContent = name || 'Provider Name';

    // Preview code
    var codeEl = document.getElementById('previewCode');
    if (codeEl) codeEl.textContent = code || 'CODE-000';

    // Preview icon
    var iconEl = document.getElementById('previewIcon');
    if (iconEl) iconEl.className = icon;

    // Also update icon preview box
    var iconPreview = document.getElementById('iconPreview');
    if (iconPreview) iconPreview.className = icon;

    // Preview type badge
    var typeEl = document.getElementById('previewType');
    if (typeEl) {
        typeEl.classList.remove('mobile', 'other');
        var typeIcon = 'fa-landmark';
        var typeLabel = 'Bank';

        if (type === 'mobile_money') {
            typeEl.classList.add('mobile');
            typeIcon = 'fa-mobile-alt';
            typeLabel = 'Mobile Money';
        } else if (type === 'other') {
            typeEl.classList.add('other');
            typeIcon = 'fa-coins';
            typeLabel = 'Other';
        }
        typeEl.innerHTML = '<i class="fas ' + typeIcon + '"></i> ' + typeLabel;
    }

    // Preview status
    var statusEl = document.getElementById('previewStatus');
    if (statusEl) {
        if (isActive) {
            statusEl.classList.remove('inactive');
            statusEl.innerHTML = '<i class="fas fa-check-circle"></i> Active';
        } else {
            statusEl.classList.add('inactive');
            statusEl.innerHTML = '<i class="fas fa-times-circle"></i> Inactive';
        }
    }

    // Toggle label
    var toggleLabel = document.getElementById('toggleLabel');
    if (toggleLabel) toggleLabel.textContent = isActive ? 'Active' : 'Inactive';
}

// ============================================================
// BRANCH SELECTION
// ============================================================
function toggleBranchOption(checkbox) {
    var label = checkbox.closest('.branch-option');
    if (!label) return;
    if (checkbox.checked) label.classList.add('selected');
    else label.classList.remove('selected');
    updateBranchCount();
}

function selectAllBranches() {
    document.querySelectorAll('.branch-checkbox').forEach(function(cb) {
        cb.checked = true;
        var label = cb.closest('.branch-option');
        if (label) label.classList.add('selected');
    });
    updateBranchCount();
}

function clearAllBranches() {
    document.querySelectorAll('.branch-checkbox').forEach(function(cb) {
        cb.checked = false;
        var label = cb.closest('.branch-option');
        if (label) label.classList.remove('selected');
    });
    updateBranchCount();
}

function updateBranchCount() {
    var count = document.querySelectorAll('.branch-checkbox:checked').length;
    var el = document.getElementById('branchCount');
    if (el) el.textContent = count;
}

// ============================================================
// RESET FORM
// ============================================================
function resetForm() {
    if (!confirm('Reset all changes back to the original values?')) return;
    window.location.href = window.location.pathname + '?id=<?php echo $id; ?>';
}

// ============================================================
// VALIDATION
// ============================================================
function validateForm() {
    var name = document.getElementById('providerName').value.trim();
    var code = document.getElementById('providerCode').value.trim();

    if (name === '') { alert('Please enter the provider name.'); return false; }
    if (code === '') { alert('Please enter the provider code.'); return false; }

    // Make sure hidden color field has a value
    var hidden = document.getElementById('colorCodeHidden');
    var picker = document.getElementById('colorPicker');
    if (hidden && (!hidden.value || hidden.value.trim() === '')) {
        hidden.value = picker.value || '#0B5ED7';
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
    // Mark active preset color + icon
    var initialColor = document.getElementById('colorPicker')?.value || '#0B5ED7';
    highlightPreset(initialColor.toUpperCase());

    var initialIcon = document.getElementById('iconInput')?.value || '';
    document.querySelectorAll('.preset-icon-btn').forEach(function(btn) {
        if (btn.getAttribute('data-icon') === initialIcon) btn.classList.add('active');
    });

    updateBranchCount();
    updatePreview();

    // Sync hidden color field on form submit as a safety net
    var form = document.getElementById('providerForm');
    if (form) {
        form.addEventListener('submit', function() {
            var hidden = document.getElementById('colorCodeHidden');
            var picker = document.getElementById('colorPicker');
            if (hidden && picker) hidden.value = picker.value.toUpperCase();
        });
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