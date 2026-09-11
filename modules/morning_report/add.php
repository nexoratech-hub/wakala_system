<?php
// ================================================================
// FILE: modules/morning_report/add.php
// WAKALA FINANCIAL SYSTEM - ADD MORNING REPORT
// WITH BRANCH PROVIDERS FILTER
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
// GET USER DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// ============================================================
// GET BRANCHES FOR DROPDOWN
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name");
$stmt->execute();
$branches = $stmt->fetchAll();

// ============================================================
// BRANCH FILTER HANDLING
// ============================================================
$selected_branch = isset($_GET['branch']) ? intval($_GET['branch']) : 0;

if ($selected_branch == 0 && isset($_SESSION['selected_branch'])) {
    $selected_branch = intval($_SESSION['selected_branch']);
}

// If employee, force their branch
if ($role == 'employee' && $selected_branch == 0) {
    $selected_branch = $user['branch_id'] ?? 0;
}

$selected_branch = $selected_branch ?? 0;

if ($selected_branch > 0) {
    $_SESSION['selected_branch'] = $selected_branch;
}

// Get branch name for display
$branch_name = 'All Branches';
$branch_code = '';
$branch_location = '';
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $branch_name = $b['branch_name'];
            $branch_code = $b['branch_code'] ?? '';
            $branch_location = $b['location'] ?? '';
            break;
        }
    }
}

// ============================================================
// GET PROVIDERS FOR BRANCH
// ============================================================
$branch_providers = [];

try {
    $check_table = $db->query("SHOW TABLES LIKE 'branch_providers'");
    $table_exists = $check_table->rowCount() > 0;
    
    if ($table_exists && $selected_branch > 0) {
        // Get providers specific to this branch
        $stmt = $db->prepare("SELECT p.*, bp.provider_code as branch_provider_code 
                              FROM providers p 
                              JOIN branch_providers bp ON p.id = bp.provider_id 
                              WHERE bp.branch_id = ? AND p.is_active = 1 
                              ORDER BY p.display_order, p.provider_name");
        $stmt->execute([$selected_branch]);
        $branch_providers = $stmt->fetchAll();
    } else {
        // Get all providers if no branch selected
        $stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order, provider_name");
        $stmt->execute();
        $branch_providers = $stmt->fetchAll();
    }
} catch (Exception $e) {
    // Fallback to all providers
    $stmt = $db->prepare("SELECT * FROM providers WHERE is_active = 1 ORDER BY display_order, provider_name");
    $stmt->execute();
    $branch_providers = $stmt->fetchAll();
}

// ============================================================
// AUTO-FILL LOGIC - FIND EVENING STOCK
// ============================================================
$auto_fill_data = null;
$auto_fill_source = null;
$auto_fill_date = null;
$has_auto_fill = false;
$auto_fill_error = null;

// Get today's date
$today = date('Y-m-d');

// Build query to find recent evening stock for this branch
$evening_stock_sql = "SELECT 
                        es.*,
                        e.full_name as employee_name,
                        b.branch_name as branch_name
                      FROM evening_stocks es
                      LEFT JOIN employees e ON es.employee_id = e.id
                      LEFT JOIN branches b ON es.branch_id = b.id
                      WHERE 1=1";

$params = [];

// Branch filter - must match selected branch
if ($selected_branch > 0) {
    $evening_stock_sql .= " AND es.branch_id = ?";
    $params[] = $selected_branch;
} else {
    // If no branch selected, try to get user's branch
    $user_branch_id = $_SESSION['user_branch_id'] ?? 0;
    if ($user_branch_id > 0) {
        $evening_stock_sql .= " AND es.branch_id = ?";
        $params[] = $user_branch_id;
    }
}

// Check for evening stock in the last 30 days
$thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
$evening_stock_sql .= " AND es.stock_date >= ?";
$params[] = $thirty_days_ago;

// Check if morning report already exists for this date and branch
$evening_stock_sql .= " AND NOT EXISTS (
                          SELECT 1 FROM morning_reports mr 
                          WHERE mr.report_date = es.stock_date 
                          AND mr.branch_id = es.branch_id
                          AND DATE(mr.report_date) = DATE(es.stock_date)
                      )";

// Order by date descending (most recent first)
$evening_stock_sql .= " ORDER BY es.stock_date DESC, es.id DESC LIMIT 1";

// Execute query
try {
    $stmt = $db->prepare($evening_stock_sql);
    $stmt->execute($params);
    $auto_fill_source = $stmt->fetch();
    
    if ($auto_fill_source) {
        $has_auto_fill = true;
        $auto_fill_date = $auto_fill_source['stock_date'];
        
        // Decode provider data from evening stock
        $auto_fill_data = json_decode($auto_fill_source['provider_data'] ?? '{}', true);
        
        // Also get the cash balance from evening stock
        $auto_fill_cash = $auto_fill_source['cash_balance'] ?? 0;
        
        // Check if there's any morning report already for this date
        $check_stmt = $db->prepare("SELECT id, report_number FROM morning_reports 
                                    WHERE report_date = ? AND branch_id = ? 
                                    AND employee_id = ?");
        $check_stmt->execute([$auto_fill_date, $auto_fill_source['branch_id'], $user_id]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            $auto_fill_error = 'Morning report already exists for this date. Report: ' . $existing['report_number'];
            $has_auto_fill = false;
        }
    }
} catch (Exception $e) {
    // If query fails, we'll just use manual mode
    $auto_fill_error = 'Error checking evening stock: ' . $e->getMessage();
    $has_auto_fill = false;
}

// If no auto-fill found, check if we need to allow manual entry
$allow_manual = !$has_auto_fill;

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
$success_message = '';
$error_message = '';
$show_success = false;
$show_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_morning_report') {
    try {
        // Get form data
        $report_date = $_POST['report_date'] ?? date('Y-m-d');
        $branch_id = intval($_POST['branch_id'] ?? 0);
        $cash_balance = floatval(str_replace(',', '', $_POST['cash_balance'] ?? 0));
        $notes = $_POST['notes'] ?? '';
        $source_type = $_POST['source_type'] ?? 'manual';
        $source_evening_id = isset($_POST['source_evening_id']) ? intval($_POST['source_evening_id']) : null;
        
        // Validate
        if ($branch_id <= 0) {
            throw new Exception('Please select a branch.');
        }
        
        // Get branch name
        $branch_name_db = '';
        foreach ($branches as $b) {
            if ($b['id'] == $branch_id) {
                $branch_name_db = $b['branch_name'];
                break;
            }
        }
        
        // Build provider data from POST
        $provider_data = [];
        $total_float = 0;
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'provider_') === 0 && !empty($value)) {
                $provider_id = str_replace('provider_', '', $key);
                $amount = floatval(str_replace(',', '', $value));
                if ($amount > 0) {
                    $provider_data[$provider_id] = $amount;
                    $total_float += $amount;
                }
            }
        }
        
        if (empty($provider_data)) {
            throw new Exception('Please enter at least one provider amount.');
        }
        
        // Check if report exists for this branch on this date
        $check_stmt = $db->prepare("SELECT COUNT(*) FROM morning_reports 
                                    WHERE report_date = ? AND employee_id = ? AND branch_id = ?");
        $check_stmt->execute([$report_date, $user_id, $branch_id]);
        $exists = $check_stmt->fetchColumn();
        
        if ($exists > 0) {
            throw new Exception('A morning report already exists for this branch on this date.');
        }
        
        // Generate report number
        $report_number = 'MR-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert data
        $provider_json = json_encode($provider_data);
        
        $insert_stmt = $db->prepare("INSERT INTO morning_reports 
            (report_number, employee_id, branch, branch_id, report_date, provider_data, 
             cash_balance, cumm_total, notes, source_type, source_evening_stock_id, is_locked) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $is_locked = ($source_type === 'auto_from_evening') ? 1 : 0;
        
        $insert_stmt->execute([
            $report_number,
            $user_id,
            $branch_name_db,
            $branch_id,
            $report_date,
            $provider_json,
            $cash_balance,
            $total_float,
            $notes,
            $source_type,
            $source_evening_id,
            $is_locked
        ]);
        
        $report_id = $db->lastInsertId();
        
        // ============================================================
        // SAVE TO morning_report_providers TABLE
        // ============================================================
        foreach ($provider_data as $provider_id => $amount) {
            try {
                // Get provider code
                $prov_stmt = $db->prepare("SELECT provider_code FROM providers WHERE id = ?");
                $prov_stmt->execute([$provider_id]);
                $prov = $prov_stmt->fetch();
                $provider_code = $prov['provider_code'] ?? 'N/A';
                
                $insert_prov_stmt = $db->prepare("INSERT INTO morning_report_providers 
                    (report_id, provider_id, provider_code, provider_name, float_balance, cash_balance) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                
                // Get provider name
                $name_stmt = $db->prepare("SELECT provider_name FROM providers WHERE id = ?");
                $name_stmt->execute([$provider_id]);
                $name = $name_stmt->fetch();
                $provider_name = $name['provider_name'] ?? 'Unknown';
                
                $insert_prov_stmt->execute([
                    $report_id,
                    $provider_id,
                    $provider_code,
                    $provider_name,
                    $amount,
                    0 // cash_balance for each provider
                ]);
            } catch (Exception $e) {
                // If morning_report_providers table doesn't exist, ignore
            }
        }
        
        // Log activity
        try {
            $stmt = $db->prepare("INSERT INTO activity_logs (employee_id, action, module, record_id, new_value, branch_id) 
                                  VALUES (?, 'Add Morning Report', 'Morning Report', ?, ?, ?)");
            $activity_msg = 'New morning report added for branch: ' . $branch_name_db;
            if ($source_type === 'auto_from_evening') {
                $activity_msg .= ' (Auto-filled from Evening Stock #' . $source_evening_id . ')';
            }
            $stmt->execute([$user_id, $report_id, $activity_msg, $branch_id]);
        } catch (Exception $e) {
            // Activity log table might not exist, ignore
        }
        
        $success_message = 'Morning report added successfully! Report Number: ' . $report_number;
        if ($source_type === 'auto_from_evening') {
            $success_message .= ' (Auto-filled from Evening Stock)';
        }
        $show_success = true;
        
        // Redirect after 2 seconds
        echo '<meta http-equiv="refresh" content="2;url=index.php">';
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $show_error = true;
    }
}

// ============================================================
// INCLUDE HEADER, SIDEBAR & TOPBAR
// ============================================================
include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<!-- ============================================================
DASHBOARD CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== BRANCH INDICATOR CARD - RED ===== -->
        <div class="branch-indicator">
            <div class="branch-indicator-left">
                <div class="branch-icon-wrapper">
                    <i class="fas fa-store-alt"></i>
                </div>
                <div class="branch-info">
                    <span class="branch-indicator-label">Current Filter</span>
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
                <?php if ($has_auto_fill): ?>
                    <div class="auto-fill-badge" style="background:rgba(255,255,255,0.15); padding:4px 14px; border-radius:16px; display:flex; align-items:center; gap:6px; font-size:12px;">
                        <i class="fas fa-sync-alt fa-spin"></i>
                        <span>Auto-filled from <?php echo date('d M Y', strtotime($auto_fill_date)); ?></span>
                    </div>
                <?php endif; ?>
                <span class="provider-count" style="background:rgba(255,255,255,0.12); padding:2px 12px; border-radius:12px; font-size:12px;">
                    <i class="fas fa-university"></i> <?php echo count($branch_providers); ?> Providers
                </span>
            </div>
            <div class="branch-indicator-right">
                <div class="branch-select-wrapper">
                    <select id="branchFilter" class="branch-filter-select" onchange="window.location.href='?branch='+this.value">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $selected_branch == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="date-display">
                    <i class="far fa-calendar-alt"></i> 
                    <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-sun"></i> Add Morning Report</h2>
                <span class="page-subtitle">
                    <?php if ($has_auto_fill): ?>
                        Auto-filled from Evening Stock (<?php echo date('d M Y', strtotime($auto_fill_date)); ?>)
                    <?php else: ?>
                        Manual Entry
                    <?php endif; ?>
                </span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <!-- ============================================================
        AUTO-FILL NOTIFICATION
        ============================================================ -->
        <?php if ($has_auto_fill && $auto_fill_source): ?>
            <div class="alert alert-auto-fill">
                <div class="alert-content">
                    <i class="fas fa-sync-alt fa-spin" style="font-size:20px; color:#F59E0B;"></i>
                    <div>
                        <strong>Auto-Fill Active</strong>
                        <p>This form has been automatically filled from the Evening Stock report 
                           of <strong><?php echo date('d M Y', strtotime($auto_fill_date)); ?></strong>.
                           The report is locked for editing.</p>
                    </div>
                    <span class="source-badge">Evening Stock #<?php echo $auto_fill_source['stock_number']; ?></span>
                </div>
            </div>
        <?php elseif ($auto_fill_error): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo htmlspecialchars($auto_fill_error); ?></span>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <span>No recent Evening Stock found. You can manually add a morning report.</span>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        SUCCESS/ERROR MESSAGES
        ============================================================ -->
        <?php if ($show_success && !empty($success_message)): ?>
            <div class="alert alert-success" id="successAlert">
                <i class="fas fa-check-circle"></i> 
                <span><?php echo $success_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if ($show_error && !empty($error_message)): ?>
            <div class="alert alert-danger" id="errorAlert">
                <i class="fas fa-exclamation-circle"></i> 
                <span><?php echo $error_message; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <!-- ============================================================
        FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="main-form" id="morningReportForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="add_morning_report">
                <input type="hidden" name="source_type" value="<?php echo $has_auto_fill ? 'auto_from_evening' : 'manual'; ?>">
                <?php if ($has_auto_fill && $auto_fill_source): ?>
                    <input type="hidden" name="source_evening_id" value="<?php echo $auto_fill_source['id']; ?>">
                <?php endif; ?>
                <?php if ($has_auto_fill): ?>
                    <input type="hidden" name="is_locked" value="1">
                <?php endif; ?>
                
                <!-- ===== BASIC INFORMATION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                        <?php if ($has_auto_fill): ?>
                            <span class="badge badge-auto" style="background:#F59E0B; color:#FFFFFF; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600;">
                                <i class="fas fa-lock"></i> Auto-Filled
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="report_date">Report Date <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" id="report_date" name="report_date" 
                                       value="<?php 
                                            if ($has_auto_fill && $auto_fill_source) {
                                                echo $auto_fill_source['stock_date'];
                                            } elseif (isset($_POST['report_date'])) {
                                                echo htmlspecialchars($_POST['report_date']);
                                            } else {
                                                echo date('Y-m-d');
                                            }
                                       ?>" 
                                       class="form-control" 
                                       <?php echo $has_auto_fill ? 'readonly style="background:var(--form-input-bg);cursor:not-allowed;opacity:0.7;"' : 'required'; ?>>
                            </div>
                            <?php if ($has_auto_fill): ?>
                                <small><i class="fas fa-info-circle"></i> Date is locked (from Evening Stock)</small>
                            <?php else: ?>
                                <small>Select the date for this report</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="branch_id">Branch <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-store-alt"></i></span>
                                <select id="branch_id" name="branch_id" class="form-control" 
                                        <?php echo $has_auto_fill ? 'disabled' : 'required'; ?>>
                                    <option value="0">Select Branch</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" 
                                            <?php 
                                                if ($has_auto_fill && $auto_fill_source) {
                                                    echo $auto_fill_source['branch_id'] == $b['id'] ? 'selected' : '';
                                                } elseif ($selected_branch == $b['id']) {
                                                    echo 'selected';
                                                } elseif ($role == 'employee' && $user['branch_id'] == $b['id']) {
                                                    echo 'selected';
                                                }
                                            ?>>
                                            <?php echo htmlspecialchars($b['branch_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($has_auto_fill): ?>
                                <input type="hidden" name="branch_id" value="<?php echo $auto_fill_source['branch_id']; ?>">
                                <small><i class="fas fa-info-circle"></i> Branch is locked (from Evening Stock)</small>
                            <?php else: ?>
                                <small>Select the branch for this report</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cash_balance">Cash Balance</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                                <input type="text" id="cash_balance" name="cash_balance" 
                                       value="<?php 
                                            if ($has_auto_fill && $auto_fill_source) {
                                                echo number_format($auto_fill_source['cash_balance'] ?? 0, 0, '.', ',');
                                            } elseif (isset($_POST['cash_balance'])) {
                                                echo htmlspecialchars($_POST['cash_balance']);
                                            } else {
                                                echo '0';
                                            }
                                       ?>" 
                                       class="form-control money-input" 
                                       placeholder="0.00"
                                       <?php echo $has_auto_fill ? 'readonly style="background:var(--form-input-bg);cursor:not-allowed;opacity:0.7;"' : ''; ?>
                                       oninput="<?php echo $has_auto_fill ? '' : 'formatMoneyInput(this); calculateTotals();'; ?>">
                            </div>
                            <?php if ($has_auto_fill): ?>
                                <small><i class="fas fa-info-circle"></i> Cash balance is locked (from Evening Stock)</small>
                            <?php else: ?>
                                <small>Physical cash balance in the till</small>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-sticky-note"></i></span>
                                <input type="text" id="notes" name="notes" 
                                       value="<?php 
                                            if ($has_auto_fill && $auto_fill_source) {
                                                echo htmlspecialchars($auto_fill_source['notes'] ?? 'Auto-filled from Evening Stock');
                                            } elseif (isset($_POST['notes'])) {
                                                echo htmlspecialchars($_POST['notes']);
                                            } else {
                                                echo '';
                                            }
                                       ?>" 
                                       class="form-control" 
                                       placeholder="Any additional notes..."
                                       <?php echo $has_auto_fill ? 'readonly style="background:var(--form-input-bg);cursor:not-allowed;opacity:0.7;"' : ''; ?>>
                            </div>
                            <small><?php echo $has_auto_fill ? 'Notes are locked (from Evening Stock)' : 'Optional notes for this report'; ?></small>
                        </div>
                    </div>
                </div>

                <!-- ===== PROVIDERS SECTION ===== -->
                <div class="form-section">
                    <div class="section-header">
                        <h3><i class="fas fa-university"></i> Provider Balances</h3>
                        <span class="section-sub">
                            <?php if ($selected_branch > 0): ?>
                                Branch: <?php echo htmlspecialchars($branch_name); ?> (<?php echo count($branch_providers); ?> providers)
                            <?php else: ?>
                                Select a branch to see providers
                            <?php endif; ?>
                            <?php echo $has_auto_fill ? ' - Auto-filled from Evening Stock (locked)' : ''; ?>
                        </span>
                    </div>
                    
                    <?php if (empty($branch_providers) && $selected_branch > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>No providers found for this branch. Please add providers to this branch first.</span>
                        </div>
                    <?php elseif (empty($branch_providers)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <span>Please select a branch to see its providers.</span>
                        </div>
                    <?php else: ?>
                        <div class="providers-grid" id="providersContainer">
                            <?php foreach ($branch_providers as $provider): 
                                $provider_key = 'provider_' . $provider['id'];
                                $provider_value = '';
                                
                                if ($has_auto_fill && $auto_fill_data && isset($auto_fill_data[$provider['id']])) {
                                    $provider_value = number_format($auto_fill_data[$provider['id']], 0, '.', ',');
                                } elseif (isset($_POST[$provider_key])) {
                                    $provider_value = htmlspecialchars($_POST[$provider_key]);
                                }
                            ?>
                                <div class="provider-item <?php echo $has_auto_fill ? 'provider-locked' : ''; ?>">
                                    <div class="provider-icon" style="background: <?php echo $provider['color_code'] ?? '#0B5ED7'; ?>;">
                                        <i class="<?php echo $provider['icon_class'] ?? 'fas fa-university'; ?>"></i>
                                    </div>
                                    <div class="provider-info">
                                        <span class="provider-name"><?php echo htmlspecialchars($provider['provider_name']); ?></span>
                                        <span class="provider-code"><?php echo htmlspecialchars($provider['branch_provider_code'] ?? $provider['provider_code']); ?></span>
                                    </div>
                                    <div class="provider-input">
                                        <input type="text" 
                                               id="provider_<?php echo $provider['id']; ?>" 
                                               name="provider_<?php echo $provider['id']; ?>" 
                                               class="form-control provider-amount money-input" 
                                               placeholder="0.00" 
                                               value="<?php echo $provider_value; ?>"
                                               data-provider-id="<?php echo $provider['id']; ?>"
                                               <?php echo $has_auto_fill ? 'readonly style="background:var(--form-input-bg);cursor:not-allowed;opacity:0.7;"' : ''; ?>
                                               oninput="<?php echo $has_auto_fill ? '' : 'formatMoneyInput(this); calculateTotals();'; ?>">
                                        <?php if ($has_auto_fill && $provider_value): ?>
                                            <span class="provider-lock-icon" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);color:#9CA3AF;">
                                                <i class="fas fa-lock" style="font-size:12px;"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="providers-summary">
                        <div class="summary-row">
                            <span class="summary-label">Total Float:</span>
                            <span class="summary-value" id="totalFloatDisplay">
                                <?php 
                                    $display_total = 0;
                                    if ($has_auto_fill && $auto_fill_data) {
                                        $display_total = array_sum($auto_fill_data);
                                    }
                                    echo 'TSh ' . number_format($display_total, 0, '.', ',');
                                ?>
                            </span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Cash Balance:</span>
                            <span class="summary-value" id="cashBalanceDisplay">
                                <?php 
                                    $display_cash = 0;
                                    if ($has_auto_fill && $auto_fill_source) {
                                        $display_cash = $auto_fill_source['cash_balance'] ?? 0;
                                    }
                                    echo 'TSh ' . number_format($display_cash, 0, '.', ',');
                                ?>
                            </span>
                        </div>
                        <div class="summary-row total">
                            <span class="summary-label">Grand Total (Float + Cash):</span>
                            <span class="summary-value" id="grandTotalDisplay">
                                <?php 
                                    $display_grand = 0;
                                    if ($has_auto_fill && $auto_fill_source) {
                                        $display_grand = ($auto_fill_source['cumm_total'] ?? 0) + ($auto_fill_source['cash_balance'] ?? 0);
                                    }
                                    echo 'TSh ' . number_format($display_grand, 0, '.', ',');
                                ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i> 
                        <?php echo $has_auto_fill ? 'Save Auto-Filled Report' : 'Save Morning Report'; ?>
                    </button>
                    <?php if (!$has_auto_fill): ?>
                        <button type="reset" class="btn btn-reset" onclick="return confirmReset()">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<!-- ============================================================
DASHBOARD STYLES
============================================================ -->
<style>
/* ============================================================
   BRANCH INDICATOR CARD - RED
   ============================================================ */
.branch-indicator {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px;
    padding: 14px 24px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 15px rgba(220, 38, 38, 0.35);
    border: none;
    position: relative;
    overflow: hidden;
}

.branch-indicator::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator::after {
    content: '';
    position: absolute;
    bottom: -60%;
    left: 30%;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 50%;
    pointer-events: none;
}

.branch-indicator-left {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 13px;
    color: #FFFFFF;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

.branch-icon-wrapper {
    width: 44px;
    height: 44px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.branch-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.branch-indicator-label {
    font-size: 11px;
    font-weight: 500;
    opacity: 0.7;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.branch-indicator-name {
    font-weight: 700;
    font-size: 16px;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}

.branch-indicator-code {
    font-size: 11px;
    font-weight: 600;
    opacity: 0.6;
    color: #FFFFFF;
    padding: 2px 10px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.branch-location {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    opacity: 0.8;
    color: #FFFFFF;
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.branch-location i { font-size: 12px; }

.provider-count {
    font-size: 12px;
    font-weight: 500;
    color: #FFFFFF;
    padding: 4px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.branch-indicator-right {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    z-index: 1;
}

.branch-select-wrapper {
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    padding: 2px 4px;
}

.branch-filter-select {
    background: transparent;
    border: none;
    color: #FFFFFF;
    padding: 6px 30px 6px 12px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    outline: none;
    font-family: 'Inter', sans-serif;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23FFFFFF' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}

.branch-filter-select option {
    background: #1F2937;
    color: #FFFFFF;
}

.date-display {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-display i {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
}

/* ============================================================
   DARK MODE VARIABLES
   ============================================================ */
:root {
    --form-bg: #FFFFFF;
    --form-text: #1F2937;
    --form-text-secondary: #6B7280;
    --form-text-light: #9CA3AF;
    --form-border: #E5E7EB;
    --form-card-bg: #FFFFFF;
    --form-card-header: #FAFBFC;
    --form-input-bg: #F9FAFB;
    --form-hover: #F3F4F6;
    --form-shadow: rgba(0,0,0,0.06);
    --form-shadow-lg: rgba(0,0,0,0.12);
    --form-dropdown-bg: #FFFFFF;
    --provider-bg: #E8F5E9;
    --provider-bg-hover: #C8E6C9;
    --provider-border: #A5D6A7;
    --provider-text: #1B5E20;
}

html.dark-mode {
    --form-bg: #0f172a;
    --form-text: #F1F5F9;
    --form-text-secondary: #94A3B8;
    --form-text-light: #64748B;
    --form-border: #334155;
    --form-card-bg: #1E293B;
    --form-card-header: #2D3A4F;
    --form-input-bg: #334155;
    --form-hover: #2D3A4F;
    --form-shadow: rgba(0,0,0,0.4);
    --form-shadow-lg: rgba(0,0,0,0.6);
    --form-dropdown-bg: #1E293B;
    --provider-bg: #1B3A1B;
    --provider-bg-hover: #2E4F2E;
    --provider-border: #2E7D32;
    --provider-text: #A5D6A7;
}

body {
    background: var(--form-bg) !important;
    color: var(--form-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--form-bg) !important; }
.main-content { background: var(--form-bg) !important; }

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
    color: var(--form-text);
    margin: 0;
}
.page-header-left h2 i { color: #F59E0B; margin-right: 8px; }
.page-subtitle {
    font-size: 13px;
    color: var(--form-text-secondary);
    background: var(--form-hover);
    padding: 3px 12px;
    border-radius: 12px;
}
.btn-back {
    background: var(--form-hover);
    color: var(--form-text-secondary);
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
    background: var(--form-border);
    color: var(--form-text);
}

/* ============================================================
   ALERTS
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
    position: relative;
    animation: slideDown 0.4s ease forwards;
}
.alert-success {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #A7F3D0;
}
.alert-danger {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}
.alert-warning {
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid #FDE68A;
}
.alert-info {
    background: #DBEAFE;
    color: #1E40AF;
    border: 1px solid #BFDBFE;
}
.alert-auto-fill {
    background: #FFFBEB;
    border: 1px solid #FDE68A;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.alert-auto-fill .alert-content {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    flex-wrap: wrap;
}
.alert-auto-fill .alert-content strong {
    color: #92400E;
    font-size: 15px;
}
.alert-auto-fill .alert-content p {
    margin: 0;
    color: #78350F;
    font-size: 13px;
}
.alert-auto-fill .source-badge {
    background: #F59E0B;
    color: #FFFFFF;
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
html.dark-mode .alert-auto-fill {
    background: #1E293B;
    border-color: #F59E0B;
}
html.dark-mode .alert-auto-fill .alert-content strong {
    color: #FBBF24;
}
html.dark-mode .alert-auto-fill .alert-content p {
    color: #D1D5DB;
}
html.dark-mode .alert-warning {
    background: #1E293B;
    color: #FBBF24;
    border-color: #F59E0B;
}
html.dark-mode .alert-info {
    background: #1E293B;
    color: #60A5FA;
    border-color: #3B82F6;
}
.alert i { font-size: 20px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    color: inherit;
    cursor: pointer;
    padding: 0 4px;
    opacity: 0.6;
}
.alert-close:hover { opacity: 1; }
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   FORM CONTAINER
   ============================================================ */
.form-container {
    background: var(--form-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--form-shadow);
    border: 1px solid var(--form-border);
    overflow: hidden;
}
.form-section {
    padding: 20px 24px;
    border-bottom: 1px solid var(--form-border);
}
.form-section:last-child { border-bottom: none; }
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.section-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--form-text);
    margin: 0;
}
.section-header h3 i { color: #F59E0B; margin-right: 8px; }
.section-sub {
    font-size: 13px;
    color: var(--form-text-secondary);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.form-group label {
    font-size: 13px;
    font-weight: 600;
    color: var(--form-text);
}
.form-group label .required { color: #DC2626; font-weight: 700; }
.input-group {
    position: relative;
    display: flex;
    align-items: center;
}
.input-icon {
    position: absolute;
    left: 12px;
    color: var(--form-text-light);
    font-size: 14px;
    z-index: 1;
    pointer-events: none;
}
.input-group .form-control {
    padding: 10px 14px 10px 40px;
    border-radius: 8px;
    border: 1px solid var(--form-border);
    font-size: 14px;
    outline: none;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    background: var(--form-input-bg);
    color: var(--form-text);
    width: 100%;
}
.input-group .form-control:focus {
    border-color: #F59E0B;
    box-shadow: 0 0 0 3px rgba(245,158,11,0.1);
}
.input-group .form-control:disabled,
.input-group .form-control[readonly] {
    cursor: not-allowed;
    opacity: 0.7;
}
.input-group select.form-control {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236B7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}
html.dark-mode .input-group select.form-control {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%239CA3AF' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
}
.input-group select.form-control:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}
.form-group small {
    font-size: 12px;
    color: var(--form-text-secondary);
    margin-top: 2px;
}

/* ============================================================
   PROVIDERS GRID
   ============================================================ */
.providers-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
}
.provider-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: var(--provider-bg);
    border-radius: 8px;
    border: 2px solid var(--provider-border);
    transition: all 0.3s ease;
}
.provider-item:hover {
    background: var(--provider-bg-hover);
    border-color: #66BB6A;
    box-shadow: 0 2px 8px var(--form-shadow);
    transform: translateY(-1px);
}
.provider-item.provider-locked:hover {
    transform: none;
    box-shadow: none;
}
.provider-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 14px;
    flex-shrink: 0;
}
.provider-info { flex: 1; min-width: 0; }
.provider-name {
    font-size: 13px;
    font-weight: 500;
    color: var(--provider-text);
    display: block;
}
.provider-code {
    font-size: 10px;
    color: var(--form-text-light);
    text-transform: uppercase;
}
.provider-input {
    width: 110px;
    flex-shrink: 0;
    position: relative;
}
.provider-input .form-control {
    padding: 6px 10px;
    border-radius: 6px;
    border: 2px solid var(--provider-border);
    font-size: 13px;
    outline: none;
    transition: all 0.3s ease;
    background: #FFFFFF;
    color: var(--form-text);
    width: 100%;
    text-align: right;
    font-weight: 600;
}
html.dark-mode .provider-input .form-control {
    background: #2D4A2D;
    color: #E8F5E9;
}
.provider-input .form-control:focus {
    border-color: #4CAF50;
    box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
}
.provider-input .form-control[readonly] {
    padding-right: 30px;
}
.money-input {
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* ============================================================
   PROVIDERS SUMMARY
   ============================================================ */
.providers-summary {
    background: var(--form-hover);
    border-radius: 8px;
    padding: 14px 18px;
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 12px;
}
.summary-row {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}
.summary-row.total {
    border-left: 2px solid var(--form-border);
    padding-left: 16px;
}
.summary-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--form-text-secondary);
    font-weight: 600;
}
.summary-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--form-text);
}
.summary-row.total .summary-value { color: #10B981; }

/* ============================================================
   FORM ACTIONS
   ============================================================ */
.form-actions {
    display: flex;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--form-border);
    background: var(--form-card-header);
}
.btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}
.btn-submit {
    background: #F59E0B;
    color: white;
}
.btn-submit:hover {
    background: #D97706;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245,158,11,0.3);
}
.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
.btn-reset {
    background: var(--form-hover);
    color: var(--form-text-secondary);
}
.btn-reset:hover {
    background: var(--form-border);
    color: var(--form-text);
}
.btn-cancel {
    background: var(--form-hover);
    color: var(--form-text-secondary);
}
.btn-cancel:hover {
    background: #FEE2E2;
    color: #991B1B;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .providers-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .page-header { flex-direction: column; gap: 12px; align-items: flex-start; }
    .form-row { grid-template-columns: 1fr; gap: 12px; }
    .form-section { padding: 16px 14px; }
    .providers-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .provider-item { padding: 8px 10px; flex-wrap: wrap; }
    .provider-input { width: 100%; }
    .form-actions { flex-direction: column; }
    .form-actions .btn { justify-content: center; width: 100%; }
    .providers-summary { flex-direction: column; align-items: stretch; gap: 8px; }
    .summary-row.total {
        border-left: none;
        border-top: 2px solid var(--form-border);
        padding-left: 0;
        padding-top: 8px;
    }
    .branch-indicator { flex-direction: column; gap: 12px; align-items: flex-start; padding: 16px 18px; }
    .branch-indicator-left { width: 100%; flex-wrap: wrap; }
    .branch-indicator-right { width: 100%; flex-wrap: wrap; }
    .branch-select-wrapper { width: 100%; }
    .branch-filter-select { width: 100%; padding: 8px 30px 8px 12px; }
    .alert-auto-fill .alert-content { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .providers-grid { grid-template-columns: 1fr; }
    .section-header { flex-direction: column; align-items: flex-start; gap: 4px; }
    .summary-value { font-size: 15px; }
    .provider-item { padding: 6px 8px; }
    .branch-indicator-name { font-size: 14px; }
    .branch-icon-wrapper { width: 38px; height: 38px; font-size: 17px; }
}
</style>

<script>
// ============================================================
// FORMAT MONEY INPUT
// ============================================================
function formatMoneyInput(input) {
    if (input.readOnly) return;
    var value = input.value.replace(/[^0-9.]/g, '');
    var parts = value.split('.');
    var integerPart = parts[0] || '';
    var decimalPart = parts[1] || '';
    
    if (integerPart.length > 0) {
        integerPart = parseInt(integerPart).toLocaleString('en-US');
    }
    
    if (decimalPart.length > 2) {
        decimalPart = decimalPart.substring(0, 2);
    }
    
    var formatted = integerPart;
    if (decimalPart.length > 0) {
        formatted += '.' + decimalPart;
    }
    
    input.value = formatted;
}

// ============================================================
// CALCULATE TOTALS
// ============================================================
function calculateTotals() {
    var providerInputs = document.querySelectorAll('.provider-amount');
    var totalFloat = 0;
    
    providerInputs.forEach(function(input) {
        if (!input.readOnly) {
            var rawValue = input.value.replace(/,/g, '');
            var value = parseFloat(rawValue) || 0;
            totalFloat += value;
        }
    });
    
    var cashBalanceInput = document.getElementById('cash_balance');
    var rawCash = cashBalanceInput ? cashBalanceInput.value.replace(/,/g, '') : '0';
    var cashBalance = parseFloat(rawCash) || 0;
    var grandTotal = totalFloat + cashBalance;
    
    var totalFloatDisplay = document.getElementById('totalFloatDisplay');
    var cashBalanceDisplay = document.getElementById('cashBalanceDisplay');
    var grandTotalDisplay = document.getElementById('grandTotalDisplay');
    
    if (totalFloatDisplay) totalFloatDisplay.textContent = 'TSh ' + formatNumberDisplay(totalFloat);
    if (cashBalanceDisplay) cashBalanceDisplay.textContent = 'TSh ' + formatNumberDisplay(cashBalance);
    if (grandTotalDisplay) grandTotalDisplay.textContent = 'TSh ' + formatNumberDisplay(grandTotal);
}

function formatNumberDisplay(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

// ============================================================
// VALIDATE FORM
// ============================================================
function validateForm() {
    var branch = document.getElementById('branch_id');
    if (!branch || branch.value === '0' || branch.value === '') {
        alert('Please select a branch.');
        if (branch) branch.focus();
        return false;
    }
    
    var hasProvider = false;
    var providerInputs = document.querySelectorAll('.provider-amount');
    providerInputs.forEach(function(input) {
        if (!input.readOnly) {
            var rawValue = input.value.replace(/,/g, '');
            var value = parseFloat(rawValue) || 0;
            if (value > 0) {
                hasProvider = true;
            }
        } else {
            var rawValue = input.value.replace(/,/g, '');
            var value = parseFloat(rawValue) || 0;
            if (value > 0) {
                hasProvider = true;
            }
        }
    });
    
    if (!hasProvider) {
        alert('Please enter at least one provider amount.');
        return false;
    }
    
    var submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    return true;
}

// ============================================================
// CONFIRM RESET
// ============================================================
function confirmReset() {
    return confirm('Are you sure you want to reset the form? All entered data will be lost.');
}

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
    
    var cashBalance = document.getElementById('cash_balance');
    if (cashBalance && !cashBalance.readOnly) {
        cashBalance.addEventListener('input', function() {
            formatMoneyInput(this);
            calculateTotals();
        });
    }
    
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
    
    var successAlert = document.getElementById('successAlert');
    if (successAlert) {
        setTimeout(function() {
            successAlert.style.display = 'none';
        }, 5000);
    }
    
    var errorAlert = document.getElementById('errorAlert');
    if (errorAlert) {
        setTimeout(function() {
            errorAlert.style.display = 'none';
        }, 8000);
    }
});
</script>

</body>
</html>