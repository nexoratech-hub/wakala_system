<?php
// ================================================================
// FILE: modules/capital_management/index.php
// CAPITAL MANAGEMENT - MAIN INDEX
// ✅ FIXED: Delete inaondoa capital_management NA inapunguza daily_reports
// ✅ FIXED: Summary inasoma kutoka daily_reports (live)
// ✅ FIXED: View, Edit, Delete buttons
// ✅ FIXED: Export dropdown
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// ✅ HANDLE AJAX: DELETE CAPITAL
// ✅ Futa capital_management + rekebisha daily_reports
// ============================================================
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_capital') {
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $response = ['success' => false, 'message' => 'Unknown error'];
    
    try {
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        error_log("DELETE CAPITAL: id=$id, user_id=$user_id");
        
        if ($id <= 0) {
            throw new Exception('Invalid ID: ' . $id);
        }
        
        // Check record exists
        $stmt = $db->prepare("
            SELECT id, capital_number, branch_id, amount, 
                   transaction_type, reference_module, reference_id, transaction_date
            FROM capital_management 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $cap = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cap) {
            throw new Exception('Capital record not found (ID: ' . $id . ')');
        }
        
        $db->beginTransaction();
        
        try {
            // ============================================================
            // STEP 1: Rekebisha daily_reports KABLA ya delete
            // ============================================================
            $branch_id = intval($cap['branch_id']);
            $amount = floatval($cap['amount']);
            $is_outgoing = in_array($cap['transaction_type'], ['cash_out', 'adjustment']);
            $is_float = ($cap['reference_module'] === 'provider');
            
            // Pata latest daily_report kwa branch hii
            $stmt = $db->prepare("
                SELECT id, current_float, current_cash, current_capital
                FROM daily_reports
                WHERE branch_id = ?
                ORDER BY report_date DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$branch_id]);
            $dr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($dr) {
                $dr_id = $dr['id'];
                $dr_float = floatval($dr['current_float']);
                $dr_cash = floatval($dr['current_cash']);
                
                // ✅ Hesabu new values
                // Delete ya record inamaanisha: reverse the effect
                // Kama ilikuwa incoming (+) → toa
                // Kama ilikuwa outgoing (-) → ongeza (kwa sababu ilikuwa inapunguza)
                
                if ($is_float) {
                    // Float record
                    if ($is_outgoing) {
                        // Ilikuwa cash_out → ilipunguza float, sasa tunarudisha
                        $new_float = $dr_float + $amount;
                    } else {
                        // Ilikuwa opening/additional → iliongeza float, sasa tunatoa
                        $new_float = $dr_float - $amount;
                    }
                    $new_cash = $dr_cash;
                } else {
                    // Cash record
                    if ($is_outgoing) {
                        // Ilikuwa cash_out → ilipunguza cash, sasa tunarudisha
                        $new_cash = $dr_cash + $amount;
                    } else {
                        // Ilikuwa opening/additional → iliongeza cash, sasa tunatoa
                        $new_cash = $dr_cash - $amount;
                    }
                    $new_float = $dr_float;
                }
                
                // Hakikisha hazipungui chini ya 0
                $new_float = max(0, $new_float);
                $new_cash = max(0, $new_cash);
                $new_capital = $new_float + $new_cash;
                
                // Update daily_reports
                $stmt = $db->prepare("
                    UPDATE daily_reports
                    SET current_float = ?,
                        current_cash = ?,
                        current_capital = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$new_float, $new_cash, $new_capital, $dr_id]);
                
                error_log("DELETE CAPITAL: Updated daily_report id=$dr_id, float=$new_float, cash=$new_cash, capital=$new_capital");
            }
            
            // ============================================================
            // STEP 2: Futa capital_management record
            // ============================================================
            $stmt = $db->prepare("DELETE FROM capital_management WHERE id = ?");
            $result = $stmt->execute([$id]);
            
            if (!$result) {
                throw new Exception('Failed to execute DELETE query');
            }
            
            $rows_affected = $stmt->rowCount();
            
            if ($rows_affected === 0) {
                throw new Exception('No rows were deleted');
            }
            
            // ============================================================
            // STEP 3: Recalculate daily_reports totals (kama ina float providers)
            // ============================================================
            if ($dr) {
                // Kama ni provider float, hesabu upya kutoka daily_report_providers
                if ($is_float) {
                    $stmt = $db->prepare("
                        SELECT COALESCE(SUM(latest.current_float), 0) as total_float
                        FROM (
                            SELECT drp1.provider_id, drp1.current_float
                            FROM daily_report_providers drp1
                            INNER JOIN (
                                SELECT provider_id, MAX(id) as max_id
                                FROM daily_report_providers
                                WHERE daily_report_id = ?
                                GROUP BY provider_id
                            ) drp2 ON drp1.id = drp2.max_id
                        ) latest
                    ");
                    $stmt->execute([$dr_id]);
                    $f = $stmt->fetch(PDO::FETCH_ASSOC);
                    $recalc_float = floatval($f['total_float'] ?? 0);
                    
                    // Kama recalc inatofautiana na new_float, tumia recalc
                    if ($recalc_float > 0 && abs($recalc_float - $new_float) > 1) {
                        $new_float = $recalc_float;
                        $new_capital = $new_float + $new_cash;
                        
                        $stmt = $db->prepare("
                            UPDATE daily_reports
                            SET current_float = ?,
                                current_capital = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$new_float, $new_capital, $dr_id]);
                    }
                }
            }
            
            // ============================================================
            // STEP 4: Log activity
            // ============================================================
            try {
                if (function_exists('logActivity')) {
                    logActivity(
                        $user_id, 
                        'Delete Capital', 
                        'Capital Management', 
                        $id, 
                        '', 
                        'Deleted capital: ' . $cap['capital_number'] . 
                        ' (Kiasi: ' . number_format($amount, 0) . ')' . 
                        ($is_float ? ' [Float]' : ' [Cash]')
                    );
                }
            } catch (Exception $logError) {
                error_log("Log activity error: " . $logError->getMessage());
            }
            
            $db->commit();
            
            $response = [
                'success' => true,
                'message' => 'Capital record "' . $cap['capital_number'] . '" deleted! Daily report updated.',
                'deleted_id' => $id,
                'updated_daily_report' => $dr ? true : false
            ];
            
        } catch (Exception $innerError) {
            $db->rollBack();
            throw $innerError;
        }
        
    } catch (PDOException $e) {
        error_log("DELETE CAPITAL PDO ERROR: " . $e->getMessage());
        $response = [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        error_log("DELETE CAPITAL ERROR: " . $e->getMessage());
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    echo json_encode($response);
    exit();
}

// ============================================================
// GET FILTERS
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
    if ($selected_branch < 0) $selected_branch = 0;
}

// ============================================================
// GET BRANCHES
// ============================================================
try {
    $stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $stmt->execute();
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branches = [];
}

// ============================================================
// GET SELECTED BRANCH INFO
// ============================================================
$filter_branch_name = 'All Branches';
$filter_branch_code = '';
$filter_branch_location = '';
$branch_found = false;

if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $filter_branch_name = $b['branch_name'];
            $filter_branch_code = $b['branch_code'] ?? '';
            $filter_branch_location = $b['location'] ?? '';
            $branch_found = true;
            break;
        }
    }
    
    if (!$branch_found) {
        $selected_branch = 0;
        $filter_branch_name = 'All Branches';
    }
}

// ============================================================
// ✅ GET CURRENT CAPITAL - KUTOKA daily_reports PEKEE
// Baada ya delete, daily_reports imesharekebishwa
// ============================================================
$current_cash = 0;
$current_float = 0;
$current_capital = 0;

function getCurrentCapitalFromDR($db, $branch_id) {
    $result = ['cash' => 0, 'float' => 0, 'capital' => 0];
    
    // Soma kutoka daily_reports (latest record)
    $stmt = $db->prepare("
        SELECT current_cash, current_capital, current_float
        FROM daily_reports 
        WHERE branch_id = ? 
        ORDER BY report_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$branch_id]);
    $dr = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dr) {
        $result['cash'] = floatval($dr['current_cash'] ?? 0);
        $result['capital'] = floatval($dr['current_capital'] ?? 0);
        $result['float'] = floatval($dr['current_float'] ?? 0);
    }
    
    return $result;
}

if ($selected_branch > 0) {
    $data = getCurrentCapitalFromDR($db, $selected_branch);
    $current_cash = $data['cash'];
    $current_float = $data['float'];
    $current_capital = $data['capital'];
} else {
    foreach ($branches as $b) {
        $data = getCurrentCapitalFromDR($db, $b['id']);
        $current_cash += $data['cash'];
        $current_float += $data['float'];
        $current_capital += $data['capital'];
    }
}

// ============================================================
// BUILD QUERY (Transactions List)
// ============================================================
try {
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
            b.location as branch_location,
            p.provider_name,
            p.icon_class as provider_icon,
            p.color_code as provider_color,
            bp.provider_code as branch_provider_code
            FROM capital_management cm
            LEFT JOIN employees e ON cm.employee_id = e.id
            LEFT JOIN branches b ON cm.branch_id = b.id
            LEFT JOIN providers p ON cm.reference_id = p.id AND cm.reference_module = 'provider'
            LEFT JOIN branch_providers bp ON bp.branch_id = cm.branch_id AND bp.provider_id = p.id
            WHERE cm.transaction_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($selected_branch > 0) {
        $sql .= " AND cm.branch_id = ?";
        $params[] = $selected_branch;
    }

    if (!empty($type_filter)) {
        $sql .= " AND cm.transaction_type = ?";
        $params[] = $type_filter;
    }

    $sql .= " ORDER BY cm.branch_id ASC, cm.transaction_date DESC, cm.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $all_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $all_transactions = [];
}

// ============================================================
// BUILD FLAT LIST
// ============================================================
$flat_transactions = [];
$grand_totals = [
    'total_in' => 0,
    'total_out' => 0,
    'float_in' => 0,
    'cash_in' => 0,
    'count' => 0,
    'branches_count' => 0
];

$branch_summary = [];
foreach ($branches as $b) {
    $branch_summary[$b['id']] = [
        'branch_id' => $b['id'],
        'branch_name' => $b['branch_name'],
        'branch_code' => $b['branch_code'] ?? '',
        'count' => 0
    ];
}

$previous_branch_id = null;
$branch_ids_seen = [];

foreach ($all_transactions as $t) {
    $b_id = $t['branch_id'] ?? 0;
    $is_new_branch = ($previous_branch_id !== null && $previous_branch_id != $b_id);
    
    if (isset($branch_summary[$b_id])) {
        $branch_summary[$b_id]['count']++;
        if (!in_array($b_id, $branch_ids_seen)) {
            $branch_ids_seen[] = $b_id;
        }
    }
    
    $is_outgoing = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
    $amount = floatval($t['amount']);
    $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
    
    if ($is_outgoing) {
        $grand_totals['total_out'] += $amount;
    } else {
        $grand_totals['total_in'] += $amount;
        if ($is_float) {
            $grand_totals['float_in'] += $amount;
        } else {
            $grand_totals['cash_in'] += $amount;
        }
    }
    $grand_totals['count']++;
    
    $flat_transactions[] = [
        'transaction' => $t,
        'is_new_branch' => $is_new_branch
    ];
    
    $previous_branch_id = $b_id;
}

$grand_totals['branches_count'] = count($branch_ids_seen);
$grand_totals['net_capital'] = $grand_totals['total_in'] - $grand_totals['total_out'];

$type_labels = [
    'opening' => ['label' => 'Opening', 'icon' => 'fa-play', 'color' => 'blue'],
    'additional' => ['label' => 'Additional', 'icon' => 'fa-plus-circle', 'color' => 'green'],
    'profit_allocation' => ['label' => 'Profit Allocation', 'icon' => 'fa-chart-line', 'color' => 'purple'],
    'cash_out' => ['label' => 'Cash Out', 'icon' => 'fa-money-bill-wave', 'color' => 'red'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders-h', 'color' => 'orange']
];

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH FILTER CARD -->
        <div class="branch-filter-card <?php echo $selected_branch > 0 ? 'filter-active' : 'filter-all'; ?>">
            <div class="filter-left">
                <i class="fas <?php echo $selected_branch > 0 ? 'fa-store-alt' : 'fa-globe-africa'; ?>"></i>
                <span class="filter-label">Showing:</span>
                <span class="filter-name"><?php echo htmlspecialchars($filter_branch_name); ?></span>
                <?php if ($filter_branch_code): ?>
                    <span class="filter-code">(<?php echo htmlspecialchars($filter_branch_code); ?>)</span>
                <?php endif; ?>
                <?php if ($selected_branch > 0): ?>
                    <a href="?branch_id=0&branch=0&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&type=<?php echo $type_filter; ?>" class="filter-clear">
                        <i class="fas fa-times-circle"></i> Show All Branches
                    </a>
                <?php endif; ?>
            </div>
            <div class="filter-right">
                <a href="add.php?branch=<?php echo $selected_branch; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Transaction
                </a>
                <a href="history.php?branch=<?php echo $selected_branch; ?>" class="btn btn-info">
                    <i class="fas fa-history"></i> Full History
                </a>
                <div class="dropdown export-dropdown" id="exportDropdown">
                    <button type="button" class="btn btn-export dropdown-toggle" id="exportToggleBtn" onclick="toggleExportDropdown(event)">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a href="#" onclick="exportData('csv'); return false;"><i class="fas fa-file-csv"></i> CSV</a>
                        <a href="#" onclick="exportData('excel'); return false;"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="#" onclick="exportData('pdf'); return false;"><i class="fas fa-file-pdf"></i> PDF</a>
                        <a href="#" onclick="window.print(); return false;"><i class="fas fa-print"></i> Print</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-building" style="color:#bb0404;"></i> Capital Management</h2>
                <p class="text-muted">
                    <?php if ($selected_branch > 0): ?>
                        Capital transactions for <strong><?php echo htmlspecialchars($filter_branch_name); ?></strong>
                    <?php else: ?>
                        All capital transactions (continuous view)
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- CURRENT CAPITAL SUMMARY -->
        <div class="current-capital-wrapper">
            <div class="current-capital-header">
                <div class="cch-left">
                    <div class="cch-icon">
                        <i class="fas fa-vault"></i>
                    </div>
                    <div class="cch-info">
                        <span class="cch-title">Current Branch Capital</span>
                        <span class="cch-subtitle">
                            <?php echo $selected_branch > 0 ? htmlspecialchars($filter_branch_name) : 'All Branches (Latest)'; ?>
                            • Live Values
                        </span>
                    </div>
                </div>
                <div class="cch-badge">
                    <i class="fas fa-check-circle"></i> Live Values
                </div>
            </div>
            
            <div class="current-capital-grid">
                <div class="current-card card-float">
                    <div class="cc-icon cc-icon-blue">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="cc-content">
                        <span class="cc-label">Total Float</span>
                        <span class="cc-value cc-value-blue" id="currentFloatValue"><?php echo formatCurrency($current_float); ?></span>
                        <span class="cc-sub">Provider floats</span>
                    </div>
                </div>
                
                <div class="current-card card-cash">
                    <div class="cc-icon cc-icon-green">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="cc-content">
                        <span class="cc-label">Cash Balance</span>
                        <span class="cc-value cc-value-green" id="currentCashValue"><?php echo formatCurrency($current_cash); ?></span>
                        <span class="cc-sub">Branch cash</span>
                    </div>
                </div>
                
                <div class="current-card card-capital">
                    <div class="cc-icon cc-icon-purple">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="cc-content">
                        <span class="cc-label">Total Capital</span>
                        <span class="cc-value cc-value-purple" id="currentCapitalValue"><?php echo formatCurrency($current_capital); ?></span>
                        <span class="cc-sub">Float + Cash</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- PERIOD SUMMARY CARDS -->
        <div class="period-summary-wrapper">
            <div class="period-header">
                <div class="ph-left">
                    <div class="ph-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="ph-info">
                        <span class="ph-title">Period Summary</span>
                        <span class="ph-subtitle">
                            <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                        </span>
                    </div>
                </div>
                <div class="ph-badge">
                    <i class="fas fa-database"></i>
                    <span id="periodRecordCount"><?php echo $grand_totals['count']; ?></span> Records
                </div>
            </div>
            
            <div class="period-cards-grid">
                <div class="period-card card-total-in">
                    <div class="pc-icon pc-icon-green">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="pc-content">
                        <span class="pc-label">Total In</span>
                        <span class="pc-value pc-value-green" id="periodTotalIn">
                            + <?php echo formatCurrency($grand_totals['total_in']); ?>
                        </span>
                        <span class="pc-sub">Period incoming</span>
                    </div>
                </div>
                
                <div class="period-card card-total-out">
                    <div class="pc-icon pc-icon-red">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="pc-content">
                        <span class="pc-label">Total Out</span>
                        <span class="pc-value pc-value-red" id="periodTotalOut">
                            - <?php echo formatCurrency($grand_totals['total_out']); ?>
                        </span>
                        <span class="pc-sub">Period outgoing</span>
                    </div>
                </div>
                
                <div class="period-card card-net">
                    <div class="pc-icon pc-icon-blue">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="pc-content">
                        <span class="pc-label">Net Change</span>
                        <span class="pc-value <?php echo $grand_totals['net_capital'] >= 0 ? 'pc-value-green' : 'pc-value-red'; ?>" id="periodNet">
                            <?php echo formatCurrency($grand_totals['net_capital']); ?>
                        </span>
                        <span class="pc-sub">In - Out</span>
                    </div>
                </div>
                
                <div class="period-card card-float-in">
                    <div class="pc-icon pc-icon-purple">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="pc-content">
                        <span class="pc-label">Float In</span>
                        <span class="pc-value pc-value-purple" id="periodFloatIn">
                            <?php echo formatCurrency($grand_totals['float_in']); ?>
                        </span>
                        <span class="pc-sub">Provider float</span>
                    </div>
                </div>
                
                <div class="period-card card-cash-in">
                    <div class="pc-icon pc-icon-teal">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="pc-content">
                        <span class="pc-label">Cash In</span>
                        <span class="pc-value pc-value-teal" id="periodCashIn">
                            <?php echo formatCurrency($grand_totals['cash_in']); ?>
                        </span>
                        <span class="pc-sub">Branch cash</span>
                    </div>
                </div>
                
                <div class="period-card card-branches">
                    <div class="pc-icon pc-icon-orange">
                        <i class="fas fa-store-alt"></i>
                    </div>
                    <div class="pc-content">
                        <span class="pc-label">Branches</span>
                        <span class="pc-value pc-value-orange" id="periodBranchesCount">
                            <?php echo $grand_totals['branches_count']; ?>
                        </span>
                        <span class="pc-sub">With transactions</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- FILTERS -->
        <div class="filters-bar">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>" class="form-control">
                </div>
                <div class="filter-group">
                    <label>Branch</label>
                    <select name="branch_id" class="form-control">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="">All Types</option>
                        <?php foreach ($type_labels as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter == $key ? 'selected' : ''; ?>>
                                <?php echo $label['label']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group filter-buttons">
                    <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Filter</button>
                    <a href="index.php" class="btn btn-reset"><i class="fas fa-undo"></i> Reset</a>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <?php if (count($flat_transactions) > 0): ?>
            
            <div class="table-container-main">
                
                <div class="table-header-red">
                    
                    <div class="thr-left">
                        <i class="fas fa-list"></i>
                        <h3>Capital Transactions</h3>
                        <span class="thr-count" id="tableRecordCount"><?php echo $grand_totals['count']; ?> records</span>
                    </div>
                    
                    <div class="thr-center">
                        <button type="button" class="scroll-btn-header" onclick="scrollTableMain('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <div class="search-wrapper-main">
                            <i class="fas fa-search"></i>
                            <input type="text" 
                                   id="globalSearchInput" 
                                   placeholder="Search..."
                                   oninput="onGlobalSearch(this)">
                            <button type="button" id="globalSearchClear" onclick="clearGlobalSearch()" style="display:none;">
                                <i class="fas fa-times"></i>
                            </button>
                            <span class="search-count-main" id="globalSearchCount" style="display:none;">0</span>
                        </div>
                        
                        <button type="button" class="scroll-btn-header" onclick="scrollTableMain('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    
                    <div class="thr-right">
                        <span class="thr-branches-badge">
                            <i class="fas fa-store-alt"></i>
                            <?php echo $grand_totals['branches_count']; ?> branches
                        </span>
                    </div>
                </div>
                
                <div class="table-responsive-main" id="tableWrapperMain">
                    <table class="data-table-main" id="capitalTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 120px;">Branch</th>
                                <th>Number</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Source</th>
                                <th>Employee</th>
                                <th class="text-right">Amount</th>
                                <th style="width: 140px;" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $global_counter = 1;
                            foreach ($flat_transactions as $item): 
                                $t = $item['transaction'];
                                $is_new_branch = $item['is_new_branch'];
                                
                                $type_info = $type_labels[$t['transaction_type']] ?? ['label' => $t['transaction_type'], 'color' => 'gray'];
                                $is_out = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
                                $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
                                
                                $search_data = strtolower(
                                    $t['capital_number'] . ' ' .
                                    ($t['provider_name'] ?? '') . ' ' .
                                    ($t['branch_provider_code'] ?? '') . ' ' .
                                    ($t['employee_name'] ?? '') . ' ' .
                                    ($t['branch_name'] ?? '') . ' ' .
                                    ($t['branch_code'] ?? '') . ' ' .
                                    ($t['description'] ?? '') . ' ' .
                                    ($t['notes'] ?? '') . ' ' .
                                    $type_info['label']
                                );
                            ?>
                                <?php if ($is_new_branch && $global_counter > 1): ?>
                                    <tr class="branch-separator-row">
                                        <td colspan="9">
                                            <div class="branch-separator-line"></div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                
                                <tr class="transaction-row" 
                                    data-id="<?php echo $t['id']; ?>"
                                    data-branch-id="<?php echo $t['branch_id']; ?>"
                                    data-search="<?php echo htmlspecialchars($search_data); ?>">
                                    <td class="row-number"><?php echo $global_counter++; ?></td>
                                    <td>
                                        <span class="branch-cell-badge">
                                            <i class="fas fa-store-alt"></i>
                                            <?php echo htmlspecialchars($t['branch_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?php echo $t['id']; ?>" class="capital-link">
                                            <?php echo htmlspecialchars($t['capital_number']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="date-display">
                                            <?php echo date('d M Y', strtotime($t['transaction_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?php echo $type_info['color']; ?>">
                                            <i class="fas <?php echo $type_info['icon']; ?>"></i>
                                            <?php echo $type_info['label']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($is_float): ?>
                                            <div class="source-display source-float">
                                                <div class="source-icon-sm" style="background: <?php echo htmlspecialchars($t['provider_color'] ?? '#0B5ED7'); ?>;">
                                                    <i class="<?php echo htmlspecialchars($t['provider_icon'] ?? 'fas fa-university'); ?>"></i>
                                                </div>
                                                <div class="source-info-sm">
                                                    <span class="source-name"><?php echo htmlspecialchars($t['provider_name']); ?></span>
                                                    <span class="source-code"><?php echo htmlspecialchars($t['branch_provider_code'] ?? 'N/A'); ?></span>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="source-display source-cash">
                                                <div class="source-icon-sm">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                </div>
                                                <div class="source-info-sm">
                                                    <span class="source-name">Cash</span>
                                                    <span class="source-code">Manual</span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="employee-display">
                                            <?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="amount-display <?php echo $is_out ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo $is_out ? '-' : '+'; ?>
                                            <?php echo formatCurrency($t['amount']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $t['id']; ?>" 
                                               class="btn-action btn-view" 
                                               title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $t['id']; ?>" 
                                               class="btn-action btn-edit" 
                                               title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn-action btn-delete" 
                                                    onclick="confirmDelete(<?php echo $t['id']; ?>, '<?php echo htmlspecialchars($t['capital_number']); ?>')" 
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="no-results-main" id="noResultsMain" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No records found</h3>
                    <p>No capital transactions match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearGlobalSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            </div>
            
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Capital Transactions</h3>
                <p>No capital transactions found for the selected period.</p>
                <?php if ($selected_branch > 0): ?>
                    <a href="add.php?branch=<?php echo $selected_branch; ?>" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add First Transaction
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-header-danger">
            <div class="modal-icon-danger">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="modal-header-content">
                <h3>Delete Capital Record?</h3>
                <p>This action cannot be undone.</p>
            </div>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <p class="modal-text">Are you sure you want to delete:</p>
            <div class="modal-capital-number" id="deleteCapitalNumber">-</div>
            <p class="modal-warning">All data for this record will be permanently removed from the system. <strong>Daily Report will also be updated.</strong></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn-confirm-delete" id="confirmDeleteBtn" onclick="executeDelete()">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<style>
/* ============================================================
   CSS VARIABLES
   ============================================================ */
:root {
    --cm-bg: #F3F4F6;
    --cm-text: #1F2937;
    --cm-text-secondary: #6B7280;
    --cm-text-light: #9CA3AF;
    --cm-border: #E5E7EB;
    --cm-card-bg: #FFFFFF;
    --cm-card-header: #FAFBFC;
    --cm-input-bg: #F9FAFB;
    --cm-hover: #F3F4F6;
    --cm-shadow: rgba(0,0,0,0.06);
    --cm-shadow-md: rgba(0,0,0,0.1);
}

html.dark-mode {
    --cm-bg: #0F172A;
    --cm-text: #F9FAFB;
    --cm-text-secondary: #9CA3AF;
    --cm-text-light: #6B7280;
    --cm-border: #334155;
    --cm-card-bg: #1E293B;
    --cm-card-header: #1E293B;
    --cm-input-bg: #334155;
    --cm-hover: #334155;
    --cm-shadow: rgba(0,0,0,0.3);
    --cm-shadow-md: rgba(0,0,0,0.5);
}

*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}

body {
    background: var(--cm-bg) !important;
    color: var(--cm-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--cm-bg) !important; overflow-x: hidden !important; }
.main-content { background: var(--cm-bg) !important; overflow-x: hidden !important; max-width: 100% !important; padding: 16px 20px !important; }

/* ============================================================
   BRANCH FILTER CARD
   ============================================================ */
.branch-filter-card {
    border-radius: 12px;
    padding: 16px 22px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    color: #FFFFFF;
    position: relative;
    z-index: 5000;
    overflow: visible;
}
.branch-filter-card::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    pointer-events: none;
    z-index: 0;
    clip-path: inset(0);
}
.branch-filter-card.filter-all {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
}
.branch-filter-card.filter-active {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.filter-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    flex-wrap: wrap;
    position: relative;
    z-index: 2;
}
.filter-left > i { font-size: 20px; opacity: 0.9; color: #FCD34D; }
.filter-label { font-weight: 500; opacity: 0.8; }
.filter-name { font-weight: 800; font-size: 17px; }
.filter-code {
    font-size: 12px;
    font-weight: 700;
    opacity: 0.9;
    padding: 3px 12px;
    background: rgba(255,255,255,0.18);
    border-radius: 10px;
    font-family: 'Courier New', monospace;
}
.filter-clear {
    margin-left: 8px;
    color: #FFFFFF;
    text-decoration: none;
    font-size: 12px;
    padding: 5px 14px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}
.filter-clear:hover { background: rgba(255,255,255,0.25); color: #FFFFFF; transform: translateY(-1px); }
.filter-right { 
    display: flex; 
    gap: 8px; 
    flex-wrap: wrap; 
    position: relative; 
    z-index: 3; 
}

/* ============================================================
   PAGE HEADER
   ============================================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--cm-text);
    margin: 0;
}
.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--cm-text-secondary);
    margin: 4px 0 0 0;
}

/* ============================================================
   CURRENT CAPITAL WRAPPER
   ============================================================ */
.current-capital-wrapper {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 50%, #2563EB 100%);
    border-radius: 16px;
    padding: 0;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(30, 64, 175, 0.35);
    position: relative;
    z-index: 1;
}
.current-capital-wrapper::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 400px; height: 400px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    pointer-events: none;
}
.current-capital-header {
    padding: 18px 24px;
    background: rgba(255,255,255,0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.cch-left { display: flex; align-items: center; gap: 14px; }
.cch-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
}
.cch-info { display: flex; flex-direction: column; gap: 3px; }
.cch-title {
    font-size: 16px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.cch-subtitle {
    font-size: 12px;
    font-weight: 500;
    color: rgba(255,255,255,0.75);
}
.cch-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.current-capital-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 20px 24px;
    position: relative;
    z-index: 1;
}

.current-card {
    background: rgba(255,255,255,0.12);
    border-radius: 14px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1.5px solid rgba(255,255,255,0.18);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.current-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 5px; height: 100%;
}
.current-card:hover {
    background: rgba(255,255,255,0.2);
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}
.card-float::before { background: #93C5FD; }
.card-cash::before { background: #86EFAC; }
.card-capital::before { background: #FCD34D; }

.cc-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.cc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.cc-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.cc-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }

.cc-content { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.cc-label {
    font-size: 11px;
    font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase;
    letter-spacing: 1.2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cc-value {
    font-size: clamp(18px, 1.6vw, 24px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.cc-value-blue { color: #93C5FD !important; }
.cc-value-green { color: #86EFAC !important; }
.cc-value-purple { color: #FCD34D !important; }
.cc-sub {
    font-size: 10px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.65);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* ============================================================
   PERIOD SUMMARY WRAPPER
   ============================================================ */
.period-summary-wrapper {
    background: linear-gradient(135deg, #7C3AED 0%, #5B21B6 100%);
    border-radius: 16px;
    padding: 0;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(124, 58, 237, 0.35);
    position: relative;
    z-index: 1;
}
.period-summary-wrapper::before {
    content: '';
    position: absolute;
    top: -50%; right: -10%;
    width: 400px; height: 400px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    pointer-events: none;
}
.period-header {
    padding: 18px 24px;
    background: rgba(255,255,255,0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
.ph-left { display: flex; align-items: center; gap: 14px; }
.ph-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(255,255,255,0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.3);
}
.ph-info { display: flex; flex-direction: column; gap: 3px; }
.ph-title {
    font-size: 16px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.ph-subtitle {
    font-size: 12px;
    font-weight: 500;
    color: rgba(255,255,255,0.75);
}
.ph-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.period-cards-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 14px;
    padding: 20px 24px;
    position: relative;
    z-index: 1;
}

.period-card {
    background: rgba(255,255,255,0.1);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1.5px solid rgba(255,255,255,0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    min-width: 0;
    position: relative;
    overflow: hidden;
}
.period-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}
.period-card:hover {
    background: rgba(255,255,255,0.18);
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}
.card-total-in::before { background: #86EFAC; }
.card-total-out::before { background: #FCA5A5; }
.card-net::before { background: #93C5FD; }
.card-float-in::before { background: #C4B5FD; }
.card-cash-in::before { background: #5EEAD4; }
.card-branches::before { background: #FCD34D; }

.pc-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}
.pc-icon-green { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }
.pc-icon-red { background: linear-gradient(135deg, #DC2626, #B91C1C); color: #FFFFFF; }
.pc-icon-blue { background: linear-gradient(135deg, #3B82F6, #2563EB); color: #FFFFFF; }
.pc-icon-purple { background: linear-gradient(135deg, #A855F7, #7C3AED); color: #FFFFFF; }
.pc-icon-teal { background: linear-gradient(135deg, #14B8A6, #0D9488); color: #FFFFFF; }
.pc-icon-orange { background: linear-gradient(135deg, #F59E0B, #D97706); color: #FFFFFF; }

.pc-content {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    flex: 1;
}
.pc-label {
    font-size: 10px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pc-value {
    font-size: 16px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pc-value-green { color: #86EFAC !important; }
.pc-value-red { color: #FCA5A5 !important; }
.pc-value-purple { color: #C4B5FD !important; }
.pc-value-teal { color: #5EEAD4 !important; }
.pc-value-orange { color: #FCD34D !important; }
.pc-sub {
    font-size: 9px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.6);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ============================================================
   FILTERS BAR
   ============================================================ */
.filters-bar {
    background: var(--cm-card-bg);
    padding: 16px 20px;
    border-radius: 10px;
    border: 1px solid var(--cm-border);
    margin-bottom: 20px;
    box-shadow: 0 1px 3px var(--cm-shadow);
    position: relative;
    z-index: 1;
}
.filters-form {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label {
    font-size: 11px;
    font-weight: 600;
    color: var(--cm-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.filter-group.filter-buttons { flex-direction: row; gap: 8px; }

.form-control {
    padding: 8px 12px;
    border: 1.5px solid var(--cm-border);
    border-radius: 6px;
    font-size: 13px;
    color: var(--cm-text);
    background: var(--cm-input-bg);
    transition: all 0.3s ease;
    min-width: 150px;
    font-family: 'Inter', sans-serif;
}
.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn {
    padding: 8px 18px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}
.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); color: white; }
.btn-info { background: #3B82F6; color: white; }
.btn-info:hover { background: #2563EB; transform: translateY(-1px); color: white; }
.btn-export { background: #10B981; color: white; }
.btn-export:hover { background: #059669; transform: translateY(-1px); color: white; }
.btn-filter { background: #bb0404; color: white; }
.btn-filter:hover { background: #8a0303; color: white; }
.btn-reset {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-reset:hover { background: var(--cm-border); color: var(--cm-text); }
.btn-secondary {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-secondary:hover { background: var(--cm-border); color: var(--cm-text); }

/* ============================================================
   EXPORT DROPDOWN
   ============================================================ */
.dropdown { position: relative; display: inline-block; z-index: 10; }
.export-dropdown { position: relative; z-index: 100; }

.dropdown-menu {
    display: none;
    position: fixed;
    background: var(--cm-card-bg);
    min-width: 200px;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0, 0, 0, 0.05);
    border: 1px solid var(--cm-border);
    z-index: 2147483647;
    overflow: hidden;
    padding: 6px 0;
    animation: dropdownFadeIn 0.18s ease;
}
.dropdown-menu.show { display: block; }
@keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    text-decoration: none;
    color: var(--cm-text);
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
    z-index: 1;
}
.dropdown-menu a:hover { background: var(--cm-hover); padding-left: 20px; }
.dropdown-menu a i { width: 18px; font-size: 15px; color: #DC2626; }

/* ============================================================
   TABLE
   ============================================================ */
.table-container-main {
    background: var(--cm-card-bg);
    border-radius: 14px;
    border: 1.5px solid var(--cm-border);
    overflow: hidden;
    box-shadow: 0 4px 16px var(--cm-shadow);
    width: 100%;
    max-width: 100%;
    position: relative;
    z-index: 1;
}

.table-header-red {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.table-header-red::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.thr-left {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.thr-left > i {
    font-size: 20px;
    color: #FCD34D;
    background: rgba(255, 255, 255, 0.15);
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    flex-shrink: 0;
}
.thr-left h3 {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.thr-count {
    font-size: 11px;
    font-weight: 800;
    color: #FCD34D;
    padding: 4px 14px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    white-space: nowrap;
}

.thr-center {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.scroll-btn-header {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    border: 2px solid #FFFFFF;
    background: #FFFFFF;
    color: #DC2626;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 800;
    transition: all 0.25s ease;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn-header:hover {
    background: #FCD34D;
    color: #78350F;
    border-color: #FCD34D;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(252, 211, 77, 0.6);
}
.scroll-btn-header i { font-size: 15px; display: block; line-height: 1; }

.search-wrapper-main {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 10px;
    padding: 7px 14px;
    width: 300px;
    max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.search-wrapper-main:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.search-wrapper-main > i { color: #DC2626; font-size: 13px; flex-shrink: 0; }
.search-wrapper-main input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 12px;
    color: #1F2937;
    outline: none;
    min-width: 0;
    font-family: 'Inter', sans-serif;
}
.search-wrapper-main input::placeholder { color: #9CA3AF; font-size: 11px; }
.search-wrapper-main button {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.search-wrapper-main button:hover { background: #DC2626; color: #FFFFFF; }
.search-count-main {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 10px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    flex-shrink: 0;
    white-space: nowrap;
}

.thr-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    position: relative;
    z-index: 1;
}
.thr-branches-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 7px 16px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
}
.thr-branches-badge i { font-size: 11px; color: #FCD34D; }

.table-responsive-main {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
    scroll-behavior: smooth;
}
.table-responsive-main::-webkit-scrollbar { height: 8px; }
.table-responsive-main::-webkit-scrollbar-track { background: var(--cm-hover); border-radius: 4px; }
.table-responsive-main::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }
.table-responsive-main::-webkit-scrollbar-thumb:hover { background: #B91C1C; }

.data-table-main {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 1200px;
}
.data-table-main thead { background: #DC2626; position: sticky; top: 0; z-index: 5; }
.data-table-main thead th {
    padding: 13px 16px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.8px;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}
.data-table-main thead th.text-right { text-align: right; }
.data-table-main thead th.text-center { text-align: center; }
.data-table-main tbody tr {
    border-bottom: 1px solid var(--cm-border);
    transition: background 0.2s ease;
}
.data-table-main tbody tr:hover { background: var(--cm-hover); }
.data-table-main tbody td {
    padding: 13px 16px;
    color: var(--cm-text);
    vertical-align: middle;
}
.data-table-main tbody td.text-right { text-align: right; }
.data-table-main tbody td.text-center { text-align: center; }

/* BRANCH SEPARATOR */
.branch-separator-row { background: transparent !important; border: none !important; height: 0; }
.branch-separator-row td { padding: 0 !important; border: none !important; height: 0; background: transparent !important; }
.branch-separator-line {
    height: 5px;
    background: linear-gradient(90deg, #DC2626 0%, #B91C1C 50%, #DC2626 100%);
    box-shadow: 0 2px 12px rgba(220, 38, 38, 0.5);
    border-radius: 3px;
    margin: 10px 0;
    position: relative;
}

.transaction-row.hidden-by-search { display: none !important; }
.branch-separator-row.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--cm-hover);
    font-size: 12px;
    font-weight: 800;
    color: var(--cm-text);
    border: 1.5px solid var(--cm-border);
    font-family: 'Inter', monospace;
}

.branch-cell-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1E40AF;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    border: 1.5px solid #93C5FD;
}
.branch-cell-badge i { color: #2563EB; font-size: 10px; }
html.dark-mode .branch-cell-badge {
    background: linear-gradient(135deg, #1E3A5F, #1E40AF);
    color: #93C5FD;
    border-color: #3B82F6;
}
html.dark-mode .branch-cell-badge i { color: #60A5FA; }

.capital-link {
    font-weight: 700;
    color: #3B82F6;
    text-decoration: none;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    transition: color 0.2s ease;
    white-space: nowrap;
}
.capital-link:hover { color: #2563EB; text-decoration: underline; }

.date-display { font-size: 12px; font-weight: 600; color: var(--cm-text-secondary); white-space: nowrap; }

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}
.type-badge.type-blue { background: #DBEAFE; color: #1D4ED8; }
.type-badge.type-green { background: #D1FAE5; color: #065F46; }
.type-badge.type-purple { background: #EDE9FE; color: #6D28D9; }
.type-badge.type-red { background: #FEE2E2; color: #991B1B; }
.type-badge.type-orange { background: #FEF3C7; color: #92400E; }
.type-badge.type-gray { background: #F3F4F6; color: #6B7280; }
html.dark-mode .type-badge.type-blue { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .type-badge.type-green { background: #065F46; color: #34D399; }
html.dark-mode .type-badge.type-purple { background: #2D1B5F; color: #A78BFA; }
html.dark-mode .type-badge.type-red { background: #7F1D1D; color: #FCA5A5; }
html.dark-mode .type-badge.type-orange { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .type-badge.type-gray { background: #374151; color: #9CA3AF; }

.source-display { display: flex; align-items: center; gap: 10px; }
.source-icon-sm {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 13px;
    flex-shrink: 0;
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}
.source-cash .source-icon-sm { background: linear-gradient(135deg, #10B981, #059669); }
.source-info-sm { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.source-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--cm-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 130px;
}
.source-code {
    font-size: 9px;
    font-weight: 600;
    color: var(--cm-text-light);
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-family: 'Courier New', monospace;
}

.employee-display { font-size: 12px; font-weight: 600; color: var(--cm-text-secondary); white-space: nowrap; }

.amount-display {
    font-size: 14px;
    font-weight: 900;
    white-space: nowrap;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.2px;
}
.text-success { color: #10B981; }
.text-danger { color: #DC2626; }

/* ============================================================
   ACTION BUTTONS
   ============================================================ */
.action-buttons {
    display: flex;
    gap: 6px;
    justify-content: center;
    align-items: center;
}
.btn-action {
    width: 36px;
    height: 36px;
    border-radius: 9px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    text-decoration: none;
    font-size: 14px;
    padding: 0;
}
.btn-view { 
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE); 
    color: #1D4ED8; 
    border: 1.5px solid #93C5FD;
}
.btn-view:hover { 
    background: linear-gradient(135deg, #1D4ED8, #2563EB); 
    color: #FFFFFF; 
    transform: translateY(-3px) scale(1.05); 
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.45);
}
.btn-edit { 
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0); 
    color: #059669; 
    border: 1.5px solid #6EE7B7;
}
.btn-edit:hover { 
    background: linear-gradient(135deg, #059669, #10B981); 
    color: #FFFFFF; 
    transform: translateY(-3px) scale(1.05); 
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.45);
}
.btn-delete { 
    background: linear-gradient(135deg, #FEE2E2, #FECACA); 
    color: #DC2626; 
    border: 1.5px solid #FCA5A5;
}
.btn-delete:hover { 
    background: linear-gradient(135deg, #DC2626, #B91C1C); 
    color: #FFFFFF; 
    transform: translateY(-3px) scale(1.05); 
    box-shadow: 0 6px 16px rgba(220, 38, 38, 0.45);
}
html.dark-mode .btn-view { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .btn-edit { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }
html.dark-mode .btn-delete { background: linear-gradient(135deg, #7F1D1D, #991B1B); color: #FCA5A5; border-color: #DC2626; }

/* ============================================================
   DELETE MODAL
   ============================================================ */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(6px);
    z-index: 99999;
    justify-content: center;
    align-items: center;
    padding: 20px;
}
.modal-overlay.show {
    display: flex;
    animation: fadeIn 0.2s ease;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes slideUpModal {
    from { opacity: 0; transform: translateY(30px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-box {
    background: var(--cm-card-bg);
    border-radius: 16px;
    width: 100%;
    max-width: 480px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: slideUpModal 0.3s ease;
    border: 1px solid var(--cm-border);
}
.modal-header-danger {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    padding: 20px 24px;
    display: flex;
    align-items: center;
    gap: 14px;
    color: #FFFFFF;
}
.modal-icon-danger {
    width: 52px;
    height: 52px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: #FCD34D;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
}
.modal-header-content { flex: 1; min-width: 0; }
.modal-header-content h3 {
    font-size: 18px;
    font-weight: 800;
    margin: 0 0 3px 0;
    color: #FFFFFF;
}
.modal-header-content p {
    font-size: 12px;
    margin: 0;
    color: rgba(255, 255, 255, 0.85);
}
.modal-close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.modal-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
}
.modal-body {
    padding: 24px;
    text-align: center;
}
.modal-text {
    font-size: 14px;
    color: var(--cm-text-secondary);
    margin: 0 0 14px 0;
    font-weight: 500;
}
.modal-capital-number {
    font-family: 'Courier New', monospace;
    font-size: 20px;
    font-weight: 900;
    color: #DC2626;
    padding: 14px 24px;
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    border-radius: 10px;
    border: 2px solid #FCA5A5;
    display: inline-block;
    letter-spacing: 1px;
    margin-bottom: 14px;
}
html.dark-mode .modal-capital-number {
    background: linear-gradient(135deg, #7F1D1D, #991B1B);
    color: #FCA5A5;
    border-color: #DC2626;
}
.modal-warning {
    font-size: 12px;
    color: var(--cm-text-light);
    margin: 0;
    font-style: italic;
    line-height: 1.5;
}
.modal-warning strong { color: #DC2626; }
.modal-footer {
    display: flex;
    gap: 10px;
    padding: 18px 24px;
    background: var(--cm-hover);
    border-top: 1px solid var(--cm-border);
    justify-content: flex-end;
}
.btn-cancel, .btn-confirm-delete {
    padding: 11px 22px;
    border-radius: 9px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
    border: none;
    white-space: nowrap;
}
.btn-cancel {
    background: var(--cm-card-bg);
    color: var(--cm-text-secondary);
    border: 1.5px solid var(--cm-border);
}
.btn-cancel:hover {
    background: var(--cm-border);
    color: var(--cm-text);
}
.btn-confirm-delete {
    background: linear-gradient(135deg, #DC2626, #B91C1C);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.35);
}
.btn-confirm-delete:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 38, 38, 0.5);
    color: #FFFFFF;
}
.btn-confirm-delete:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* ============================================================
   NO RESULTS / EMPTY
   ============================================================ */
.no-results-main {
    text-align: center;
    padding: 60px 20px;
    background: var(--cm-hover);
}
.no-results-main i {
    font-size: 56px;
    color: var(--cm-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.no-results-main h3 {
    font-size: 18px;
    color: var(--cm-text);
    margin: 0 0 8px 0;
}
.no-results-main p {
    font-size: 14px;
    color: var(--cm-text-secondary);
    margin: 0 0 20px 0;
}

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: var(--cm-card-bg);
    border-radius: 12px;
    border: 1px solid var(--cm-border);
}
.empty-state i {
    font-size: 60px;
    color: var(--cm-text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 20px;
    color: var(--cm-text);
    margin: 0 0 8px 0;
}
.empty-state p {
    color: var(--cm-text-secondary);
    font-size: 14px;
    margin: 0 0 20px 0;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1400px) {
    .period-cards-grid { grid-template-columns: repeat(3, 1fr); }
    .current-capital-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 1024px) {
    .current-capital-grid { grid-template-columns: repeat(3, 1fr); }
    .period-cards-grid { grid-template-columns: repeat(3, 1fr); }
    .cc-value { font-size: 18px; }
    .pc-value { font-size: 14px; }
    .table-header-red {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .thr-left, .thr-center, .thr-right {
        justify-content: center;
        width: 100%;
    }
    .search-wrapper-main { width: 100%; }
    .scroll-btn-header { width: 40px; height: 40px; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-filter-card { flex-direction: column; align-items: flex-start; padding: 14px 18px; }
    .filter-right { width: 100%; }
    .filter-right .btn { flex: 1; justify-content: center; }
    
    .current-capital-grid { grid-template-columns: 1fr; }
    .period-cards-grid { grid-template-columns: repeat(2, 1fr); }
    .current-capital-header { flex-direction: column; align-items: flex-start; }
    .cch-badge { align-self: flex-start; }
    .period-header { flex-direction: column; align-items: flex-start; }
    .ph-badge { align-self: flex-start; }
    
    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .filter-group.filter-buttons { flex-direction: column; }
    .filter-group.filter-buttons .btn { width: 100%; justify-content: center; }
    
    .table-header-red { padding: 14px 16px; }
    .thr-left h3 { font-size: 14px; }
    .scroll-btn-header { width: 38px; height: 38px; font-size: 14px; }
    .scroll-btn-header i { font-size: 13px; }
}
@media (max-width: 480px) {
    .current-capital-grid { grid-template-columns: 1fr; }
    .period-cards-grid { grid-template-columns: 1fr; }
    .cc-value { font-size: 18px; }
    .pc-value { font-size: 15px; }
    .current-card { padding: 16px 18px; }
    .period-card { padding: 14px 16px; }
    .cc-icon { width: 48px; height: 48px; font-size: 20px; }
    .pc-icon { width: 40px; height: 40px; font-size: 16px; }
    
    .data-table-main thead th,
    .data-table-main tbody td { padding: 10px 12px; font-size: 12px; }
    .branch-cell-badge { font-size: 10px; padding: 4px 8px; }
    .source-name { max-width: 90px; font-size: 11px; }
    .amount-display { font-size: 12px; }
    
    .thr-center { gap: 8px; }
    .scroll-btn-header { width: 34px; height: 34px; font-size: 13px; }
    .scroll-btn-header i { font-size: 12px; }
    
    .btn-action { width: 32px; height: 32px; font-size: 12px; }
}
</style>

<script>
// ============================================================
// DELETE MODAL
// ============================================================
var deleteId = 0;

function confirmDelete(id, capitalNumber) {
    deleteId = id;
    var modal = document.getElementById('deleteModal');
    var capNumEl = document.getElementById('deleteCapitalNumber');
    
    if (capNumEl) capNumEl.textContent = capitalNumber;
    if (modal) modal.classList.add('show');
    
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    var modal = document.getElementById('deleteModal');
    if (modal) modal.classList.remove('show');
    document.body.style.overflow = '';
    deleteId = 0;
}

async function executeDelete() {
    if (deleteId <= 0) {
        showAlert('error', 'Invalid ID');
        return;
    }
    
    var btn = document.getElementById('confirmDeleteBtn');
    var originalHtml = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    
    try {
        var formData = new FormData();
        formData.append('ajax_action', 'delete_capital');
        formData.append('id', deleteId);
        
        console.log('Deleting ID:', deleteId);
        
        var response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        
        var text = await response.text();
        console.log('Response:', text);
        
        var data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            showAlert('error', 'Server returned invalid response');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            return;
        }
        
        if (data.success) {
            // Ficha row kwa animation
            var row = document.querySelector('.transaction-row[data-id="' + deleteId + '"]');
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
            }
            
            closeDeleteModal();
            showAlert('success', data.message);
            
            // ✅ RELOAD PAGE BAADA YA 1 SECOND
            // Hii inasasisha:
            // - Current Capital cards (Float, Cash, Capital) ← kutoka daily_reports
            // - Period Summary (Total In, Total Out, Net)
            // - Table (record imeondolewa)
            setTimeout(function() {
                window.location.reload();
            }, 1200);
        } else {
            showAlert('error', data.message || 'Failed to delete');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (err) {
        console.error('Delete error:', err);
        showAlert('error', 'Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// ============================================================
// SHOW ALERT
// ============================================================
function showAlert(type, message) {
    var alertDiv = document.createElement('div');
    alertDiv.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
    alertDiv.style.cssText = 
        'position: fixed;' +
        'top: 80px;' +
        'right: 20px;' +
        'padding: 14px 22px;' +
        'border-radius: 10px;' +
        'font-size: 14px;' +
        'font-weight: 600;' +
        'z-index: 999999;' +
        'box-shadow: 0 8px 24px rgba(0,0,0,0.2);' +
        'display: flex;' +
        'align-items: center;' +
        'gap: 10px;' +
        'max-width: 400px;' +
        (type === 'success' 
            ? 'background: linear-gradient(135deg, #059669, #10B981); color: #FFFFFF;'
            : 'background: linear-gradient(135deg, #DC2626, #B91C1C); color: #FFFFFF;');
    
    document.body.appendChild(alertDiv);
    
    setTimeout(function() {
        alertDiv.style.transition = 'all 0.3s ease';
        alertDiv.style.opacity = '0';
        alertDiv.style.transform = 'translateX(100px)';
        setTimeout(function() {
            if (alertDiv.parentElement) alertDiv.remove();
        }, 300);
    }, 4000);
}

// ============================================================
// EXPORT DROPDOWN
// ============================================================
function toggleExportDropdown(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    var menu = document.getElementById('exportMenu');
    var btn = document.getElementById('exportToggleBtn');
    if (!menu || !btn) return;
    
    document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
        if (m !== menu) m.classList.remove('show');
    });
    
    var isOpen = menu.classList.contains('show');
    
    if (isOpen) {
        menu.classList.remove('show');
        return;
    }
    
    var rect = btn.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = (rect.bottom + 6) + 'px';
    menu.style.right = (window.innerWidth - rect.right) + 'px';
    menu.style.left = 'auto';
    menu.style.bottom = 'auto';
    menu.style.zIndex = '2147483647';
    
    menu.classList.add('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-dropdown')) {
        var menu = document.getElementById('exportMenu');
        if (menu) menu.classList.remove('show');
    }
});

function exportData(format) {
    var menu = document.getElementById('exportMenu');
    if (menu) menu.classList.remove('show');
    var params = new URLSearchParams(window.location.search);
    window.location.href = 'export.php?format=' + format + '&' + params.toString();
}

// ============================================================
// SCROLL TABLE
// ============================================================
function scrollTableMain(direction) {
    var wrapper = document.getElementById('tableWrapperMain');
    if (!wrapper) return;
    var scrollAmount = 400;
    wrapper.scrollBy({
        left: direction === 'left' ? -scrollAmount : scrollAmount,
        behavior: 'smooth'
    });
}

// ============================================================
// GLOBAL SEARCH
// ============================================================
function onGlobalSearch(input) {
    var searchTerm = input.value.toLowerCase().trim();
    var rows = document.querySelectorAll('.transaction-row');
    var separatorRows = document.querySelectorAll('.branch-separator-row');
    var clearBtn = document.getElementById('globalSearchClear');
    var countBadge = document.getElementById('globalSearchCount');
    var noResults = document.getElementById('noResultsMain');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        rows.forEach(function(row) { row.classList.remove('hidden-by-search'); });
        separatorRows.forEach(function(row) { row.classList.remove('hidden-by-search'); });
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        
        var idx = 1;
        rows.forEach(function(row) {
            var numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = idx++;
        });
        return;
    }
    
    var matchCount = 0;
    rows.forEach(function(row) {
        var searchData = row.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    separatorRows.forEach(function(row) { row.classList.add('hidden-by-search'); });
    
    var visibleIdx = 1;
    rows.forEach(function(row) {
        if (!row.classList.contains('hidden-by-search')) {
            var numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = visibleIdx++;
        }
    });
    
    if (countBadge) {
        countBadge.style.display = 'inline-block';
        countBadge.textContent = matchCount;
    }
    
    if (noResults) {
        noResults.style.display = matchCount === 0 ? 'block' : 'none';
    }
}

function clearGlobalSearch() {
    var input = document.getElementById('globalSearchInput');
    if (input) {
        input.value = '';
        onGlobalSearch(input);
        input.focus();
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var modal = document.getElementById('deleteModal');
        if (modal && modal.classList.contains('show')) {
            closeDeleteModal();
            return;
        }
        var menu = document.getElementById('exportMenu');
        if (menu && menu.classList.contains('show')) {
            menu.classList.remove('show');
        }
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        var input = document.getElementById('globalSearchInput');
        if (input) { input.focus(); input.select(); }
    }
});

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
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