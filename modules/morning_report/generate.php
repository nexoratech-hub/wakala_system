<?php
// ================================================================
// FILE: modules/morning_report/generate.php
// WAKALA FINANCIAL SYSTEM - GENERATE MORNING REPORT (AUTO)
// 
// AUTO-FILL LOGIC (Same as add.php):
//    1. Find the LATEST EVENING STOCK (any previous date)
//    2. If not found → fallback to CAPITAL MANAGEMENT (float + cash)
//    3. If neither exists → "NO EVENING STOCK" + "WAITING FOR CAPITAL"
// 
// SAVE: morning_reports + daily_reports (auto)
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
// AUTO-FILL LOGIC - STEP 1: EVENING STOCK
// ============================================================
$source_type = null;
$source_data = null;
$auto_providers = [];
$auto_cash = 0;
$auto_notes = '';
$has_data = false;

// ------------------------------------------------------------
// STEP 1: FIND THE LATEST EVENING STOCK BEFORE TARGET DATE
// ------------------------------------------------------------
$stmt = $db->prepare("
    SELECT 
        es.*, 
        e.full_name AS employee_name, 
        e.employee_id AS employee_code,
        DATEDIFF(?, es.stock_date) AS days_back
    FROM evening_stocks es
    LEFT JOIN employees e ON es.employee_id = e.id
    WHERE es.branch_id = ? 
      AND es.stock_date < ?
      AND es.status IN ('waiting', 'approved', 'adjusted')
    ORDER BY es.stock_date DESC, es.id DESC
    LIMIT 1
");
$stmt->execute([$target_date, $branch_id, $target_date]);
$evening_stock = $stmt->fetch(PDO::FETCH_ASSOC);

if ($evening_stock) {
    // ------------------------------------------------------------
    // Get providers from evening_stock_providers
    // ------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT 
            esp.provider_id, 
            esp.provider_code, 
            esp.provider_name,
            esp.closing_float, 
            esp.closing_cash,
            esp.total_deposits,
            esp.total_withdrawals,
            p.icon_class, 
            p.color_code, 
            p.provider_type, 
            p.display_order
        FROM evening_stock_providers esp
        LEFT JOIN providers p ON esp.provider_id = p.id
        WHERE esp.evening_stock_id = ?
        ORDER BY COALESCE(p.display_order, 999), esp.provider_name
    ");
    $stmt->execute([$evening_stock['id']]);
    $esp = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------
    // FALLBACK: If evening_stock_providers is empty, use provider_data JSON
    // ------------------------------------------------------------
    if (empty($esp) && !empty($evening_stock['provider_data'])) {
        $decoded = json_decode($evening_stock['provider_data'], true);
        if (is_array($decoded) && !empty($decoded)) {
            foreach ($decoded as $pid => $float) {
                $pid = intval($pid);
                $stmt2 = $db->prepare("
                    SELECT 
                        p.id, 
                        p.provider_name, 
                        p.icon_class, 
                        p.color_code, 
                        p.provider_type,
                        p.display_order, 
                        bp.provider_code
                    FROM providers p
                    INNER JOIN branch_providers bp ON bp.provider_id = p.id
                    WHERE p.id = ? AND bp.branch_id = ? AND bp.is_active = 1
                ");
                $stmt2->execute([$pid, $branch_id]);
                $pinfo = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($pinfo) {
                    $esp[] = [
                        'provider_id' => $pinfo['id'],
                        'provider_code' => $pinfo['provider_code'],
                        'provider_name' => $pinfo['provider_name'],
                        'closing_float' => floatval(str_replace(',', '', $float)),
                        'closing_cash' => 0,
                        'total_deposits' => 0,
                        'total_withdrawals' => 0,
                        'icon_class' => $pinfo['icon_class'],
                        'color_code' => $pinfo['color_code'],
                        'provider_type' => $pinfo['provider_type'],
                        'display_order' => $pinfo['display_order'],
                    ];
                }
            }
        }
    }

    // ------------------------------------------------------------
    // Check if there is meaningful data
    // ------------------------------------------------------------
    $has_float_data = false;
    foreach ($esp as $row) {
        if (floatval(str_replace(',', '', $row['closing_float'] ?? 0)) > 0) {
            $has_float_data = true;
            break;
        }
    }

    $cash_value = floatval(str_replace(',', '', $evening_stock['cash_balance'] ?? 0));
    $has_cash_data = $cash_value > 0;

    if ($has_float_data || $has_cash_data) {
        $source_type = 'evening_stock';
        $source_data = [
            'id' => $evening_stock['id'],
            'number' => $evening_stock['stock_number'],
            'date' => $evening_stock['stock_date'],
            'employee' => $evening_stock['employee_name'] ?? 'N/A',
            'employee_code' => $evening_stock['employee_code'] ?? '-',
            'cash' => $cash_value,
            'cumm' => floatval(str_replace(',', '', $evening_stock['cumm_total'] ?? 0)),
            'notes' => $evening_stock['notes'] ?? '',
            'days_back' => intval($evening_stock['days_back'] ?? 1),
        ];
        $auto_providers = $esp;
        $auto_cash = $cash_value;
        $auto_notes = $evening_stock['notes'] ?? '';
        $has_data = true;
    }
}

// ============================================================
// AUTO-FILL LOGIC - STEP 2: CAPITAL MANAGEMENT (Fallback)
// ============================================================
if (!$has_data) {
    // ------------------------------------------------------------
    // Get the latest CASH for this branch
    // ------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT amount, capital_number, transaction_date
        FROM capital_management
        WHERE branch_id = ? 
          AND reference_module = 'cash_manual'
        ORDER BY transaction_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $capital_cash_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $capital_cash = $capital_cash_row ? floatval(str_replace(',', '', $capital_cash_row['amount'])) : 0;

    // ------------------------------------------------------------
    // Get the latest FLOAT for each provider
    // FIX: reference_id refers to branch_providers.id
    // ------------------------------------------------------------
    $stmt = $db->prepare("
        SELECT 
            cm.id AS capital_id,
            cm.amount, 
            cm.transaction_date,
            bp.provider_id,
            bp.provider_code,
            p.provider_name, 
            p.icon_class, 
            p.color_code, 
            p.provider_type, 
            p.display_order
        FROM capital_management cm
        INNER JOIN branch_providers bp ON cm.reference_id = bp.id
        INNER JOIN providers p ON bp.provider_id = p.id
        WHERE cm.branch_id = ? 
          AND cm.reference_module = 'provider'
          AND cm.id IN (
              SELECT MAX(id) 
              FROM capital_management 
              WHERE branch_id = ? 
                AND reference_module = 'provider'
              GROUP BY reference_id
          )
        ORDER BY p.display_order, p.provider_name
    ");
    $stmt->execute([$branch_id, $branch_id]);
    $capital_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($capital_providers) || $capital_cash > 0) {
        $source_type = 'capital_management';
        
        // Find the latest date
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
            'employee_code' => '-',
            'cash' => $capital_cash,
            'cumm' => 0,
            'notes' => 'Auto-filled from Opening Capital',
            'days_back' => 0,
        ];

        foreach ($capital_providers as $cp) {
            $auto_providers[] = [
                'provider_id' => intval($cp['provider_id']),
                'provider_code' => $cp['provider_code'] ?? '-',
                'provider_name' => $cp['provider_name'] ?? 'Unknown',
                'closing_float' => floatval(str_replace(',', '', $cp['amount'])),
                'closing_cash' => 0,
                'total_deposits' => 0,
                'total_withdrawals' => 0,
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
// CALCULATE PREVIEW TOTALS
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_morning_report') {
    try {
        $db->beginTransaction();

        $post_branch_id    = intval($_POST['branch_id'] ?? 0);
        $post_date         = $_POST['report_date'] ?? date('Y-m-d');
        $post_source_type  = $_POST['source_type'] ?? 'manual';
        $post_source_id    = intval($_POST['source_evening_stock_id'] ?? 0);
        $post_cash         = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $post_notes        = trim($_POST['notes'] ?? '');
        $post_providers    = $_POST['providers'] ?? [];

        // ------------------------------------------------------------
        // VALIDATION
        // ------------------------------------------------------------
        if ($post_branch_id <= 0) {
            throw new Exception('Invalid branch.');
        }
        if ($post_cash < 0) {
            throw new Exception('Cash balance cannot be negative.');
        }
        if (strtotime($post_date) > strtotime(date('Y-m-d'))) {
            throw new Exception('Report date cannot be in the future.');
        }

        // Check duplicate
        $stmt = $db->prepare("SELECT id FROM morning_reports WHERE branch_id = ? AND report_date = ? LIMIT 1");
        $stmt->execute([$post_branch_id, $post_date]);
        if ($stmt->fetch()) {
            throw new Exception('Morning report for this date already exists.');
        }

        // ------------------------------------------------------------
        // Calculate total float
        // ------------------------------------------------------------
        $total_float = 0;
        foreach ($post_providers as $pid => $float) {
            $fv = floatval(str_replace(',', '', $float));
            if ($fv < 0) {
                throw new Exception('Provider float cannot be negative.');
            }
            $total_float += $fv;
        }
        $cumm_total = $total_float + $post_cash;

        if ($total_float <= 0 && $post_cash <= 0) {
            throw new Exception('Report cannot be empty. Provide at least one float or cash balance.');
        }

        // ------------------------------------------------------------
        // Generate report number
        // ------------------------------------------------------------
        $report_number = 'MR-' . date('Ymd', strtotime($post_date)) . '-' . 
                         strtoupper(substr($branch_code, 0, 3)) . '-' . 
                         str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        // ------------------------------------------------------------
        // Source type mapping
        // ------------------------------------------------------------
        $db_source_type = ($post_source_type === 'capital_management') ? 'manual' : 'auto_from_evening';
        $db_source_id = ($post_source_type === 'evening_stock' && $post_source_id > 0) ? $post_source_id : null;

        // ------------------------------------------------------------
        // 1) INSERT morning_reports
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            INSERT INTO morning_reports 
            (report_number, employee_id, branch, branch_id, report_date,
             provider_data, cash_balance, cumm_total, submitted_at,
             notes, source_type, source_evening_stock_id, is_locked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 0)
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
            $db_source_type, 
            $db_source_id
        ]);

        $report_id = $db->lastInsertId();

        // ------------------------------------------------------------
        // 2) Get provider map for branch
        // ------------------------------------------------------------
        $provider_map = [];
        $stmt2 = $db->prepare("
            SELECT p.id, p.provider_name, bp.provider_code
            FROM providers p
            INNER JOIN branch_providers bp ON bp.provider_id = p.id
            WHERE bp.branch_id = ? AND bp.is_active = 1
        ");
        $stmt2->execute([$post_branch_id]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $provider_map[intval($row['id'])] = $row;
        }

        // ------------------------------------------------------------
        // 3) INSERT morning_report_providers
        // ------------------------------------------------------------
        $stmt = $db->prepare("
            INSERT INTO morning_report_providers
            (report_id, provider_id, provider_code, provider_name, 
             float_balance, cash_balance, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");

        foreach ($post_providers as $pid => $float) {
            $pid = intval($pid);
            $fv = floatval(str_replace(',', '', $float));
            if ($pid <= 0 || $fv <= 0) continue;

            $pinfo = $provider_map[$pid] ?? null;
            if (!$pinfo) continue;

            $stmt->execute([
                $report_id, 
                $pid, 
                $pinfo['provider_code'], 
                $pinfo['provider_name'], 
                $fv
            ]);
        }

        // ------------------------------------------------------------
        // 4) INSERT daily_reports
        // ------------------------------------------------------------
        $daily_report_number = 'DR-' . date('Ymd', strtotime($post_date)) . '-' . 
                               str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $stmt_dr = $db->prepare("
            INSERT INTO daily_reports 
            (report_number, employee_id, branch, branch_id, report_date,
             morning_report_id, morning_total, current_cash, current_float,
             total_business_income, current_capital, created_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");

        $stmt_dr->execute([
            $daily_report_number, 
            $user_id, 
            $branch_name, 
            $post_branch_id, 
            $post_date,
            $report_id, 
            $cumm_total, 
            $post_cash, 
            $total_float,
            $cumm_total, 
            $cumm_total, 
            $post_notes
        ]);

        $daily_report_id = $db->lastInsertId();

        // ------------------------------------------------------------
        // 5) INSERT daily_report_providers
        // ------------------------------------------------------------
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
            if ($pid <= 0 || $fv <= 0) continue;

            $pinfo = $provider_map[$pid] ?? null;
            if (!$pinfo) continue;

            $stmt_drp->execute([
                $daily_report_id, 
                $pid, 
                $pinfo['provider_code'], 
                $pinfo['provider_name'],
                $fv, 
                $fv
            ]);
        }

        // ------------------------------------------------------------
        // LOG ACTIVITY
        // ------------------------------------------------------------
        $log_source = ($post_source_type === 'evening_stock') 
                      ? 'evening stock ID ' . $post_source_id 
                      : 'opening capital';
        logActivity(
            $user_id, 
            'Generate Morning Report', 
            'Morning Report', 
            $report_id, 
            '',
            'Generated ' . $report_number . ' from ' . $log_source . 
            ' for ' . $branch_name . 
            ' - Float: ' . number_format($total_float) . ', Cash: ' . number_format($post_cash)
        );

        $db->commit();

        $_SESSION['success_message'] = 'Morning Report ' . $report_number . ' generated successfully!';
        header('Location: view.php?id=' . $report_id);
        exit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error_message = $e->getMessage();
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
                <p class="text-muted">Auto-generate from previous Evening Stock or Opening Capital</p>
            </div>
        </div>

        <!-- LOGIC EXPLANATION -->
        <div class="logic-banner">
            <div class="logic-icon"><i class="fas fa-lightbulb"></i></div>
            <div class="logic-content">
                <h4>How It Works</h4>
                <p>
                    The Morning Report for <strong><?php echo date('d M Y', strtotime($target_date)); ?></strong> 
                    is generated from the <strong>latest Evening Stock</strong> before this date.
                    If no Evening Stock exists, the system will use the branch's 
                    <strong>Opening Capital</strong> as a fallback.
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

        <?php if (!$has_data): ?>
            <!-- ============================================================ -->
            <!-- NO DATA - WAITING FOR CAPITAL -->
            <!-- ============================================================ -->
            <div class="waiting-card">
                <div class="waiting-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <h3 class="waiting-title">NO EVENING STOCK</h3>
                <p class="waiting-text">
                    No <strong>Evening Stock</strong> found for this branch 
                    before <?php echo date('d M Y', strtotime($target_date)); ?>, 
                    and no <strong>Opening Capital</strong> has been set up.
                    <br><br>
                    Please submit an <strong>Evening Stock</strong> for a previous date, 
                    or add an <strong>Opening Capital</strong> in Capital Management.
                </p>
                <div class="waiting-actions">
                    <a href="../capital_management/index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-primary">
                        <i class="fas fa-coins"></i> Go to Capital Management
                    </a>
                    <a href="../evening_stock/index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-moon"></i> Go to Evening Stock
                    </a>
                    <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        <?php else: ?>

            <!-- ============================================================ -->
            <!-- SOURCE STOCK INFO -->
            <!-- ============================================================ -->
            <div class="source-card">
                <div class="source-card-header">
                    <?php if ($source_type === 'evening_stock'): ?>
                        <i class="fas fa-moon"></i>
                        <h3>Source: Evening Stock</h3>
                        <span class="source-badge"><?php echo htmlspecialchars($source_data['number']); ?></span>
                        <?php if (isset($source_data['days_back']) && $source_data['days_back'] > 1): ?>
                            <span class="days-badge">
                                <?php echo $source_data['days_back']; ?> days ago
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <i class="fas fa-coins"></i>
                        <h3>Source: Opening Capital</h3>
                        <span class="source-badge"><?php echo htmlspecialchars($source_data['number']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="source-card-body">
                    <div class="source-info-grid">
                        <div class="source-info-item">
                            <span class="source-label">Source Date</span>
                            <span class="source-value">
                                <?php echo date('d M Y', strtotime($source_data['date'])); ?>
                            </span>
                        </div>
                        <div class="source-info-item">
                            <span class="source-label"><?php echo $source_type === 'evening_stock' ? 'Submitted By' : 'Source'; ?></span>
                            <span class="source-value">
                                <?php echo htmlspecialchars($source_data['employee']); ?>
                            </span>
                        </div>
                        <div class="source-info-item">
                            <span class="source-label">Cash Balance</span>
                            <span class="source-value mono">
                                <?php echo formatCurrency($source_data['cash']); ?>
                            </span>
                        </div>
                        <div class="source-info-item">
                            <span class="source-label">Total Float</span>
                            <span class="source-value mono">
                                <?php echo formatCurrency($preview_total_float); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- GENERATE FORM -->
            <!-- ============================================================ -->
            <form method="POST" action="" class="generate-form" id="generateForm" onsubmit="return validateGenerate()">
                <input type="hidden" name="action" value="generate_morning_report">
                <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                <input type="hidden" name="source_type" value="<?php echo htmlspecialchars($source_type); ?>">
                <input type="hidden" name="source_evening_stock_id" value="<?php echo ($source_type === 'evening_stock' && $source_data) ? intval($source_data['id']) : 0; ?>">

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
                                <small class="form-hint">Date for this morning report</small>
                            </div>
                            <div class="form-group">
                                <label>Cash Balance (TSh) <span class="required">*</span></label>
                                <input type="text" name="cash_balance" id="cash_balance" 
                                       class="form-control money-input readonly-input" 
                                       value="<?php echo number_format($preview_cash); ?>" 
                                       readonly tabindex="-1">
                                <small class="form-hint">Carried over from source</small>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- PROVIDERS -->
                <div class="form-card">
                    <div class="form-card-header">
                        <i class="fas fa-university"></i>
                        <h3>Provider Floats</h3>
                        <span class="providers-count"><?php echo count($auto_providers); ?> providers</span>
                    </div>
                    <div class="form-card-body">
                        <?php if (count($auto_providers) > 0): ?>
                            <div class="providers-grid">
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
                                <p>No providers found in this source.</p>
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
                                      placeholder="Additional notes..."><?php echo htmlspecialchars($auto_notes); ?></textarea>
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
/* ============================================================
   VARIABLES
   ============================================================ */
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
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; font-size: 13px; font-weight: 500; }
.alert-close { background: transparent; border: none; font-size: 22px; color: inherit; cursor: pointer; opacity: 0.6; }

/* WAITING CARD */
.waiting-card {
    background: var(--bg-card);
    border-radius: 16px;
    border: 2px dashed #F59E0B;
    padding: 60px 30px;
    text-align: center;
    box-shadow: 0 4px 16px var(--shadow-color);
    margin-bottom: 20px;
}
.waiting-icon {
    width: 90px; height: 90px;
    background: linear-gradient(135deg, #FEF3C7, #FDE68A);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #D97706;
    margin: 0 auto 20px;
    border: 3px solid #FCD34D;
    animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(217, 119, 6, 0.4); }
    50% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(217, 119, 6, 0); }
}
html.dark-mode .waiting-icon {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    color: #FCD34D; border-color: #D97706;
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
    color: #D97706;
    background: #FEF3C7;
    padding: 2px 8px; border-radius: 6px;
    font-weight: 800;
}
html.dark-mode .waiting-text strong { background: #5F3A1E; color: #FCD34D; }
.waiting-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

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
.days-badge {
    font-size: 10px; font-weight: 700;
    padding: 4px 10px;
    background: rgba(255,255,255,0.25);
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.35);
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
    .waiting-actions { flex-direction: column; }
    .waiting-actions .btn { width: 100%; justify-content: center; }
    .waiting-title { font-size: 20px; }
    .waiting-icon { width: 70px; height: 70px; font-size: 32px; }
}
</style>

<script>
function validateGenerate() {
    const reportDate = document.getElementById('report_date').value;
    if (!reportDate) {
        alert('Please select report date.');
        return false;
    }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
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