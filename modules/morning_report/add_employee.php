<?php
// ================================================================
// FILE: modules/morning_report/add_employee.php
// WAKALA FINANCIAL SYSTEM - ADD MORNING REPORT (EMPLOYEE) - FINAL
// ✅ GREEN THEME
// ✅ AUTO-FILL ONLY - Readonly
// ✅ Cash JUU, Providers CHINI (3 kwa row)
// ✅ Table mbili: morning_reports + daily_reports
// ✅ FIXED: SQL columns + float computation
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

if ($role !== 'employee') {
    header('Location: add.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: ../../login.php');
    exit();
}

$employee_branch_id = intval($employee['branch_id'] ?? 0);

if ($employee_branch_id <= 0) {
    $_SESSION['error_message'] = 'Your account is not assigned to any branch.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ? AND is_active = 1");
$stmt->execute([$employee_branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$branch) {
    $_SESSION['error_message'] = 'Branch not found.';
    header('Location: index_employee.php');
    exit();
}

$branch_name     = $branch['branch_name'];
$branch_code     = $branch['branch_code'] ?? '';
$branch_location = $branch['location'] ?? '';

// ============================================================
// TARGET DATE
// ============================================================
$target_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
    $target_date = date('Y-m-d');
}

// ============================================================
// CHECK DUPLICATE
// ============================================================
$stmt = $db->prepare("
    SELECT id, report_number 
    FROM morning_reports 
    WHERE branch_id = ? AND report_date = ?
    LIMIT 1
");
$stmt->execute([$employee_branch_id, $target_date]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $_SESSION['error_message'] = 'Morning report for ' . date('d M Y', strtotime($target_date)) . 
                                  ' already exists (' . $existing['report_number'] . ').';
    header('Location: view_employee.php?id=' . $existing['id']);
    exit();
}

// ============================================================
// AUTO-FILL LOGIC
// ============================================================
$source_type = null;
$source_data = null;
$auto_providers = [];
$auto_cash = 0;
$auto_notes = '';
$has_data = false;

// STEP 1: Evening Stock
$stmt = $db->prepare("
    SELECT es.*, e.full_name AS employee_name, e.employee_id AS employee_code
    FROM evening_stocks es
    LEFT JOIN employees e ON es.employee_id = e.id
    WHERE es.branch_id = ? AND es.stock_date < ?
    ORDER BY es.stock_date DESC, es.id DESC
    LIMIT 1
");
$stmt->execute([$employee_branch_id, $target_date]);
$evening_stock = $stmt->fetch(PDO::FETCH_ASSOC);

if ($evening_stock) {
    $stmt = $db->prepare("
        SELECT esp.provider_id, esp.provider_code, esp.provider_name,
               esp.closing_float, esp.closing_cash,
               p.icon_class, p.color_code, p.provider_type, p.display_order
        FROM evening_stock_providers esp
        LEFT JOIN providers p ON esp.provider_id = p.id
        WHERE esp.evening_stock_id = ?
        ORDER BY p.display_order, esp.provider_name
    ");
    $stmt->execute([$evening_stock['id']]);
    $esp = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($esp) && !empty($evening_stock['provider_data'])) {
        $decoded = json_decode($evening_stock['provider_data'], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $pid => $float) {
                $stmt2 = $db->prepare("
                    SELECT p.id, p.provider_name, p.icon_class, p.color_code, p.provider_type,
                           p.display_order, bp.provider_code
                    FROM providers p
                    INNER JOIN branch_providers bp ON bp.provider_id = p.id
                    WHERE p.id = ? AND bp.branch_id = ?
                ");
                $stmt2->execute([intval($pid), $employee_branch_id]);
                $pinfo = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($pinfo) {
                    $esp[] = [
                        'provider_id' => $pinfo['id'],
                        'provider_code' => $pinfo['provider_code'],
                        'provider_name' => $pinfo['provider_name'],
                        'closing_float' => floatval(str_replace(',', '', $float)),
                        'closing_cash' => 0,
                        'icon_class' => $pinfo['icon_class'],
                        'color_code' => $pinfo['color_code'],
                        'provider_type' => $pinfo['provider_type'],
                        'display_order' => $pinfo['display_order'],
                    ];
                }
            }
        }
    }

    $has_float_data = false;
    foreach ($esp as $row) {
        if (floatval(str_replace(',', '', $row['closing_float'] ?? 0)) > 0) {
            $has_float_data = true;
            break;
        }
    }

    $has_cash_data = floatval(str_replace(',', '', $evening_stock['cash_balance'] ?? 0)) > 0 || 
                     floatval(str_replace(',', '', $evening_stock['cumm_total'] ?? 0)) > 0;

    if ($has_float_data || $has_cash_data) {
        $source_type = 'evening_stock';
        $source_data = [
            'id' => $evening_stock['id'],
            'number' => $evening_stock['stock_number'],
            'date' => $evening_stock['stock_date'],
            'employee' => $evening_stock['employee_name'] ?? 'N/A',
            'cash' => floatval(str_replace(',', '', $evening_stock['cash_balance'])),
            'cumm' => floatval(str_replace(',', '', $evening_stock['cumm_total'])),
            'notes' => $evening_stock['notes'] ?? '',
        ];
        $auto_providers = $esp;
        $auto_cash = floatval(str_replace(',', '', $evening_stock['cash_balance']));
        $auto_notes = $evening_stock['notes'] ?? '';
        $has_data = true;
    }
}

// STEP 2: Capital Management
if (!$has_data) {
    $stmt = $db->prepare("
        SELECT amount, capital_number, transaction_date, description
        FROM capital_management
        WHERE branch_id = ? AND reference_module = 'cash'
        ORDER BY transaction_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$employee_branch_id]);
    $capital_cash_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $capital_cash = $capital_cash_row ? floatval(str_replace(',', '', $capital_cash_row['amount'])) : 0;

    $stmt = $db->prepare("
        SELECT cm.id, cm.capital_number, cm.amount, cm.transaction_date,
               cm.reference_id AS provider_id,
               p.provider_name, p.icon_class, p.color_code, p.provider_type, p.display_order,
               COALESCE(bp.provider_code, p.provider_code) AS provider_code
        FROM capital_management cm
        LEFT JOIN providers p ON (cm.reference_module = 'provider' AND cm.reference_id = p.id)
        LEFT JOIN branch_providers bp ON (bp.branch_id = cm.branch_id AND bp.provider_id = p.id)
        WHERE cm.branch_id = ? AND cm.reference_module = 'provider'
          AND cm.id IN (
              SELECT MAX(id) FROM capital_management 
              WHERE branch_id = ? AND reference_module = 'provider'
              GROUP BY reference_id
          )
        ORDER BY p.display_order, p.provider_name
    ");
    $stmt->execute([$employee_branch_id, $employee_branch_id]);
    $capital_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($capital_providers) || $capital_cash > 0) {
        $source_type = 'capital_management';
        
        $latest_date = null;
        foreach ($capital_providers as $cp) {
            if ($latest_date === null || $cp['transaction_date'] > $latest_date) {
                $latest_date = $cp['transaction_date'];
            }
        }
        if ($capital_cash_row && ($latest_date === null || $capital_cash_row['transaction_date'] > $latest_date)) {
            $latest_date = $capital_cash_row['transaction_date'];
        }

        $source_data = [
            'id' => 0,
            'number' => 'OPENING-CAPITAL',
            'date' => $latest_date ?? date('Y-m-d'),
            'employee' => 'System Auto-fill',
            'cash' => $capital_cash,
            'cumm' => 0,
            'notes' => 'Auto-filled from Opening Capital',
        ];

        foreach ($capital_providers as $cp) {
            $auto_providers[] = [
                'provider_id' => intval($cp['provider_id']),
                'provider_code' => $cp['provider_code'] ?? '-',
                'provider_name' => $cp['provider_name'] ?? 'Unknown',
                'closing_float' => floatval(str_replace(',', '', $cp['amount'])),
                'closing_cash' => 0,
                'icon_class' => $cp['icon_class'] ?? 'fas fa-university',
                'color_code' => $cp['color_code'] ?? '#0B5ED7',
                'provider_type' => $cp['provider_type'] ?? 'bank',
                'display_order' => $cp['display_order'] ?? 0,
            ];
        }

        $auto_cash = $capital_cash;
        $auto_notes = 'Auto-filled from Opening Capital';
        $has_data = true;
    }
}

if (!$has_data) {
    $auto_providers = [];
    $auto_cash = 0;
    $auto_notes = '';
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
$preview_total_float = 0;
foreach ($auto_providers as $sp) {
    $preview_total_float += floatval(str_replace(',', '', $sp['closing_float'] ?? 0));
}
$preview_cash = $auto_cash;
$preview_cumm = $preview_total_float + $preview_cash;

// ============================================================
// HANDLE POST
// ============================================================
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_morning_report') {
    try {
        $db->beginTransaction();

        $post_date         = $_POST['report_date'] ?? date('Y-m-d');
        $post_source_type  = $_POST['source_type'] ?? 'manual';
        $post_source_id    = intval($_POST['source_evening_stock_id'] ?? 0);
        $post_cash         = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $post_notes        = trim($_POST['notes'] ?? '');
        $post_providers    = $_POST['providers'] ?? [];

        if ($post_cash < 0) throw new Exception('Cash balance cannot be negative.');

        $stmt = $db->prepare("SELECT id FROM morning_reports WHERE branch_id = ? AND report_date = ? LIMIT 1");
        $stmt->execute([$employee_branch_id, $post_date]);
        if ($stmt->fetch()) {
            throw new Exception('Morning report for this date already exists.');
        }

        $report_number = 'MR-' . date('Ymd', strtotime($post_date)) . '-' . 
                         strtoupper(substr($branch_code, 0, 3)) . '-' . 
                         str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // ✅ FIXED: str_replace for float computation
        $total_float = 0;
        foreach ($post_providers as $pid => $float) {
            $fv = floatval(str_replace(',', '', $float));
            if ($fv < 0) throw new Exception('Provider float cannot be negative.');
            $total_float += $fv;
        }
        $cumm_total = $total_float + $post_cash;

        $db_source_type = ($post_source_type === 'capital_management') ? 'manual' : 'auto_from_evening';
        $db_source_id = ($post_source_type === 'evening_stock' && $post_source_id > 0) ? $post_source_id : null;

        // 1) morning_reports
        $stmt = $db->prepare("
            INSERT INTO morning_reports 
            (report_number, employee_id, branch, branch_id, report_date,
             provider_data, cash_balance, cumm_total, submitted_at,
             notes, source_type, source_evening_stock_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $report_number, $user_id, $branch_name, $employee_branch_id, $post_date,
            json_encode($post_providers), $post_cash, $cumm_total,
            $post_notes, $db_source_type, $db_source_id
        ]);

        $report_id = $db->lastInsertId();

        // 2) morning_report_providers
        $stmt = $db->prepare("
            INSERT INTO morning_report_providers
            (report_id, provider_id, provider_code, provider_name, float_balance, cash_balance, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");

        $provider_map = [];
        $stmt2 = $db->prepare("
            SELECT p.id, p.provider_name, bp.provider_code
            FROM providers p
            INNER JOIN branch_providers bp ON bp.provider_id = p.id
            WHERE bp.branch_id = ? AND bp.is_active = 1
        ");
        $stmt2->execute([$employee_branch_id]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $provider_map[intval($row['id'])] = $row;
        }

        foreach ($post_providers as $pid => $float) {
            $pid = intval($pid);
            $fv = floatval(str_replace(',', '', $float));
            if ($pid <= 0 || $fv == 0) continue;

            $pinfo = $provider_map[$pid] ?? null;
            if (!$pinfo) continue;

            $stmt->execute([$report_id, $pid, $pinfo['provider_code'], $pinfo['provider_name'], $fv]);
        }

        // 3) daily_reports
        $daily_report_number = 'DR-' . date('Ymd', strtotime($post_date)) . '-' . 
                               str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $stmt_dr = $db->prepare("
            INSERT INTO daily_reports 
            (report_number, employee_id, branch, branch_id, report_date,
             morning_report_id, current_cash,
             morning_total, current_float, total_business_income,
             current_capital, created_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        $stmt_dr->execute([
            $daily_report_number, $user_id, $branch_name, $employee_branch_id, $post_date,
            $report_id, $post_cash, $cumm_total, $total_float, $cumm_total, $cumm_total, $post_notes
        ]);

        $daily_report_id = $db->lastInsertId();

        // 4) daily_report_providers
        $stmt_drp = $db->prepare("
            INSERT INTO daily_report_providers 
            (daily_report_id, provider_id, provider_code, provider_name,
             morning_float, morning_cash, current_float, current_cash,
             total_deposits, total_withdrawals, created_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, 0, NOW())
        ");

        foreach ($post_providers as $pid => $float) {
            $pid = intval($pid);
            $fv = floatval(str_replace(',', '', $float));
            if ($pid <= 0 || $fv == 0) continue;

            $pinfo = $provider_map[$pid] ?? null;
            if (!$pinfo) continue;

            $stmt_drp->execute([
                $daily_report_id, $pid, $pinfo['provider_code'], $pinfo['provider_name'],
                $fv, $fv
            ]);
        }

        logActivity(
            $user_id, 'Add Morning Report', 'Morning Report', $report_id, '',
            'Employee ' . $employee['full_name'] . ' added ' . $report_number . ' for ' . $branch_name . 
            ' - Float: ' . number_format($total_float) . ', Cash: ' . number_format($post_cash)
        );

        $db->commit();

        $_SESSION['success_message'] = 'Morning Report ' . $report_number . ' created successfully!';
        header('Location: index_employee.php');
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
    }
}

$success_message_session = '';
if (isset($_SESSION['success_message'])) {
    $success_message_session = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">My Branch</span>
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
                <a href="index_employee.php" class="btn-back-card">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-plus-circle" style="color:#059669;"></i> New Morning Report</h2>
            </div>
        </div>

        <?php if (!empty($success_message_session)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo htmlspecialchars($success_message_session); ?></span>
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

        <?php if (!$has_data): ?>
            <div class="waiting-card">
                <div class="waiting-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <h3 class="waiting-title">Waiting for Opening Capital</h3>
                <p class="waiting-text">
                    No Evening Stock data found for your branch. 
                    Please ask admin to add <strong>Opening Capital</strong> from Capital Management first, 
                    or submit an <strong>Evening Stock</strong> for a previous date.
                </p>
                <div class="waiting-actions">
                    <a href="index_employee.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                </div>
            </div>
        <?php else: ?>

            <div class="source-info-card <?php echo $source_type === 'capital_management' ? 'source-capital' : 'source-evening'; ?>">
                <div class="source-info-header">
                    <?php if ($source_type === 'evening_stock'): ?>
                        <i class="fas fa-moon"></i>
                        <span>Source: Evening Stock</span>
                    <?php else: ?>
                        <i class="fas fa-coins"></i>
                        <span>Source: Opening Capital</span>
                    <?php endif; ?>
                </div>
                <div class="source-info-body">
                    <div class="source-detail">
                        <span class="source-detail-label">Reference</span>
                        <span class="source-detail-value"><?php echo htmlspecialchars($source_data['number']); ?></span>
                    </div>
                    <div class="source-detail">
                        <span class="source-detail-label">Date</span>
                        <span class="source-detail-value"><?php echo date('d M Y', strtotime($source_data['date'])); ?></span>
                    </div>
                    <div class="source-detail">
                        <span class="source-detail-label">Employee</span>
                        <span class="source-detail-value"><?php echo htmlspecialchars($source_data['employee']); ?></span>
                    </div>
                </div>
            </div>

            <form method="POST" action="" class="add-form" id="addForm" onsubmit="return validateAdd()">
                <input type="hidden" name="action" value="add_morning_report">
                <input type="hidden" name="source_type" value="<?php echo htmlspecialchars($source_type); ?>">
                <input type="hidden" name="source_evening_stock_id" value="<?php echo ($source_type === 'evening_stock' && $source_data) ? intval($source_data['id']) : 0; ?>">

                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>Cash Balance</h3>
                    </div>
                    <div class="form-card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Report Date <span class="required">*</span></label>
                                <input type="date" name="report_date" id="report_date" 
                                       class="form-control" 
                                       value="<?php echo htmlspecialchars($target_date); ?>" 
                                       required>
                            </div>
                            <div class="form-group">
                                <label>Cash Balance (TSh)</label>
                                <input type="text" name="cash_balance" id="cash_balance" 
                                       class="form-control money-input readonly-input" 
                                       value="<?php echo number_format($preview_cash); ?>" 
                                       readonly tabindex="-1">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-university"></i>
                        <h3>Provider Floats</h3>
                        <span class="providers-count"><?php echo count($auto_providers); ?> providers</span>
                    </div>
                    <div class="form-card-body">
                        <?php if (count($auto_providers) > 0): ?>
                            <div class="providers-grid-3">
                                <?php foreach ($auto_providers as $sp):
                                    $pid = intval($sp['provider_id']);
                                    $color = $sp['color_code'] ?? '#0B5ED7';
                                    $icon = $sp['icon_class'] ?? 'fas fa-university';
                                    $float_val = floatval(str_replace(',', '', $sp['closing_float'] ?? 0));
                                ?>
                                    <div class="provider-input-card">
                                        <div class="provider-input-header">
                                            <div class="provider-input-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                                            </div>
                                            <div class="provider-input-info">
                                                <span class="provider-input-name"><?php echo htmlspecialchars($sp['provider_name']); ?></span>
                                                <span class="provider-input-code"><?php echo htmlspecialchars($sp['provider_code']); ?></span>
                                            </div>
                                        </div>
                                        <div class="provider-input-body">
                                            <label>Float Balance (TSh)</label>
                                            <input type="text" 
                                                   name="providers[<?php echo $pid; ?>]" 
                                                   class="form-control money-input provider-float-input readonly-input" 
                                                   value="<?php echo number_format($float_val); ?>"
                                                   data-provider-id="<?php echo $pid; ?>"
                                                   readonly tabindex="-1">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-providers">
                                <i class="fas fa-info-circle"></i>
                                <p>No providers found for your branch.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-sticky-note"></i>
                        <h3>Notes</h3>
                    </div>
                    <div class="form-card-body">
                        <div class="form-group">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Additional notes..."><?php echo htmlspecialchars($auto_notes); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="summary-panel">
                    <div class="summary-panel-header">
                        <i class="fas fa-calculator"></i>
                        <span>Summary</span>
                    </div>
                    <div class="summary-panel-body">
                        <div class="summary-line">
                            <span>Total Float:</span>
                            <span class="summary-value"><?php echo formatCurrency($preview_total_float); ?></span>
                        </div>
                        <div class="summary-line">
                            <span>Cash Balance:</span>
                            <span class="summary-value"><?php echo formatCurrency($preview_cash); ?></span>
                        </div>
                        <div class="summary-line summary-line-total">
                            <span>Cumm. Total:</span>
                            <span class="summary-value"><?php echo formatCurrency($preview_cumm); ?></span>
                        </div>
                    </div>
                </div>

                <div class="actions-bottom">
                    <a href="index_employee.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Create Morning Report
                    </button>
                </div>

            </form>

        <?php endif; ?>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
:root {
    --bg-body: #f0f4f8;
    --bg-card: #ffffff;
    --bg-input: #f8fafc;
    --text-primary: #1e293b;
    --text-secondary: #334155;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --border-color: #cbd5e1;
    --shadow-color: rgba(5, 150, 105, 0.08);
    --green-primary: #059669;
    --green-dark: #047857;
    --green-darker: #065F46;
    --green-light: #D1FAE5;
    --green-lighter: #A7F3D0;
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
    --green-light: #065F46;
    --green-lighter: #047857;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100vw !important; width: 100% !important; }
.main-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
    margin-left: 240px;
    width: calc(100% - 240px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--bg-body);
    transition: margin-left 0.3s ease, width 0.3s ease;
    position: relative;
}
.main-content { overflow-x: hidden !important; max-width: 100% !important; width: 100% !important; padding: 20px 24px !important; }
@media (max-width: 1024px) {
    .main-wrapper { margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; }
    .main-content { padding: 16px 18px !important; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 50px; }
    .main-content { padding: 16px 14px !important; width: 100%; }
}
@media (max-width: 480px) {
    .main-wrapper { padding-top: 44px; width: 100%; }
    .main-content { padding: 12px 10px !important; width: 100%; }
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

.branch-indicator {
    background: linear-gradient(135deg, #059669 0%, #047857 50%, #065F46 100%);
    border-radius: 12px; padding: 14px 22px; margin-bottom: 16px;
    display: flex; justify-content: space-between; align-items: center;
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.35);
    flex-wrap: wrap; gap: 12px;
}
.branch-indicator-left { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; min-width: 0; flex: 1; }
.branch-icon-wrapper {
    width: 42px; height: 42px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFF; flex-shrink: 0;
    border: 1.5px solid rgba(255,255,255,0.3);
}
.branch-info { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; min-width: 0; }
.branch-indicator-label {
    font-size: 10px; font-weight: 600; opacity: 0.85;
    text-transform: uppercase; letter-spacing: 1px; color: #FFF;
}
.branch-indicator-name {
    font-weight: 800; font-size: 16px; color: #FFF;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
}
.branch-indicator-code {
    font-size: 11px; font-weight: 700; color: #FFF;
    padding: 3px 12px; background: rgba(255,255,255,0.2);
    border-radius: 12px; border: 1px solid rgba(255,255,255,0.25);
}
.branch-location {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: rgba(255,255,255,0.9);
    padding: 4px 12px; background: rgba(255,255,255,0.12);
    border-radius: 12px; white-space: nowrap;
}
.branch-indicator-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px; background: rgba(255,255,255,0.12);
    border-radius: 8px; border: 1px solid rgba(255,255,255,0.15);
    color: #FFF; text-decoration: none;
    font-size: 13px; font-weight: 600;
    transition: all 0.3s ease;
}
.btn-back-card:hover { background: rgba(255,255,255,0.22); color: #FFF; }

.page-header {
    display: flex; justify-content: space-between;
    align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
}
.page-header .header-left h2 { font-size: 18px; font-weight: 700; margin: 0; }
.page-header .header-left h2 i { margin-right: 6px; }

.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 14px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none;
    font-size: 20px; color: inherit;
    cursor: pointer; padding: 0 4px; opacity: 0.6;
}

.waiting-card {
    background: var(--bg-card);
    border-radius: 16px;
    border: 2px dashed var(--green-primary);
    padding: 60px 30px;
    text-align: center;
    box-shadow: 0 4px 16px var(--shadow-color);
    margin-bottom: 20px;
}
.waiting-icon {
    width: 90px; height: 90px;
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #059669;
    margin: 0 auto 20px;
    border: 3px solid #6EE7B7;
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(5, 150, 105, 0.4); }
    50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(5, 150, 105, 0); }
}
html.dark-mode .waiting-icon {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #6EE7B7; border-color: #10B981;
}
.waiting-title {
    font-size: 24px; font-weight: 800;
    color: var(--text-primary); margin: 0 0 12px 0;
}
.waiting-text {
    font-size: 14px; color: var(--text-secondary);
    max-width: 600px; margin: 0 auto 24px;
    line-height: 1.7;
}
.waiting-text strong {
    color: var(--green-primary);
    background: var(--green-light);
    padding: 2px 8px; border-radius: 6px;
    font-weight: 800;
}
html.dark-mode .waiting-text strong { background: #065F46; color: #6EE7B7; }
.waiting-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

.source-info-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 2px solid;
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.source-evening { border-color: #7C3AED; }
.source-capital { border-color: #F59E0B; }
.source-info-header {
    padding: 11px 18px;
    display: flex; align-items: center; gap: 10px;
    font-size: 12px; font-weight: 800;
    color: #FFFFFF;
    text-transform: uppercase; letter-spacing: 1px;
}
.source-evening .source-info-header {
    background: linear-gradient(135deg, #7C3AED 0%, #6D28D9 100%);
}
.source-capital .source-info-header {
    background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
}
.source-info-body {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
}
.source-detail {
    padding: 12px 18px;
    display: flex; flex-direction: column;
    gap: 4px;
    border-right: 1px solid var(--border-color);
    min-width: 0;
}
.source-detail:last-child { border-right: none; }
.source-detail-label {
    font-size: 10px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: 0.8px;
}
.source-detail-value {
    font-size: 12px; font-weight: 800;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    word-break: break-word;
}

.form-card {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.form-card-header {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    padding: 12px 18px;
    display: flex; align-items: center; gap: 10px;
    color: #FFF;
}
.form-card-header i { font-size: 16px; }
.form-card-header h3 { font-size: 14px; font-weight: 800; margin: 0; flex: 1; }
.providers-count {
    font-size: 11px; font-weight: 700;
    padding: 3px 10px;
    background: rgba(255,255,255,0.2);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.3);
}
.form-card-body { padding: 18px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.form-group label {
    font-size: 11px; font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.form-group label .required { color: #DC2626; }
.form-control {
    padding: 10px 13px;
    border: 1.5px solid var(--border-color);
    border-radius: 9px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    width: 100%;
}
.form-control:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
    background: var(--bg-card);
}
textarea.form-control { resize: vertical; min-height: 70px; font-family: 'Inter', sans-serif; }
.money-input {
    font-size: 16px !important;
    font-weight: 800 !important;
    font-family: 'Inter', 'Courier New', monospace !important;
    letter-spacing: 0.5px;
    text-align: right;
}
.readonly-input {
    background: #F0FDF4 !important;
    border: 2px solid #86EFAC !important;
    color: #065F46 !important;
    cursor: not-allowed;
    font-weight: 900;
    pointer-events: none;
    user-select: none;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
}
html.dark-mode .readonly-input {
    background: #065F46 !important;
    border-color: #10B981 !important;
    color: #6EE7B7 !important;
}

.providers-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.provider-input-card {
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    overflow: hidden;
    transition: all 0.3s ease;
}
.provider-input-card:hover {
    border-color: #059669;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.15);
}
.provider-input-header {
    padding: 10px 12px;
    background: var(--bg-card);
    border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; gap: 10px;
}
.provider-input-icon {
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #FFF; font-size: 14px; flex-shrink: 0;
}
.provider-input-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.provider-input-name {
    font-size: 12px; font-weight: 800;
    color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.provider-input-code {
    font-size: 9px; font-weight: 700;
    color: #059669;
    background: #D1FAE5;
    padding: 1px 6px;
    border-radius: 5px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}
html.dark-mode .provider-input-code { background: #065F46; color: #6EE7B7; }
.provider-input-body { padding: 10px 12px; }
.provider-input-body label {
    font-size: 9px; font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block; margin-bottom: 5px;
}
.provider-input-body .form-control {
    font-size: 13px;
    padding: 8px 10px;
    text-align: right;
    font-family: 'Courier New', monospace;
    font-weight: 700;
}

.empty-providers {
    text-align: center;
    padding: 24px 16px;
    color: var(--text-muted);
}
.empty-providers i {
    font-size: 32px; color: var(--text-light);
    opacity: 0.5; display: block; margin-bottom: 8px;
}
.empty-providers p { margin: 0; font-size: 12px; }

.summary-panel {
    background: linear-gradient(135deg, #D1FAE5 0%, #A7F3D0 100%);
    border: 2px solid #6EE7B7;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 16px;
}
html.dark-mode .summary-panel {
    background: linear-gradient(135deg, #065F46, #047857);
    border-color: #059669;
}
.summary-panel-header {
    padding: 11px 18px;
    background: rgba(5, 150, 105, 0.1);
    border-bottom: 2px solid #6EE7B7;
    display: flex; align-items: center; gap: 10px;
    font-size: 12px; font-weight: 800;
    color: #065F46;
    text-transform: uppercase; letter-spacing: 1px;
}
html.dark-mode .summary-panel-header {
    background: rgba(0,0,0,0.15);
    border-color: #059669;
    color: #D1FAE5;
}
.summary-panel-header i { color: #059669; }
.summary-panel-body { padding: 14px 18px; }
.summary-line {
    display: flex; justify-content: space-between;
    align-items: center; padding: 7px 0;
    border-bottom: 1px dashed rgba(5, 150, 105, 0.3);
    font-size: 12px; color: #065F46; font-weight: 600;
}
html.dark-mode .summary-line { color: #D1FAE5; border-color: rgba(110, 231, 183, 0.3); }
.summary-line:last-child { border-bottom: none; }
.summary-line-total {
    padding-top: 10px;
    margin-top: 6px;
    border-top: 2px solid #059669 !important;
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #065F46 !important;
}
html.dark-mode .summary-line-total { color: #A7F3D0 !important; }
.summary-value {
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: #059669;
    font-size: 14px;
}
html.dark-mode .summary-value { color: #6EE7B7; }
.summary-line-total .summary-value { font-size: 18px; color: #065F46; }
html.dark-mode .summary-line-total .summary-value { color: #D1FAE5; }

.actions-bottom {
    display: flex; gap: 12px;
    justify-content: flex-end;
    padding: 16px 0;
    flex-wrap: wrap;
}
.btn {
    padding: 11px 24px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    text-decoration: none; white-space: nowrap;
}
.btn-primary {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #FFF;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 22px rgba(5, 150, 105, 0.5);
    color: #FFF;
}
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-secondary {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-input); color: var(--text-primary); }

@media (max-width: 1024px) {
    .providers-grid-3 { grid-template-columns: repeat(2, 1fr); }
    .source-info-body { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-indicator { flex-direction: column; align-items: flex-start; }
    .btn-back-card { width: 100%; justify-content: center; }
    .form-row { grid-template-columns: 1fr; }
    .providers-grid-3 { grid-template-columns: 1fr; }
    .source-info-body { grid-template-columns: 1fr; }
    .source-detail { border-right: none; border-bottom: 1px solid var(--border-color); }
    .source-detail:last-child { border-bottom: none; }
    .actions-bottom { flex-direction: column-reverse; }
    .actions-bottom .btn { width: 100%; justify-content: center; }
    .waiting-actions { flex-direction: column; }
    .waiting-actions .btn { width: 100%; justify-content: center; }
    .waiting-title { font-size: 20px; }
    .waiting-icon { width: 70px; height: 70px; font-size: 32px; }
    .page-header .header-left h2 { font-size: 16px; }
}
</style>

<script>
function validateAdd() {
    const reportDate = document.getElementById('report_date').value;
    if (!reportDate) { alert('Please select report date.'); return false; }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    return true;
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

    document.querySelectorAll('.readonly-input').forEach(function(input) {
        input.addEventListener('keydown', function(e) { e.preventDefault(); return false; });
        input.addEventListener('paste', function(e) { e.preventDefault(); return false; });
        input.addEventListener('cut', function(e) { e.preventDefault(); return false; });
        input.addEventListener('drop', function(e) { e.preventDefault(); return false; });
        input.addEventListener('dragover', function(e) { e.preventDefault(); return false; });
        input.addEventListener('contextmenu', function(e) { e.preventDefault(); return false; });
        input.addEventListener('focus', function(e) { this.blur(); });
    });
});
</script>
</body>
</html>