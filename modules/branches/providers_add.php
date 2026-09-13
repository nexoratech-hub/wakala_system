<?php
// ================================================================
// FILE: modules/branches/providers_add.php
// WAKALA FINANCIAL SYSTEM - ADD BRANCH PROVIDERS
// RED THEME + MODERN CARDS + MULTI-SELECT + LIVE VALIDATION
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
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================================
// GET BRANCH
// ============================================================
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
if ($branch_id <= 0) {
    header('Location: index.php');
    exit();
}

$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    header('Location: index.php');
    exit();
}

// ============================================================
// LOAD PROVIDERS
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order ASC, provider_name ASC");
$stmt->execute();
$all_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Existing assignments
$stmt = $db->prepare("SELECT provider_id, provider_code FROM branch_providers WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

$existing_by_provider = [];
$all_existing_codes = [];
foreach ($existing as $e) {
    $existing_by_provider[intval($e['provider_id'])][] = $e['provider_code'];
    $all_existing_codes[] = strtoupper($e['provider_code']);
}

// ============================================================
// HANDLE SUBMISSION
// ============================================================
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_providers') {
    try {
        $db->beginTransaction();

        $selected = $_POST['providers'] ?? [];
        $codes    = $_POST['provider_codes'] ?? [];

        if (empty($selected)) {
            throw new Exception('Please select at least one provider.');
        }

        $added = 0;
        $errors = [];

        // Track codes being added in this batch to prevent internal duplicates
        $batch_codes = [];

        foreach ($selected as $provider_id) {
            $provider_id = intval($provider_id);
            $code = strtoupper(trim($codes[$provider_id] ?? ''));

            // Find provider name
            $pname = 'Unknown';
            foreach ($all_providers as $p) {
                if (intval($p['id']) === $provider_id) {
                    $pname = $p['provider_name'];
                    break;
                }
            }

            if ($code === '') {
                $errors[] = "Provider code is required for $pname.";
                continue;
            }

            // Check duplicate in existing
            if (in_array($code, $all_existing_codes)) {
                $errors[] = "Code '$code' already exists in this branch ($pname).";
                continue;
            }

            // Check duplicate within batch
            if (in_array($code, $batch_codes)) {
                $errors[] = "Code '$code' is used twice in this batch.";
                continue;
            }

            $batch_codes[] = $code;

            // Insert
            $stmt = $db->prepare("
                INSERT INTO branch_providers 
                (branch_id, provider_id, provider_code, is_active, created_at)
                VALUES (?, ?, ?, 1, NOW())
            ");
            $stmt->execute([$branch_id, $provider_id, $code]);

            $added++;
        }

        if ($added === 0) {
            throw new Exception(implode(' ', $errors) ?: 'No providers were added.');
        }

        if (function_exists('logActivity')) {
            logActivity(
                $user_id,
                'Add Branch Providers',
                'Branches',
                $branch_id,
                $branch['branch_code'] ?? '',
                "Added $added provider(s) to {$branch['branch_name']}"
            );
        }

        $db->commit();

        $_SESSION['success_message'] = $added . ' provider(s) added successfully!' . 
            (!empty($errors) ? ' ' . count($errors) . ' skipped.' : '');
        if (!empty($errors)) {
            $_SESSION['error_message'] = implode(' ', $errors);
        }

        header('Location: providers.php?branch_id=' . $branch_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
        $form_data = $_POST;
    }
}

// ============================================================
// FLASH
// ============================================================
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

// Stats
$total_providers = count($all_providers);
$total_existing = count($existing);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle"></i> Add Providers to Branch</h2>
                <p class="text-muted">Select one or more providers and assign unique codes</p>
            </div>
            <div class="header-right">
                <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back
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

        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Adding to</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                    <?php if (!empty($branch['branch_code'])): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($branch['location'])): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch['location']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-stats">
                <div class="bi-stat">
                    <span class="bi-stat-num"><?php echo $total_existing; ?></span>
                    <span class="bi-stat-label">Assigned</span>
                </div>
                <div class="bi-divider"></div>
                <div class="bi-stat">
                    <span class="bi-stat-num"><?php echo $total_providers; ?></span>
                    <span class="bi-stat-label">Available</span>
                </div>
            </div>
        </div>

        <!-- INFO NOTE -->
        <div class="info-banner">
            <div class="info-banner-icon">
                <i class="fas fa-lightbulb"></i>
            </div>
            <div class="info-banner-text">
                <strong>Tip:</strong>
                You can add the same provider multiple times with <strong>different codes</strong> (e.g., 3 M-Pesa lines).
                Each code must be <strong>unique within this branch</strong>.
            </div>
        </div>

        <!-- FORM -->
        <form method="POST" action="" id="providerForm" onsubmit="return validateForm(event)">
            <input type="hidden" name="action" value="add_providers">

            <!-- ============================================================
            RED TOOLBAR: search + < > scroll + select controls
            ============================================================ -->
            <div class="providers-toolbar">
                <div class="providers-toolbar-inner">

                    <!-- LEFT: Search -->
                    <div class="toolbar-search">
                        <i class="fas fa-search toolbar-search-icon"></i>
                        <input type="text"
                               id="providerSearch"
                               class="toolbar-search-input"
                               placeholder="Search provider..."
                               oninput="filterProviders(this.value)">
                        <button type="button" class="toolbar-search-clear"
                                id="providerSearchClear"
                                onclick="clearProviderSearch()"
                                style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <!-- CENTER: < > scroll -->
                    <div class="toolbar-scroll-center">
                        <button type="button" class="toolbar-scroll-btn" onclick="scrollProviders('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="toolbar-scroll-label">
                            <i class="fas fa-arrows-alt-h"></i> SCROLL
                        </span>
                        <button type="button" class="toolbar-scroll-btn" onclick="scrollProviders('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>

                    <!-- RIGHT: Select controls -->
                    <div class="toolbar-filters">
                        <button type="button" class="toolbar-btn toolbar-btn-light" onclick="selectAll()">
                            <i class="fas fa-check-double"></i> All
                        </button>
                        <button type="button" class="toolbar-btn toolbar-btn-light" onclick="deselectAll()">
                            <i class="fas fa-times"></i> None
                        </button>
                        <span class="toolbar-count">
                            <i class="fas fa-check-circle"></i>
                            <strong id="selectedCount">0</strong> selected
                        </span>
                    </div>

                </div>
            </div>

            <!-- ============================================================
            PROVIDERS GRID
            ============================================================ -->
            <div class="providers-wrapper" id="providersWrapper">
                <?php if (count($all_providers) > 0): ?>
                <div class="providers-grid" id="providersGrid">
                    <?php foreach ($all_providers as $p):
                        $pid = intval($p['id']);
                        $p_color = $p['color_code'] ?? '#0B5ED7';
                        $p_icon = $p['icon_class'] ?? 'fas fa-university';
                        $p_type = $p['provider_type'] ?? 'bank';
                        $p_code = $p['provider_code'] ?? '-';
                        $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                        $p_type_icon = $p_type === 'mobile_money' ? 'fa-mobile-alt' : ($p_type === 'other' ? 'fa-coins' : 'fa-landmark');
                        $existing_codes = $existing_by_provider[$pid] ?? [];
                        $has_existing = !empty($existing_codes);
                        $initial = strtoupper(substr($p['provider_name'] ?? 'P', 0, 1));
                        $search_data = strtolower(($p['provider_name'] ?? '') . ' ' . $p_code . ' ' . $p_type_label);
                    ?>
                    <div class="provider-card"
                         data-provider-id="<?php echo $pid; ?>"
                         data-search="<?php echo htmlspecialchars($search_data); ?>">

                        <!-- Checkbox overlay (clickable area) -->
                        <label class="provider-card-checkbox-overlay" for="provider_<?php echo $pid; ?>">
                            <input type="checkbox"
                                   id="provider_<?php echo $pid; ?>"
                                   name="providers[]"
                                   value="<?php echo $pid; ?>"
                                   class="provider-checkbox"
                                   onchange="onProviderToggle(<?php echo $pid; ?>)">
                            <span class="custom-checkbox">
                                <i class="fas fa-check"></i>
                            </span>
                        </label>

                        <!-- Accent bar -->
                        <div class="provider-card-accent" style="background: <?php echo htmlspecialchars($p_color); ?>;"></div>

                        <div class="provider-card-body">

                            <div class="provider-card-top">
                                <div class="provider-icon-circle" style="background: <?php echo htmlspecialchars($p_color); ?>;">
                                    <i class="<?php echo htmlspecialchars($p_icon); ?>"></i>
                                </div>
                                <div class="provider-info">
                                    <div class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></div>
                                    <div class="provider-code-chip">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($p_code); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="provider-meta-row">
                                <span class="type-pill type-<?php echo $p_type; ?>">
                                    <i class="fas <?php echo $p_type_icon; ?>"></i>
                                    <?php echo htmlspecialchars($p_type_label); ?>
                                </span>
                                <?php if ($has_existing): ?>
                                    <span class="existing-pill">
                                        <i class="fas fa-layer-group"></i>
                                        <?php echo count($existing_codes); ?> existing
                                    </span>
                                <?php else: ?>
                                    <span class="available-pill">
                                        <i class="fas fa-plus-circle"></i>
                                        Available
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Existing codes -->
                            <?php if ($has_existing): ?>
                            <div class="existing-codes-box">
                                <div class="existing-codes-label">
                                    <i class="fas fa-info-circle"></i>
                                    Existing codes:
                                </div>
                                <div class="existing-codes-chips">
                                    <?php foreach ($existing_codes as $ec): ?>
                                        <span class="existing-code-chip"><?php echo htmlspecialchars($ec); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Code input (revealed when checked) -->
                            <div class="code-input-section" id="code_input_<?php echo $pid; ?>" style="display:none;">
                                <label class="code-input-label">
                                    <i class="fas fa-barcode"></i>
                                    Enter new code for this branch
                                </label>
                                <div class="code-input-wrapper">
                                    <span class="code-input-icon"><i class="fas fa-tag"></i></span>
                                    <input type="text"
                                           id="code_<?php echo $pid; ?>"
                                           name="provider_codes[<?php echo $pid; ?>]"
                                           class="form-control code-input-field"
                                           placeholder="e.g. <?php echo strtoupper(substr($p_code, 0, 4)) . '-' . strtoupper(substr($branch['branch_code'] ?? 'BR', 0, 3)) . '-01'; ?>"
                                           data-provider="<?php echo $pid; ?>"
                                           oninput="checkCodeDuplicate(<?php echo $pid; ?>)"
                                           autocomplete="off">
                                    <button type="button"
                                            class="btn-suggest"
                                            onclick="suggestCode(<?php echo $pid; ?>, '<?php echo addslashes($p_code); ?>')"
                                            title="Auto-generate code">
                                        <i class="fas fa-magic"></i>
                                    </button>
                                </div>
                                <div class="code-warning" id="code_warning_<?php echo $pid; ?>" style="display:none;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <span>This code already exists in this branch</span>
                                </div>
                            </div>

                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-university"></i>
                    <h3>No Providers Available</h3>
                    <p>Add providers to the system first.</p>
                    <a href="../providers/add.php" class="btn-add-empty">
                        <i class="fas fa-plus-circle"></i> Add Provider
                    </a>
                </div>
                <?php endif; ?>

                <!-- No search results -->
                <div class="no-results" id="noResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <p>No providers match your search</p>
                    <button type="button" class="btn-clear-search" onclick="clearProviderSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>

            <!-- ============================================================
            STICKY ACTIONS
            ============================================================ -->
            <div class="form-actions">
                <a href="providers.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary-large">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="btn btn-submit-large" id="submitBtn">
                    <i class="fas fa-save"></i> Assign Selected
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
.branch-indicator-stats {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 18px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    position: relative; z-index: 1;
    flex-wrap: wrap;
}
.bi-stat {
    display: flex; flex-direction: column;
    align-items: center; gap: 2px;
}
.bi-stat-num {
    font-size: 17px; font-weight: 900;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    line-height: 1.1;
}
.bi-stat-label {
    font-size: 9px; font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.bi-divider {
    width: 1px; height: 28px;
    background: rgba(255, 255, 255, 0.15);
}

/* INFO BANNER */
.info-banner {
    display: flex; gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    margin-bottom: 16px;
    align-items: center;
}
html.dark-mode .info-banner {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #D97706;
}
.info-banner-icon {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: #FFFFFF;
    color: #D97706;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(217, 119, 6, 0.2);
}
html.dark-mode .info-banner-icon { background: #1e293b; }
.info-banner-text {
    font-size: 13px;
    color: #78350F;
    line-height: 1.5;
    flex: 1;
}
html.dark-mode .info-banner-text { color: #FDE68A; }
.info-banner-text strong { font-weight: 800; }

/* RED TOOLBAR */
.providers-toolbar {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 50%, #991B1B 100%);
    border-radius: 12px 12px 0 0;
    padding: 12px 18px;
    position: relative;
    overflow: hidden;
}
.providers-toolbar::before {
    content: ''; position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.07);
    border-radius: 50%; pointer-events: none;
}
.providers-toolbar-inner {
    display: flex; align-items: center;
    gap: 12px; flex-wrap: wrap;
    position: relative; z-index: 1;
    justify-content: space-between;
}

/* Search */
.toolbar-search {
    position: relative;
    display: flex; align-items: center;
    flex: 0 1 240px;
    max-width: 240px; min-width: 180px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 8px;
    padding: 0 10px;
    height: 36px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.2s ease;
    border: 2px solid transparent;
}
.toolbar-search:focus-within {
    background: #FFFFFF;
    border-color: #FCD34D;
    box-shadow: 0 3px 14px rgba(252, 211, 77, 0.5);
}
html.dark-mode .toolbar-search { background: rgba(30, 41, 59, 0.98); }
.toolbar-search-icon {
    color: #DC2626; font-size: 12px;
    flex-shrink: 0; margin-right: 8px;
}
.toolbar-search-input {
    flex: 1; border: none; background: transparent;
    padding: 0; outline: none;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    color: #1f2937; min-width: 0;
}
html.dark-mode .toolbar-search-input { color: #f1f5f9; }
.toolbar-search-input::placeholder { color: #9ca3af; font-size: 11px; }
.toolbar-search-clear {
    width: 18px; height: 18px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 8px;
    flex-shrink: 0; margin-left: 6px;
}
.toolbar-search-clear:hover { background: #DC2626; color: #FFFFFF; transform: scale(1.1); }

/* Scroll center */
.toolbar-scroll-center {
    display: flex; align-items: center; justify-content: center;
    gap: 8px; flex: 1; min-width: 0; padding: 0 8px;
}
.toolbar-scroll-btn {
    width: 34px; height: 34px;
    border-radius: 8px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF; color: #DC2626;
    cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800;
    transition: all 0.2s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0; padding: 0; line-height: 1;
}
.toolbar-scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.toolbar-scroll-btn i { font-size: 12px; display: block; line-height: 1; }
.toolbar-scroll-label {
    font-size: 10px; font-weight: 800;
    color: #FCD34D; text-transform: uppercase;
    letter-spacing: 1px;
    display: flex; align-items: center; gap: 5px;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
    padding: 0 4px;
}
.toolbar-scroll-label i { font-size: 10px; color: #FCD34D; }

/* Filters */
.toolbar-filters {
    display: flex; gap: 6px;
    flex-shrink: 0; flex-wrap: wrap;
    align-items: center;
}
.toolbar-btn {
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    font-weight: 700; font-size: 11.5px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.toolbar-btn:hover {
    background: rgba(255, 255, 255, 0.28);
    border-color: #FCD34D;
}
.toolbar-count {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.2);
    color: #FFFFFF;
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    white-space: nowrap;
}
.toolbar-count i { color: #FCD34D; font-size: 12px; }
.toolbar-count strong { font-size: 14px; font-weight: 900; }

/* PROVIDERS WRAPPER */
.providers-wrapper {
    background: var(--bg-card);
    border-radius: 0 0 14px 14px;
    border: 1.5px solid var(--border-color);
    border-top: none;
    padding: 20px;
    overflow-x: auto;
    scroll-behavior: smooth;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.providers-wrapper::-webkit-scrollbar { height: 8px; }
.providers-wrapper::-webkit-scrollbar-track { background: var(--bg-input); border-radius: 4px; }
.providers-wrapper::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
    min-width: min-content;
}

/* PROVIDER CARD */
.provider-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 2px solid var(--border-color);
    overflow: hidden;
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
    min-width: 0;
    position: relative;
}
.provider-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
    border-color: #FCA5A5;
}
.provider-card.hidden-by-search { display: none !important; }

/* Selected state */
.provider-card:has(.provider-checkbox:checked) {
    border-color: #DC2626;
    background: linear-gradient(180deg, #FEF2F2 0%, var(--bg-card) 60%);
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}
html.dark-mode .provider-card:has(.provider-checkbox:checked) {
    background: linear-gradient(180deg, #5F1E1E 0%, var(--bg-card) 60%);
}

/* Checkbox overlay */
.provider-card-checkbox-overlay {
    position: absolute;
    top: 12px;
    right: 12px;
    cursor: pointer;
    z-index: 5;
}
.provider-checkbox { display: none; }
.custom-checkbox {
    width: 26px;
    height: 26px;
    border: 2px solid var(--border-color);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-card);
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}
.custom-checkbox i {
    color: transparent;
    font-size: 13px;
    transition: all 0.2s ease;
}
.provider-checkbox:checked ~ .custom-checkbox,
label:has(.provider-checkbox:checked) .custom-checkbox {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-color: #DC2626;
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}
.provider-checkbox:checked ~ .custom-checkbox i,
label:has(.provider-checkbox:checked) .custom-checkbox i {
    color: #FFFFFF;
}
.custom-checkbox:hover {
    border-color: #DC2626;
    transform: scale(1.1);
}

.provider-card-accent {
    height: 5px;
    width: 100%;
    flex-shrink: 0;
}

.provider-card-body {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.provider-card-top {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding-right: 32px; /* space for checkbox */
}

.provider-icon-circle {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 3px 10px rgba(0,0,0,0.15);
}

.provider-info {
    display: flex; flex-direction: column; gap: 5px;
    min-width: 0; flex: 1;
}
.provider-name {
    font-size: 14px; font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
    word-break: break-word;
}
.provider-code-chip {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 9.5px; font-weight: 800;
    color: #DC2626; background: #FEF2F2;
    padding: 3px 8px; border-radius: 5px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.4px;
    align-self: flex-start;
    border: 1px solid #FCA5A5;
}
html.dark-mode .provider-code-chip {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.provider-code-chip i { font-size: 8px; }

.provider-meta-row {
    display: flex; flex-wrap: wrap; gap: 6px;
    align-items: center;
}
.type-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
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

.existing-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    background: #FEF3C7; color: #D97706;
    border: 1.5px solid #FDE68A;
    white-space: nowrap;
}
.existing-pill i { font-size: 9px; }
html.dark-mode .existing-pill { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

.available-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 10px; font-weight: 800;
    background: #F0FDF4; color: #059669;
    border: 1.5px solid #A7F3D0;
    white-space: nowrap;
}
.available-pill i { font-size: 9px; }
html.dark-mode .available-pill { background: #065F46; color: #34D399; border-color: #10B981; }

/* Existing codes box */
.existing-codes-box {
    padding: 10px 12px;
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1.5px solid #FCD34D;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
html.dark-mode .existing-codes-box {
    background: linear-gradient(135deg, #5F3A1E 0%, #78350F 100%);
    border-color: #D97706;
}
.existing-codes-label {
    font-size: 10px;
    font-weight: 800;
    color: #78350F;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: flex; align-items: center; gap: 5px;
}
html.dark-mode .existing-codes-label { color: #FDE68A; }
.existing-codes-label i { color: #D97706; font-size: 10px; }
.existing-codes-chips {
    display: flex; flex-wrap: wrap; gap: 5px;
}
.existing-code-chip {
    display: inline-block;
    padding: 2px 9px;
    background: #FFFFFF;
    color: #78350F;
    border: 1px solid #FCD34D;
    border-radius: 5px;
    font-family: 'Courier New', monospace;
    font-size: 10px; font-weight: 800;
    letter-spacing: 0.3px;
}
html.dark-mode .existing-code-chip {
    background: #1e293b; color: #FDE68A; border-color: #D97706;
}

/* Code input section */
.code-input-section {
    padding-top: 4px;
    border-top: 1.5px dashed var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 6px;
    animation: fadeIn 0.25s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
.code-input-label {
    font-size: 10.5px;
    font-weight: 800;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex; align-items: center; gap: 5px;
}
.code-input-label i { color: #DC2626; font-size: 10px; }

.code-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    gap: 6px;
}
.code-input-icon {
    position: absolute;
    left: 12px;
    color: #DC2626;
    font-size: 12px;
    pointer-events: none;
    z-index: 1;
}
.code-input-field {
    flex: 1;
    padding: 10px 14px 10px 34px !important;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    font-family: 'Courier New', monospace !important;
    font-weight: 800 !important;
    font-size: 13px !important;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    background: var(--bg-input);
    color: var(--red-primary) !important;
    transition: all 0.2s ease;
    outline: none;
    min-width: 0;
}
html.dark-mode .code-input-field { color: #FCA5A5 !important; }
.code-input-field:focus {
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
    background: var(--bg-card);
}
.code-input-field.error {
    border-color: #DC2626 !important;
    background: #FEF2F2 !important;
}
html.dark-mode .code-input-field.error { background: #5F1E1E !important; }

.btn-suggest {
    padding: 10px 14px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    border: none;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: all 0.2s ease;
    flex-shrink: 0;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.25);
}
.btn-suggest:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.4);
}
.btn-suggest i { font-size: 11px; }

.code-warning {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 10px;
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #FECACA;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}
html.dark-mode .code-warning {
    background: #7F1D1D; color: #FCA5A5; border-color: #DC2626;
}
.code-warning i { font-size: 11px; flex-shrink: 0; }

/* NO RESULTS */
.no-results {
    padding: 50px 20px;
    text-align: center;
    background: var(--bg-input);
    border-radius: 12px;
    margin-top: 16px;
}
.no-results i {
    font-size: 48px; color: var(--text-light);
    opacity: 0.4; margin-bottom: 12px;
    display: block;
}
.no-results p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 16px 0;
}
.btn-clear-search {
    padding: 10px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    font-family: 'Inter', sans-serif;
    box-shadow: 0 3px 10px rgba(220, 38, 38, 0.3);
}
.btn-clear-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.5);
}

/* EMPTY STATE */
.empty-state {
    padding: 60px 20px;
    text-align: center;
}
.empty-state i {
    font-size: 64px; color: #FCA5A5;
    opacity: 0.5; margin-bottom: 20px;
    display: block;
}
.empty-state h3 {
    font-size: 20px; font-weight: 800;
    color: var(--text-primary);
    margin: 0 0 10px 0;
}
.empty-state p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 24px 0;
}
.btn-add-empty {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 26px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; border-radius: 10px;
    font-size: 13px; font-weight: 700;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
    transition: all 0.3s ease;
}
.btn-add-empty:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}

/* FORM ACTIONS */
.form-actions {
    display: flex; gap: 12px;
    padding: 18px 24px;
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    margin-top: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
    flex-wrap: wrap; justify-content: flex-end;
    position: sticky; bottom: 16px; z-index: 10;
}
.btn-secondary-large, .btn-submit-large {
    padding: 13px 26px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
    min-width: 160px;
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
@media (max-width: 900px) {
    .toolbar-search { flex: 1 1 100%; max-width: 100%; min-width: 0; }
    .toolbar-scroll-center { width: 100%; justify-content: center; order: 3; }
    .toolbar-filters { width: 100%; justify-content: center; order: 2; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .page-header .header-right { width: 100%; }
    .page-header .header-right .btn { flex: 1; justify-content: center; }

    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .branch-indicator-stats { width: 100%; justify-content: center; }

    .providers-wrapper { padding: 14px; }
    .providers-grid { grid-template-columns: 1fr; }

    .form-actions { flex-direction: column; position: static; padding: 16px; }
    .btn-secondary-large, .btn-submit-large { width: 100%; }
}
@media (max-width: 480px) {
    .bi-stat-num { font-size: 14px; }
    .bi-stat-label { font-size: 8px; }
    .provider-icon-circle { width: 40px; height: 40px; font-size: 18px; }
    .provider-name { font-size: 13px; }
}
</style>

<script>
// ============================================================
// EXISTING CODES (from PHP)
// ============================================================
const existingCodes = <?php echo json_encode(array_map('strtoupper', $all_existing_codes)); ?>;
const branchCode = <?php echo json_encode($branch['branch_code'] ?? 'BR'); ?>;

// ============================================================
// TOGGLE PROVIDER (show/hide code input)
// ============================================================
function onProviderToggle(providerId) {
    var checkbox = document.getElementById('provider_' + providerId);
    var codeSection = document.getElementById('code_input_' + providerId);
    var codeField = document.getElementById('code_' + providerId);

    if (checkbox && checkbox.checked) {
        codeSection.style.display = 'flex';
        codeField.required = true;
        setTimeout(function() { codeField.focus(); }, 150);
    } else {
        codeSection.style.display = 'none';
        codeField.required = false;
        codeField.value = '';
        codeField.classList.remove('error');
        var warning = document.getElementById('code_warning_' + providerId);
        if (warning) warning.style.display = 'none';
    }

    updateSelectedCount();
}

// ============================================================
// SELECTED COUNT
// ============================================================
function updateSelectedCount() {
    var checked = document.querySelectorAll('.provider-checkbox:checked');
    document.getElementById('selectedCount').textContent = checked.length;
}

// ============================================================
// SELECT ALL / DESELECT ALL
// ============================================================
function selectAll() {
    document.querySelectorAll('.provider-card:not(.hidden-by-search) .provider-checkbox').forEach(function(cb) {
        if (!cb.checked) {
            cb.checked = true;
            onProviderToggle(cb.value);
        }
    });
    updateSelectedCount();
}

function deselectAll() {
    document.querySelectorAll('.provider-checkbox').forEach(function(cb) {
        if (cb.checked) {
            cb.checked = false;
            onProviderToggle(cb.value);
        }
    });
    updateSelectedCount();
}

// ============================================================
// SEARCH
// ============================================================
function filterProviders(term) {
    term = term.toLowerCase().trim();
    var cards = document.querySelectorAll('.provider-card');
    var noResults = document.getElementById('noResults');
    var clearBtn = document.getElementById('providerSearchClear');
    var matches = 0;

    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';

    cards.forEach(function(card) {
        var data = card.getAttribute('data-search') || '';
        if (term === '' || data.includes(term)) {
            card.classList.remove('hidden-by-search');
            matches++;
        } else {
            card.classList.add('hidden-by-search');
        }
    });

    if (noResults) noResults.style.display = matches === 0 ? 'block' : 'none';
}

function clearProviderSearch() {
    var input = document.getElementById('providerSearch');
    if (input) {
        input.value = '';
        filterProviders('');
        input.focus();
    }
}

// ============================================================
// SCROLL
// ============================================================
function scrollProviders(direction) {
    var wrapper = document.getElementById('providersWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({
        left: direction === 'left' ? -350 : 350,
        behavior: 'smooth'
    });
}

// ============================================================
// CHECK CODE DUPLICATE
// ============================================================
function checkCodeDuplicate(providerId) {
    var field = document.getElementById('code_' + providerId);
    var warning = document.getElementById('code_warning_' + providerId);
    if (!field || !warning) return;

    var code = field.value.trim().toUpperCase();

    if (code === '') {
        field.classList.remove('error');
        warning.style.display = 'none';
        return;
    }

    if (existingCodes.indexOf(code) !== -1) {
        field.classList.add('error');
        warning.style.display = 'flex';
    } else {
        field.classList.remove('error');
        warning.style.display = 'none';
    }
}

// ============================================================
// SUGGEST CODE
// ============================================================
function suggestCode(providerId, providerMainCode) {
    var field = document.getElementById('code_' + providerId);
    if (!field) return;

    var base = (providerMainCode || 'PROV').toUpperCase().replace(/[^A-Z0-9]/g, '');
    var branchBase = (branchCode || 'BR').toUpperCase().replace(/[^A-Z0-9]/g, '');

    var seq = 1;
    var suggested = base + '-' + branchBase + '-' + String(seq).padStart(2, '0');

    var attempts = 0;
    while (existingCodes.indexOf(suggested.toUpperCase()) !== -1 && attempts < 100) {
        seq++;
        suggested = base + '-' + branchBase + '-' + String(seq).padStart(2, '0');
        attempts++;
    }

    field.value = suggested;
    field.focus();
    checkCodeDuplicate(providerId);
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm(e) {
    var checked = document.querySelectorAll('.provider-checkbox:checked');

    if (checked.length === 0) {
        e.preventDefault();
        alert('Please select at least one provider to assign.');
        return false;
    }

    var errors = [];
    var allValid = true;

    checked.forEach(function(cb) {
        var providerId = cb.value;
        var field = document.getElementById('code_' + providerId);
        var card = cb.closest('.provider-card');
        var providerName = card.querySelector('.provider-name').textContent.trim();

        if (!field || field.value.trim() === '') {
            errors.push('• ' + providerName + ': code is required');
            allValid = false;
            return;
        }

        var code = field.value.trim().toUpperCase();
        if (existingCodes.indexOf(code) !== -1) {
            errors.push('• ' + providerName + ': code "' + code + '" already exists');
            allValid = false;
        }
    });

    // Check for duplicates within the batch
    var batchCodes = {};
    checked.forEach(function(cb) {
        var providerId = cb.value;
        var field = document.getElementById('code_' + providerId);
        if (field && field.value.trim() !== '') {
            var code = field.value.trim().toUpperCase();
            if (batchCodes[code]) {
                errors.push('• Code "' + code + '" is used twice in this batch');
                allValid = false;
            } else {
                batchCodes[code] = true;
            }
        }
    });

    if (!allValid) {
        e.preventDefault();
        alert('Please fix the following errors:\n\n' + errors.join('\n'));
        return false;
    }

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';
    return true;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();

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