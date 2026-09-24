<?php
// ================================================================
// FILE: modules/morning_report/generate.php
// WAKALA FINANCIAL SYSTEM - AUTO-GENERATE MORNING REPORT
// 
// LOGIC:
// Morning Report ya tarehe X inachukua Evening Stock ya tarehe X-1
// Kama tarehe X-1 haikuwa na Evening Stock, inachukua Evening Stock
// ya karibu zaidi nyuma (X-2, X-3, ...)
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
// GET PARAMETERS
// ============================================================
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$target_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

if ($branch_id <= 0) {
    $_SESSION['error_message'] = 'Please select a branch.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index.php');
    exit();
}

$branch_name = $branch['branch_name'];
$branch_code = $branch['branch_code'] ?? '';
$branch_location = $branch['location'] ?? '';

// ============================================================
// CHECK IF MORNING REPORT ALREADY EXISTS FOR TARGET DATE
// ============================================================
$stmt = $db->prepare("
    SELECT id, report_number 
    FROM morning_reports 
    WHERE branch_id = ? AND report_date = ?
    LIMIT 1
");
$stmt->execute([$branch_id, $target_date]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $_SESSION['error_message'] = 'Morning report for ' . date('d M Y', strtotime($target_date)) . 
                                  ' already exists (' . $existing['report_number'] . ').';
    header('Location: index.php?branch_id=' . $branch_id);
    exit();
}

// ============================================================
// FIND THE LATEST EVENING STOCK BEFORE TARGET DATE
// LOGIC: Tafuta evening stock ya tarehe iliyopita (target_date - 1).
// Kama haipo, tafuta ya karibu zaidi nyuma.
// ============================================================
$stmt = $db->prepare("
    SELECT 
        es.*,
        e.full_name AS employee_name,
        e.employee_id AS employee_code
    FROM evening_stocks es
    LEFT JOIN employees e ON es.employee_id = e.id
    WHERE es.branch_id = ? 
      AND es.stock_date < ?
    ORDER BY es.stock_date DESC, es.id DESC
    LIMIT 1
");
$stmt->execute([$branch_id, $target_date]);
$source_stock = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// IF NO SOURCE STOCK FOUND - SHOW ERROR
// ============================================================
$can_generate = true;
$error_msg = '';

if (!$source_stock) {
    $can_generate = false;
    $error_msg = 'Hakuna Evening Stock iliyopatikana kabla ya tarehe ' . 
                 date('d M Y', strtotime($target_date)) . '. ' .
                 'Tafadhali hakikisha kuwa kuna Evening Stock ya siku zilizopita.';
}

// ============================================================
// HANDLE POST - CREATE MORNING REPORT
// ============================================================
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_morning_report') {
    try {
        $db->beginTransaction();

        $post_branch_id   = intval($_POST['branch_id'] ?? 0);
        $post_date        = $_POST['report_date'] ?? date('Y-m-d');
        $post_source_id   = intval($_POST['source_evening_stock_id'] ?? 0);
        $post_cash        = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $post_notes       = trim($_POST['notes'] ?? '');
        $post_providers   = $_POST['providers'] ?? []; // [provider_id => float]

        if ($post_branch_id <= 0) throw new Exception('Invalid branch.');
        if ($post_source_id <= 0) throw new Exception('Source evening stock is required.');

        // Re-check duplicate
        $stmt = $db->prepare("SELECT id FROM morning_reports WHERE branch_id = ? AND report_date = ? LIMIT 1");
        $stmt->execute([$post_branch_id, $post_date]);
        if ($stmt->fetch()) {
            throw new Exception('Morning report for this date already exists.');
        }

        // Load source evening stock
        $stmt = $db->prepare("SELECT * FROM evening_stocks WHERE id = ? AND branch_id = ?");
        $stmt->execute([$post_source_id, $post_branch_id]);
        $src = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$src) throw new Exception('Source evening stock not found.');

        // Generate report number
        $report_number = 'MR-' . date('Ymd', strtotime($post_date)) . '-' . 
                         strtoupper(substr($branch_code, 0, 3)) . '-' . 
                         str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // Calculate total float from providers
        $total_float = 0;
        foreach ($post_providers as $pid => $float) {
            $total_float += floatval($float);
        }

        // Calculate cumm_total = total_float + cash_balance
        $cumm_total = $total_float + $post_cash;

        // Insert morning report
        $stmt = $db->prepare("
            INSERT INTO morning_reports 
            (report_number, employee_id, branch, branch_id, report_date,
             provider_data, cash_balance, cumm_total, submitted_at,
             notes, source_type, source_evening_stock_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'auto_from_evening', ?)
        ");
        $stmt->execute([
            $report_number,
            $user_id,
            $branch_name,
            $post_branch_id,
            $post_date,
            json_encode($post_providers),
            $post_cash,
            $cumm_total,
            $post_notes,
            $post_source_id
        ]);

        $report_id = $db->lastInsertId();

        // Insert providers
        $stmt = $db->prepare("
            INSERT INTO morning_report_providers
            (report_id, provider_id, provider_code, provider_name, float_balance, cash_balance, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");

        foreach ($post_providers as $pid => $float) {
            $pid = intval($pid);
            if ($pid <= 0) continue;

            // Get provider info
            $stmt2 = $db->prepare("
                SELECT p.provider_name, bp.provider_code
                FROM providers p
                INNER JOIN branch_providers bp ON bp.provider_id = p.id
                WHERE p.id = ? AND bp.branch_id = ? AND bp.is_active = 1
            ");
            $stmt2->execute([$pid, $post_branch_id]);
            $pinfo = $stmt2->fetch(PDO::FETCH_ASSOC);
            if (!$pinfo) continue;

            $stmt->execute([
                $report_id,
                $pid,
                $pinfo['provider_code'],
                $pinfo['provider_name'],
                floatval($float)
            ]);
        }

        // Log activity
        logActivity(
            $user_id,
            'Generate Morning Report',
            'Morning Report',
            $report_id,
            '',
            'Generated ' . $report_number . ' from evening stock ' . $src['stock_number'] . 
            ' for ' . $branch_name
        );

        $db->commit();

        $_SESSION['success_message'] = 'Morning Report ' . $report_number . ' generated successfully from ' . 
                                        $src['stock_number'] . '!';
        header('Location: view.php?id=' . $report_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

// ============================================================
// LOAD SOURCE PROVIDERS FOR THE FORM
// ============================================================
$source_providers = [];
if ($source_stock) {
    $stmt = $db->prepare("
        SELECT 
            esp.*,
            p.icon_class, p.color_code, p.provider_type
        FROM evening_stock_providers esp
        LEFT JOIN providers p ON esp.provider_id = p.id
        WHERE esp.evening_stock_id = ?
        ORDER BY p.display_order, esp.provider_name
    ");
    $stmt->execute([$source_stock['id']]);
    $source_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// If no providers in evening_stock_providers, fallback to provider_data JSON
if (empty($source_providers) && !empty($source_stock['provider_data'])) {
    $decoded = json_decode($source_stock['provider_data'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $pid => $float) {
            $stmt = $db->prepare("
                SELECT p.id, p.provider_name, p.icon_class, p.color_code, p.provider_type,
                       bp.provider_code
                FROM providers p
                INNER JOIN branch_providers bp ON bp.provider_id = p.id
                WHERE p.id = ? AND bp.branch_id = ?
            ");
            $stmt->execute([intval($pid), $branch_id]);
            $pinfo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($pinfo) {
                $source_providers[] = [
                    'provider_id' => $pinfo['id'],
                    'provider_code' => $pinfo['provider_code'],
                    'provider_name' => $pinfo['provider_name'],
                    'closing_float' => floatval($float),
                    'icon_class' => $pinfo['icon_class'],
                    'color_code' => $pinfo['color_code'],
                    'provider_type' => $pinfo['provider_type'],
                ];
            }
        }
    }
}

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH INDICATOR -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Branch</span>
                    <span class="branch-indicator-name"><?php echo htmlspecialchars($branch_name); ?></span>
                    <?php if ($branch_code): ?>
                        <span class="branch-indicator-code"><?php echo htmlspecialchars($branch_code); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($branch_location): ?>
                    <div class="branch-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($branch_location); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="branch-indicator-right">
                <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Reports</span>
                </a>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-magic" style="color:#F59E0B;"></i> Generate Morning Report</h2>
                <p class="text-muted">Auto-generate from previous day's Evening Stock</p>
            </div>
        </div>

        <!-- LOGIC EXPLANATION -->
        <div class="logic-banner">
            <div class="logic-icon"><i class="fas fa-lightbulb"></i></div>
            <div class="logic-content">
                <h4>Jinsi Inavyofanya Kazi</h4>
                <p>
                    Morning Report ya tarehe <strong><?php echo date('d M Y', strtotime($target_date)); ?></strong> 
                    inachukua <strong>Evening Stock ya siku iliyopita</strong>.
                    Kama siku iliyopita haikuwa na Evening Stock, mfumo unatafuta 
                    <strong>Evening Stock ya karibu zaidi nyuma</strong>.
                </p>
            </div>
        </div>

        <!-- ERROR ALERT -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error_message); ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!$can_generate): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($error_msg); ?></span>
            </div>
            <div class="actions-bottom">
                <a href="../evening_stock/index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-primary">
                    <i class="fas fa-moon"></i> Go to Evening Stock
                </a>
                <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        <?php else: ?>

            <!-- SOURCE STOCK INFO -->
            <div class="source-card">
                <div class="source-card-header">
                    <i class="fas fa-moon"></i>
                    <h3>Source: Evening Stock</h3>
                    <span class="source-badge"><?php echo htmlspecialchars($source_stock['stock_number']); ?></span>
                </div>
                <div class="source-card-body">
                    <div class="source-info-grid">
                        <div class="source-info-item">
                            <span class="source-label">Stock Date</span>
                            <span class="source-value">
                                <?php echo date('d M Y', strtotime($source_stock['stock_date'])); ?>
                            </span>
                        </div>
                        <div class="source-info-item">
                            <span class="source-label">Submitted By</span>
                            <span class="source-value">
                                <?php echo htmlspecialchars($source_stock['employee_name'] ?? 'N/A'); ?>
                            </span>
                        </div>
                        <div class="source-info-item">
                            <span class="source-label">Cash Balance</span>
                            <span class="source-value mono">
                                <?php echo formatCurrency($source_stock['cash_balance']); ?>
                            </span>
                        </div>
                        <div class="source-info-item">
                            <span class="source-label">Cumm. Total</span>
                            <span class="source-value mono">
                                <?php echo formatCurrency($source_stock['cumm_total']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- GENERATE FORM -->
            <form method="POST" action="" class="generate-form" id="generateForm" onsubmit="return validateGenerate()">
                <input type="hidden" name="action" value="generate_morning_report">
                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                <input type="hidden" name="source_evening_stock_id" value="<?php echo $source_stock['id']; ?>">

                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-sun"></i>
                        <h3>Morning Report Details</h3>
                    </div>
                    <div class="form-card-body">

                        <div class="form-row">
                            <div class="form-group">
                                <label>Report Date <span class="required">*</span></label>
                                <input type="date" name="report_date" id="report_date" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($target_date); ?>" 
                                       required>
                                <small class="form-hint">Tarehe ya morning report hii</small>
                            </div>
                            <div class="form-group">
                                <label>Cash Balance (TSh) <span class="required">*</span></label>
                                <input type="text" name="cash_balance" id="cash_balance" 
                                       class="form-control money-input" 
                                       value="<?php echo number_format(floatval($source_stock['cash_balance'])); ?>" 
                                       required
                                       oninput="formatMoneyInput(this); recalcTotals();">
                                <small class="form-hint">Inachukuliwa kutoka evening stock</small>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- PROVIDERS -->
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-university"></i>
                        <h3>Provider Floats</h3>
                        <span class="providers-count"><?php echo count($source_providers); ?> providers</span>
                    </div>
                    <div class="form-card-body">
                        <?php if (count($source_providers) > 0): ?>
                            <div class="providers-grid">
                                <?php foreach ($source_providers as $sp):
                                    $pid = intval($sp['provider_id']);
                                    $color = $sp['color_code'] ?? '#0B5ED7';
                                    $icon = $sp['icon_class'] ?? 'fas fa-university';
                                    $float_val = floatval($sp['closing_float'] ?? 0);
                                ?>
                                    <div class="provider-input-card">
                                        <div class="provider-input-header">
                                            <div class="provider-input-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                            </div>
                                            <div class="provider-input-info">
                                                <span class="provider-input-name">
                                                    <?php echo htmlspecialchars($sp['provider_name']); ?>
                                                </span>
                                                <span class="provider-input-code">
                                                    <?php echo htmlspecialchars($sp['provider_code']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="provider-input-body">
                                            <label>Float Balance (TSh)</label>
                                            <input type="text" 
                                                   name="providers[<?php echo $pid; ?>]" 
                                                   class="form-control money-input provider-float-input" 
                                                   value="<?php echo number_format($float_val); ?>"
                                                   data-provider-id="<?php echo $pid; ?>"
                                                   oninput="formatMoneyInput(this); recalcTotals();">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-providers">
                                <i class="fas fa-info-circle"></i>
                                <p>Hakuna providers kwenye evening stock hii.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- NOTES -->
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-sticky-note"></i>
                        <h3>Notes</h3>
                    </div>
                    <div class="form-card-body">
                        <div class="form-group">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Maelezo ya ziada..."><?php echo htmlspecialchars($source_stock['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- SUMMARY -->
                <div class="summary-panel">
                    <div class="summary-panel-header">
                        <i class="fas fa-calculator"></i>
                        <span>Summary</span>
                    </div>
                    <div class="summary-panel-body">
                        <div class="summary-line">
                            <span>Total Float:</span>
                            <span class="summary-value" id="sum_float">TSh 0</span>
                        </div>
                        <div class="summary-line">
                            <span>Cash Balance:</span>
                            <span class="summary-value" id="sum_cash">TSh 0</span>
                        </div>
                        <div class="summary-line summary-line-total">
                            <span>Cumm. Total:</span>
                            <span class="summary-value" id="sum_total">TSh 0</span>
                        </div>
                    </div>
                </div>

                <!-- ACTIONS -->
                <div class="actions-bottom">
                    <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Generate Morning Report
                    </button>
                </div>

            </form>

        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
:root {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-input: #f8fafc;
    --bg-table-even: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(30, 64, 175, 0.08);
}
html.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-input: #334155;
    --bg-table-even: #1a2332;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; }
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 16px 20px !important; }
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* BRANCH INDICATOR */
.branch-indicator {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 50%, #B45309 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(217, 119, 6, 0.3);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper { width: 42px; height: 42px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #FFF; flex-shrink: 0; border: 1.5px solid rgba(255,255,255,0.3); }
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label { font-size: 10px; font-weight: 600; opacity: 0.85; text-transform: uppercase; letter-spacing: 1px; color: #FFF; }
.branch-indicator-name { font-weight: 800; font-size: 16px; color: #FFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
.branch-indicator-code { font-size: 11px; font-weight: 700; color: #FFF; padding: 3px 12px; background: rgba(255,255,255,0.2); border-radius: 12px; border: 1px solid rgba(255,255,255,0.25); }
.branch-location { display: flex; align-items: center; gap: 5px; font-size: 12px; color: rgba(255,255,255,0.9); padding: 4px 12px; background: rgba(255,255,255,0.12); border-radius: 12px; white-space: nowrap; }
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255,255,255,0.12);
    border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);
    color: #FFF; text-decoration: none; font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; }

/* PAGE HEADER */
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
.page-header .header-left h2 { font-size: 22px; font-weight: 800; margin: 0; }
.page-header .header-left h2 i { margin-right: 8px; }
.page-header .header-left .text-muted { font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0; }

/* LOGIC BANNER */
.logic-banner {
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 1.5px solid #93C5FD;
    border-left: 5px solid #1E40AF;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 18px;
    display: flex; align-items: flex-start; gap: 14px;
}
html.dark-mode .logic-banner { background: linear-gradient(135deg, #1E3A5F, #1E40AF); border-color: #3B82F6; border-left-color: #60A5FA; }
.logic-icon {
    width: 44px; height: 44px; border-radius: 50%;
    background: #1E40AF; color: #FFF;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
}
html.dark-mode .logic-icon { background: #3B82F6; }
.logic-content h4 { font-size: 14px; font-weight: 800; color: #1E40AF; margin: 0 0 6px 0; }
html.dark-mode .logic-content h4 { color: #93C5FD; }
.logic-content p { font-size: 13px; color: #1E3A8A; margin: 0; line-height: 1.6; }
html.dark-mode .logic-content p { color: #DBEAFE; }

/* ALERTS */
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px var(--shadow-color); }
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
.alert-warning { background: #FEF3C7; color: #78350F; border: 1px solid #FDE68A; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
html.dark-mode .alert-warning { background: #5F3A1E; color: #FDE68A; border-color: #92400E; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

/* SOURCE CARD */
.source-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid #C4B5FD;
    overflow: hidden;
    margin-bottom: 18px;
    box-shadow: 0 4px 16px rgba(124, 58, 237, 0.1);
}
.source-card-header {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
    padding: 14px 20px;
    display: flex; align-items: center; gap: 10px;
    color: #FFF;
}
.source-card-header i { font-size: 18px; }
.source-card-header h3 { font-size: 15px; font-weight: 800; margin: 0; flex: 1; }
.source-badge {
    font-family: 'Courier New', monospace;
    font-size: 11px; font-weight: 700;
    padding: 4px 12px;
    background: rgba(255,255,255,0.2);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.3);
}
.source-card-body { padding: 18px 20px; }
.source-info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.source-info-item { display: flex; flex-direction: column; gap: 4px; padding: 10px 14px; background: var(--bg-input); border-radius: 10px; border: 1px solid var(--border-color); min-width: 0; }
.source-label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; }
.source-value { font-size: 13px; font-weight: 800; color: var(--text-primary); word-break: break-word; }
.source-value.mono { font-family: 'Courier New', monospace; color: #7C3AED; }
html.dark-mode .source-value.mono { color: #C4B5FD; }

/* FORM CARD */
.form-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 18px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.form-card-header {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    padding: 14px 20px;
    display: flex; align-items: center; gap: 10px;
    color: #FFF;
}
.form-card-header i { font-size: 18px; }
.form-card-header h3 { font-size: 15px; font-weight: 800; margin: 0; flex: 1; }
.providers-count {
    font-size: 11px; font-weight: 700;
    padding: 4px 12px;
    background: rgba(255,255,255,0.2);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.3);
}
.form-card-body { padding: 20px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-group label { font-size: 12px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.form-group label .required { color: #DC2626; }
.form-control {
    padding: 11px 14px;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%;
}
.form-control:focus { outline: none; border-color: #F59E0B; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); background: var(--bg-card); }
.form-hint { font-size: 10px; color: var(--text-muted); font-weight: 500; }
textarea.form-control { resize: vertical; min-height: 80px; font-family: 'Inter', sans-serif; }

.money-input {
    font-size: 18px !important;
    font-weight: 800 !important;
    font-family: 'Inter', 'Courier New', monospace !important;
    letter-spacing: 0.5px;
    text-align: right;
}

/* PROVIDERS GRID */
.providers-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.provider-input-card {
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
}
.provider-input-card:hover { border-color: #F59E0B; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15); }
.provider-input-header {
    padding: 12px 14px;
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 10px;
}
.provider-input-icon {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFF; font-size: 15px; flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.provider-input-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.provider-input-name { font-size: 12px; font-weight: 800; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.provider-input-code { font-size: 9px; font-weight: 700; color: #D97706; background: #FEF3C7; padding: 1px 6px; border-radius: 5px; align-self: flex-start; font-family: 'Courier New', monospace; }
html.dark-mode .provider-input-code { background: #5F3A1E; color: #FBBF24; }
.provider-input-body { padding: 12px 14px; }
.provider-input-body label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 6px; }
.provider-input-body .form-control { font-size: 14px; padding: 9px 12px; text-align: right; font-family: 'Courier New', monospace; font-weight: 700; }

.empty-providers { text-align: center; padding: 30px 20px; color: var(--text-muted); }
.empty-providers i { font-size: 36px; color: var(--text-light); opacity: 0.5; display: block; margin-bottom: 10px; }
.empty-providers p { margin: 0; font-size: 13px; }

/* SUMMARY PANEL */
.summary-panel {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #FCD34D;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 18px;
}
html.dark-mode .summary-panel { background: linear-gradient(135deg, #5F3A1E, #78350F); border-color: #D97706; }
.summary-panel-header {
    padding: 12px 20px;
    background: rgba(217, 119, 6, 0.1);
    border-bottom: 2px solid #FCD34D;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800; color: #78350F;
    text-transform: uppercase; letter-spacing: 1px;
}
html.dark-mode .summary-panel-header { background: rgba(0,0,0,0.15); border-color: #D97706; color: #FDE68A; }
.summary-panel-header i { color: #D97706; }
.summary-panel-body { padding: 16px 20px; }
.summary-line { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed rgba(217, 119, 6, 0.3); font-size: 13px; color: #78350F; font-weight: 600; }
html.dark-mode .summary-line { color: #FDE68A; border-color: rgba(252, 211, 77, 0.3); }
.summary-line:last-child { border-bottom: none; }
.summary-line-total { padding-top: 12px; margin-top: 8px; border-top: 2px solid #D97706 !important; font-size: 16px !important; font-weight: 800 !important; color: #78350F !important; }
html.dark-mode .summary-line-total { color: #FCD34D !important; }
.summary-value { font-family: 'Courier New', monospace; font-weight: 800; color: #D97706; font-size: 15px; }
html.dark-mode .summary-value { color: #FBBF24; }
.summary-line-total .summary-value { font-size: 20px; color: #78350F; }
html.dark-mode .summary-line-total .summary-value { color: #FCD34D; }

/* ACTIONS */
.actions-bottom {
    display: flex; gap: 12px; justify-content: flex-end;
    padding: 20px 0; flex-wrap: wrap;
}
.btn {
    padding: 12px 26px; border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease; font-family: 'Inter', sans-serif;
    text-decoration: none; white-space: nowrap;
}
.btn-primary {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFF;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 22px rgba(217, 119, 6, 0.5); color: #FFF; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-secondary {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-even); color: var(--text-primary); }

/* RESPONSIVE */
@media (max-width: 1024px) {
    .providers-grid { grid-template-columns: repeat(2, 1fr); }
    .source-info-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .providers-grid { grid-template-columns: 1fr; }
    .source-info-grid { grid-template-columns: 1fr; }
    .actions-bottom { flex-direction: column-reverse; }
    .actions-bottom .btn { width: 100%; justify-content: center; }
    .logic-banner { flex-direction: column; }
}
</style>

<script>
// ============================================================
// MONEY FORMAT
// ============================================================
function formatMoneyInput(input) {
    const cursorPos = input.selectionStart;
    const oldLength = input.value.length;
    let value = input.value.replace(/[^0-9]/g, '');
    if (value === '') { input.value = ''; return; }
    value = value.replace(/^0+/, '') || '0';
    if (value.length > 15) value = value.substring(0, 15);
    let formatted = '';
    let count = 0;
    for (let i = value.length - 1; i >= 0; i--) {
        if (count > 0 && count % 3 === 0) formatted = ',' + formatted;
        formatted = value[i] + formatted;
        count++;
    }
    input.value = formatted;
    const newCursorPos = cursorPos + (formatted.length - oldLength);
    try { input.setSelectionRange(newCursorPos, newCursorPos); } catch (e) {}
}

function parseMoney(str) {
    if (!str) return 0;
    return parseFloat(String(str).replace(/,/g, '')) || 0;
}

function formatMoney(num) {
    return 'TSh ' + Number(num).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// RECALCULATE TOTALS
// ============================================================
function recalcTotals() {
    let totalFloat = 0;
    document.querySelectorAll('.provider-float-input').forEach(function(input) {
        totalFloat += parseMoney(input.value);
    });

    const cash = parseMoney(document.getElementById('cash_balance').value);
    const total = totalFloat + cash;

    document.getElementById('sum_float').textContent = formatMoney(totalFloat);
    document.getElementById('sum_cash').textContent = formatMoney(cash);
    document.getElementById('sum_total').textContent = formatMoney(total);
}

// ============================================================
// VALIDATE
// ============================================================
function validateGenerate() {
    const reportDate = document.getElementById('report_date').value;
    if (!reportDate) {
        alert('Please select report date.');
        return false;
    }

    const cash = parseMoney(document.getElementById('cash_balance').value);
    if (cash < 0) {
        alert('Cash balance cannot be negative.');
        return false;
    }

    // Validate all provider floats
    let invalid = false;
    document.querySelectorAll('.provider-float-input').forEach(function(input) {
        const val = parseMoney(input.value);
        if (val < 0) {
            invalid = true;
            input.style.borderColor = '#DC2626';
        } else {
            input.style.borderColor = '';
        }
    });

    if (invalid) {
        alert('Provider floats cannot be negative.');
        return false;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    return true;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    recalcTotals();
});
</script>

</body>
</html>