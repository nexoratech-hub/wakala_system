<?php
// ================================================================
// FILE: modules/capital_management/index.php
// CAPITAL MANAGEMENT
// ✅ FIXED: Reads BOTH 'branch' AND 'branch_id' from URL
// ✅ FIXED: Empty branch shows 0 card
// ✅ FIXED: Table only shows when there are records
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
$role = $_SESSION['role'] ?? 'employee';

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

// ============================================================
// ✅ FIXED: GET FILTERS - Support BOTH 'branch' AND 'branch_id'
// ============================================================
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// ✅ CRITICAL: Support BOTH parameter names
$selected_branch = 0;

// Priority 1: branch_id (from topbar links)
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
}
// Priority 2: branch (from filter form)
elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
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
// BUILD QUERY - WITH BRANCH FILTER
// ============================================================
try {
    $sql = "SELECT cm.*, 
            e.full_name as employee_name,
            b.branch_name as branch_name,
            b.branch_code as branch_code,
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
// GROUP BY BRANCH
// ============================================================
$grouped_by_branch = [];
$grand_totals = [
    'total_in' => 0,
    'total_out' => 0,
    'float_in' => 0,
    'cash_in' => 0,
    'count' => 0,
    'branches_count' => 0
];

// If branch selected, only init that one
if ($selected_branch > 0) {
    foreach ($branches as $b) {
        if ($b['id'] == $selected_branch) {
            $grouped_by_branch[$b['id']] = [
                'branch_id' => $b['id'],
                'branch_name' => $b['branch_name'],
                'branch_code' => $b['branch_code'] ?? '',
                'location' => $b['location'] ?? '',
                'transactions' => [],
                'total_in' => 0,
                'total_out' => 0,
                'float_in' => 0,
                'cash_in' => 0,
                'opening' => 0,
                'additional' => 0,
                'profit_allocation' => 0,
                'cash_out_total' => 0,
                'adjustment' => 0,
                'count' => 0,
                'current_capital' => 0
            ];
            break;
        }
    }
} else {
    foreach ($branches as $b) {
        $grouped_by_branch[$b['id']] = [
            'branch_id' => $b['id'],
            'branch_name' => $b['branch_name'],
            'branch_code' => $b['branch_code'] ?? '',
            'location' => $b['location'] ?? '',
            'transactions' => [],
            'total_in' => 0,
            'total_out' => 0,
            'float_in' => 0,
            'cash_in' => 0,
            'opening' => 0,
            'additional' => 0,
            'profit_allocation' => 0,
            'cash_out_total' => 0,
            'adjustment' => 0,
            'count' => 0,
            'current_capital' => 0
        ];
    }
}

foreach ($all_transactions as $t) {
    $b_id = $t['branch_id'] ?? 0;
    if (!isset($grouped_by_branch[$b_id])) continue;
    
    $grouped_by_branch[$b_id]['transactions'][] = $t;
    $grouped_by_branch[$b_id]['count']++;
    
    $is_outgoing = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
    $amount = floatval($t['amount']);
    $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
    
    if (isset($grouped_by_branch[$b_id][$t['transaction_type']])) {
        $grouped_by_branch[$b_id][$t['transaction_type']] += $amount;
    }
    if ($t['transaction_type'] === 'cash_out') {
        $grouped_by_branch[$b_id]['cash_out_total'] += $amount;
    }
    
    if ($is_outgoing) {
        $grouped_by_branch[$b_id]['total_out'] += $amount;
        $grand_totals['total_out'] += $amount;
    } else {
        $grouped_by_branch[$b_id]['total_in'] += $amount;
        if ($is_float) {
            $grouped_by_branch[$b_id]['float_in'] += $amount;
            $grand_totals['float_in'] += $amount;
        } else {
            $grouped_by_branch[$b_id]['cash_in'] += $amount;
            $grand_totals['cash_in'] += $amount;
        }
        $grand_totals['total_in'] += $amount;
    }
    
    $grand_totals['count']++;
}

foreach ($grouped_by_branch as $b_id => &$branch_data) {
    if ($branch_data['count'] > 0) {
        $branch_data['current_capital'] = $branch_data['total_in'] - $branch_data['total_out'];
        $grand_totals['branches_count']++;
    }
}
unset($branch_data);

$grand_totals['net_capital'] = $grand_totals['total_in'] - $grand_totals['total_out'];

// Type labels
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
        
        <!-- ============================================================
        BRANCH FILTER CARD
        ============================================================ -->
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
                <div class="dropdown export-dropdown">
                    <button class="btn btn-export dropdown-toggle" onclick="toggleDropdown()">
                        <i class="fas fa-file-export"></i> Export
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu" id="exportMenu">
                        <a href="#" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> CSV</a>
                        <a href="#" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</a>
                        <a href="#" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</a>
                        <a href="#" onclick="window.print()"><i class="fas fa-print"></i> Print</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        PAGE HEADER
        ============================================================ -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-building" style="color:#bb0404;"></i> Capital Management</h2>
                <p class="text-muted">
                    <?php if ($selected_branch > 0): ?>
                        Capital transactions for <strong><?php echo htmlspecialchars($filter_branch_name); ?></strong>
                    <?php else: ?>
                        Capital transactions grouped by branch
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- ============================================================
        GRAND TOTALS
        ============================================================ -->
        <div class="grand-totals-card">
            <div class="grand-totals-header">
                <i class="fas fa-chart-pie"></i>
                <span>Capital Summary <?php echo $selected_branch > 0 ? '- ' . htmlspecialchars($filter_branch_name) : '(All Branches)'; ?></span>
                <span class="period-badge">
                    <?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>
                </span>
            </div>
            <div class="grand-totals-grid">
                <div class="grand-stat">
                    <span class="grand-stat-label">Total In</span>
                    <span class="grand-stat-value text-success">+ <?php echo formatCurrency($grand_totals['total_in']); ?></span>
                </div>
                <div class="grand-stat">
                    <span class="grand-stat-label">Total Out</span>
                    <span class="grand-stat-value text-danger">- <?php echo formatCurrency($grand_totals['total_out']); ?></span>
                </div>
                <div class="grand-stat">
                    <span class="grand-stat-label">Net Capital</span>
                    <span class="grand-stat-value <?php echo $grand_totals['net_capital'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($grand_totals['net_capital']); ?>
                    </span>
                </div>
                <div class="grand-stat">
                    <span class="grand-stat-label">Float In</span>
                    <span class="grand-stat-value text-blue"><?php echo formatCurrency($grand_totals['float_in']); ?></span>
                </div>
                <div class="grand-stat">
                    <span class="grand-stat-label">Cash In</span>
                    <span class="grand-stat-value text-green"><?php echo formatCurrency($grand_totals['cash_in']); ?></span>
                </div>
                <div class="grand-stat">
                    <span class="grand-stat-label">Records</span>
                    <span class="grand-stat-value"><?php echo $grand_totals['count']; ?></span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        FILTERS
        ============================================================ -->
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

        <!-- ============================================================
        BRANCH GROUPS
        ============================================================ -->
        <?php if (empty($grouped_by_branch)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Branches Found</h3>
                <p>Please add branches first.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped_by_branch as $b_id => $branch_data): ?>
                
                <?php 
                // Skip empty branches ONLY when showing All Branches
                if ($selected_branch == 0 && $branch_data['count'] == 0) {
                    continue;
                }
                ?>
                
                <div class="branch-group-card <?php echo $branch_data['count'] > 0 ? 'has-data' : 'no-data'; ?>" 
                     data-branch-id="<?php echo $b_id; ?>">
                    
                    <!-- ===== BRANCH HEADER ===== -->
                    <div class="branch-group-header">
                        <div class="branch-group-title">
                            <div class="branch-icon-wrapper">
                                <i class="fas fa-store-alt"></i>
                            </div>
                            <div class="branch-title-info">
                                <h3><?php echo htmlspecialchars($branch_data['branch_name']); ?></h3>
                                <div class="branch-meta">
                                    <?php if ($branch_data['branch_code']): ?>
                                        <span class="branch-code-badge"><?php echo htmlspecialchars($branch_data['branch_code']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($branch_data['location']): ?>
                                        <span class="branch-location-inline">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($branch_data['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="record-count-badge">
                                        <i class="fas fa-list"></i>
                                        <span class="record-count-text" data-total="<?php echo $branch_data['count']; ?>">
                                            <?php echo $branch_data['count']; ?> transaction<?php echo $branch_data['count'] != 1 ? 's' : ''; ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="branch-group-actions">
                            <?php if ($selected_branch == 0): ?>
                                <a href="?branch_id=<?php echo $b_id; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&type=<?php echo $type_filter; ?>" 
                                   class="btn btn-sm btn-outline">
                                    <i class="fas fa-eye"></i> View Only
                                </a>
                            <?php endif; ?>
                            <a href="add.php?branch=<?php echo $b_id; ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-plus"></i> Add
                            </a>
                        </div>
                    </div>
                    
                    <?php if ($branch_data['count'] > 0): ?>
                        <!-- ===== SUMMARY ===== -->
                        <div class="branch-summary-bar">
                            <div class="summary-stat">
                                <span class="summary-stat-label">
                                    <i class="fas fa-arrow-down text-success"></i> Total In
                                </span>
                                <span class="summary-stat-value text-success">
                                    + <?php echo formatCurrency($branch_data['total_in']); ?>
                                </span>
                            </div>
                            <div class="summary-stat">
                                <span class="summary-stat-label">
                                    <i class="fas fa-arrow-up text-danger"></i> Total Out
                                </span>
                                <span class="summary-stat-value text-danger">
                                    - <?php echo formatCurrency($branch_data['total_out']); ?>
                                </span>
                            </div>
                            <div class="summary-stat highlight">
                                <span class="summary-stat-label">
                                    <i class="fas fa-wallet"></i> Current Capital
                                </span>
                                <span class="summary-stat-value <?php echo $branch_data['current_capital'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatCurrency($branch_data['current_capital']); ?>
                                </span>
                            </div>
                            <div class="summary-stat">
                                <span class="summary-stat-label">
                                    <i class="fas fa-university text-blue"></i> Float In
                                </span>
                                <span class="summary-stat-value text-blue">
                                    <?php echo formatCurrency($branch_data['float_in']); ?>
                                </span>
                            </div>
                            <div class="summary-stat">
                                <span class="summary-stat-label">
                                    <i class="fas fa-money-bill-wave text-green"></i> Cash In
                                </span>
                                <span class="summary-stat-value text-green">
                                    <?php echo formatCurrency($branch_data['cash_in']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- ===== TYPE BREAKDOWN ===== -->
                        <div class="type-breakdown-bar">
                            <?php foreach ($type_labels as $key => $label): 
                                $amount = $branch_data[$key] ?? 0;
                                if ($amount == 0) continue;
                                $is_out = in_array($key, ['cash_out', 'adjustment']);
                            ?>
                                <div class="type-pill type-<?php echo $label['color']; ?>">
                                    <i class="fas <?php echo $label['icon']; ?>"></i>
                                    <span><?php echo $label['label']; ?>:</span>
                                    <strong class="<?php echo $is_out ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $is_out ? '-' : '+'; ?>
                                        <?php echo formatCurrency($amount); ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- ===== TABLE ===== -->
                        <div class="table-container-inner">
                            <div class="table-responsive">
                                <table class="data-table" data-branch-table="<?php echo $b_id; ?>">
                                    <thead>
                                        <tr class="table-header-row">
                                            <th colspan="8" class="table-search-header">
                                                <div class="table-search-wrapper">
                                                    <i class="fas fa-search table-search-icon"></i>
                                                    <input type="text" 
                                                           class="table-search-input" 
                                                           data-branch-id="<?php echo $b_id; ?>"
                                                           placeholder="Search..."
                                                           oninput="onTableSearch(this, <?php echo $b_id; ?>)">
                                                    <button type="button" class="table-search-clear" 
                                                            data-branch-id="<?php echo $b_id; ?>"
                                                            onclick="clearTableSearch(<?php echo $b_id; ?>)" 
                                                            style="display:none;">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <span class="table-search-count" data-branch-id="<?php echo $b_id; ?>" style="display:none;">0</span>
                                                </div>
                                            </th>
                                        </tr>
                                        <tr>
                                            <th style="width: 40px;">#</th>
                                            <th>Number</th>
                                            <th>Date</th>
                                            <th>Type</th>
                                            <th>Source</th>
                                            <th>Employee</th>
                                            <th class="text-right">Amount</th>
                                            <th style="width: 90px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody data-branch-tbody="<?php echo $b_id; ?>">
                                        <?php $counter = 1; ?>
                                        <?php foreach ($branch_data['transactions'] as $t): 
                                            $type_info = $type_labels[$t['transaction_type']] ?? ['label' => $t['transaction_type'], 'color' => 'gray'];
                                            $is_out = in_array($t['transaction_type'], ['cash_out', 'adjustment']);
                                            $is_float = ($t['reference_module'] === 'provider' && !empty($t['provider_name']));
                                            
                                            $search_data = strtolower(
                                                $t['capital_number'] . ' ' .
                                                ($t['provider_name'] ?? '') . ' ' .
                                                ($t['branch_provider_code'] ?? '') . ' ' .
                                                ($t['employee_name'] ?? '') . ' ' .
                                                ($t['description'] ?? '') . ' ' .
                                                ($t['notes'] ?? '') . ' ' .
                                                $type_info['label']
                                            );
                                        ?>
                                            <tr class="transaction-row" 
                                                data-search="<?php echo htmlspecialchars($search_data); ?>">
                                                <td class="row-number"><?php echo $counter++; ?></td>
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
                                                                <span class="source-name">
                                                                    <?php echo htmlspecialchars($t['provider_name']); ?>
                                                                </span>
                                                                <span class="source-code">
                                                                    <?php echo htmlspecialchars($t['branch_provider_code'] ?? 'N/A'); ?>
                                                                </span>
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
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="view.php?id=<?php echo $t['id']; ?>" class="btn-action btn-view" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit.php?id=<?php echo $t['id']; ?>" class="btn-action btn-edit" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <div class="no-branch-results" data-branch-id="<?php echo $b_id; ?>" style="display:none;">
                                    <i class="fas fa-search-minus"></i>
                                    <p>No records match your search</p>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="clearTableSearch(<?php echo $b_id; ?>)">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <!-- ✅ Branch selected but NO RECORDS -->
                        <div class="branch-no-data">
                            <div class="zero-badge">
                                <i class="fas fa-inbox"></i>
                                <span class="zero-count">0</span>
                            </div>
                            <h4>No Capital Records for <?php echo htmlspecialchars($branch_data['branch_name']); ?></h4>
                            <p>No capital transactions found for this branch in the selected period.</p>
                            <a href="add.php?branch=<?php echo $b_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus"></i> Add First Transaction
                            </a>
                        </div>
                    <?php endif; ?>
                    
                </div>
            <?php endforeach; ?>
            
            <?php if ($grand_totals['count'] == 0 && $selected_branch == 0): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Transactions Found</h3>
                    <p>No capital transactions in any branch for the selected period.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
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

body {
    background: var(--cm-bg) !important;
    color: var(--cm-text);
    transition: background 0.3s ease, color 0.3s ease;
}
.main-wrapper { background: var(--cm-bg) !important; }
.main-content { background: var(--cm-bg) !important; }

/* ============================================================
   BRANCH FILTER CARD
   ============================================================ */
.branch-filter-card {
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    color: #FFFFFF;
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
}
.filter-left i { font-size: 18px; opacity: 0.9; }
.filter-label { font-weight: 500; opacity: 0.8; }
.filter-name { font-weight: 700; font-size: 16px; }
.filter-code {
    font-size: 12px;
    font-weight: 600;
    opacity: 0.7;
    padding: 2px 10px;
    background: rgba(255,255,255,0.12);
    border-radius: 10px;
}
.filter-clear {
    margin-left: 8px;
    color: #FFFFFF;
    text-decoration: none;
    font-size: 12px;
    padding: 4px 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.filter-clear:hover { background: rgba(255,255,255,0.25); color: #FFFFFF; }
.filter-right { display: flex; gap: 8px; flex-wrap: wrap; }

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
   GRAND TOTALS
   ============================================================ */
.grand-totals-card {
    background: linear-gradient(135deg, #1E40AF 0%, #1D4ED8 100%);
    border-radius: 12px;
    padding: 0;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(30, 64, 175, 0.3);
}
.grand-totals-header {
    padding: 14px 22px;
    background: rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 10px;
    color: #FFFFFF;
    font-size: 14px;
    font-weight: 600;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.grand-totals-header i { font-size: 16px; }
.grand-totals-header .period-badge {
    margin-left: auto;
    font-size: 11px;
    font-weight: 500;
    padding: 3px 12px;
    background: rgba(255,255,255,0.15);
    border-radius: 12px;
}
.grand-totals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0;
    padding: 8px 0;
}
.grand-stat {
    text-align: center;
    padding: 12px 16px;
    border-right: 1px solid rgba(255,255,255,0.1);
}
.grand-stat:last-child { border-right: none; }
.grand-stat-label {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.6);
    letter-spacing: 1px;
    font-weight: 600;
    margin-bottom: 4px;
}
.grand-stat-value {
    display: block;
    font-size: 16px;
    font-weight: 800;
    color: #FFFFFF;
    letter-spacing: 0.3px;
}
.grand-stat-value.text-success { color: #6EE7B7; }
.grand-stat-value.text-danger { color: #FCA5A5; }
.grand-stat-value.text-blue { color: #93C5FD; }
.grand-stat-value.text-green { color: #86EFAC; }

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
.btn-sm { padding: 5px 12px; font-size: 12px; }
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
.btn-outline {
    background: transparent;
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-outline:hover {
    background: var(--cm-hover);
    color: var(--cm-text);
    border-color: #3B82F6;
}
.btn-secondary {
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border: 1px solid var(--cm-border);
}
.btn-secondary:hover { background: var(--cm-border); color: var(--cm-text); }

/* ============================================================
   EXPORT DROPDOWN
   ============================================================ */
.dropdown { position: relative; display: inline-block; }
.dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    background: var(--cm-card-bg);
    min-width: 180px;
    border-radius: 8px;
    box-shadow: 0 4px 20px var(--cm-shadow-md);
    border: 1px solid var(--cm-border);
    z-index: 1000;
    overflow: hidden;
    padding: 4px 0;
}
.dropdown-menu.show { display: block; }
.dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 14px;
    text-decoration: none;
    color: var(--cm-text);
    font-size: 13px;
    transition: background 0.2s ease;
}
.dropdown-menu a:hover { background: var(--cm-hover); }
.dropdown-menu a i { width: 18px; font-size: 15px; }

/* ============================================================
   BRANCH GROUP CARD
   ============================================================ */
.branch-group-card {
    background: var(--cm-card-bg);
    border-radius: 12px;
    border: 1px solid var(--cm-border);
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 1px 3px var(--cm-shadow);
    transition: all 0.3s ease;
    animation: fadeInUp 0.4s ease both;
}
.branch-group-card:hover {
    box-shadow: 0 4px 16px var(--cm-shadow-md);
}
.branch-group-card.has-data {
    border-left: 4px solid #3B82F6;
}
.branch-group-card.no-data {
    border-left: 4px solid #F59E0B;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   BRANCH GROUP HEADER
   ============================================================ */
.branch-group-header {
    background: var(--cm-card-header);
    padding: 16px 22px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    border-bottom: 1px solid var(--cm-border);
    flex-wrap: wrap;
}
.branch-group-card.has-data .branch-group-header {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(59, 130, 246, 0.02));
}

.branch-group-title {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
}
.branch-icon-wrapper {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3B82F6, #2563EB);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}
.branch-title-info { flex: 1; min-width: 0; }
.branch-title-info h3 {
    font-size: 17px;
    font-weight: 700;
    color: var(--cm-text);
    margin: 0 0 4px 0;
}
.branch-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.branch-code-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 10px;
    background: rgba(59, 130, 246, 0.15);
    color: #3B82F6;
    border-radius: 10px;
    letter-spacing: 0.5px;
}
.branch-location-inline {
    font-size: 11px;
    color: var(--cm-text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.branch-location-inline i { font-size: 10px; color: #3B82F6; }
.record-count-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 10px;
    background: var(--cm-hover);
    color: var(--cm-text-secondary);
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.record-count-badge i { font-size: 9px; }

.branch-group-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    flex-wrap: wrap;
}

/* ============================================================
   BRANCH SUMMARY BAR
   ============================================================ */
.branch-summary-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 0;
    background: var(--cm-card-bg);
    border-bottom: 1px solid var(--cm-border);
}
.summary-stat {
    padding: 14px 18px;
    border-right: 1px solid var(--cm-border);
    display: flex;
    flex-direction: column;
    gap: 4px;
    transition: background 0.2s ease;
}
.summary-stat:last-child { border-right: none; }
.summary-stat:hover { background: var(--cm-hover); }
.summary-stat.highlight {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.02));
    border-left: 3px solid #3B82F6;
}
.summary-stat-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--cm-text-light);
    display: flex;
    align-items: center;
    gap: 5px;
}
.summary-stat-label i { font-size: 11px; }
.summary-stat-value {
    font-size: 15px;
    font-weight: 800;
    color: var(--cm-text);
}
.text-success { color: #10B981; }
.text-danger { color: #DC2626; }
.text-blue { color: #3B82F6; }
.text-green { color: #10B981; }

/* ============================================================
   TYPE BREAKDOWN
   ============================================================ */
.type-breakdown-bar {
    display: flex;
    gap: 8px;
    padding: 10px 22px;
    background: var(--cm-card-header);
    border-bottom: 1px solid var(--cm-border);
    flex-wrap: wrap;
}
.type-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    background: var(--cm-card-bg);
    border: 1px solid var(--cm-border);
}
.type-pill i { font-size: 10px; }
.type-pill span { color: var(--cm-text-secondary); }
.type-pill strong { font-weight: 700; }
.type-pill.type-blue { border-color: #3B82F6; }
.type-pill.type-green { border-color: #10B981; }
.type-pill.type-purple { border-color: #8B5CF6; }
.type-pill.type-red { border-color: #DC2626; }
.type-pill.type-orange { border-color: #F59E0B; }

/* ============================================================
   TABLE
   ============================================================ */
.table-container-inner {
    padding: 0;
    overflow: hidden;
}
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 900px;
}

/* Compact Search Header */
.table-header-row {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%) !important;
}
.table-search-header {
    padding: 6px 14px !important;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%) !important;
    border-bottom: none !important;
}
.table-search-wrapper {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.95);
    border-radius: 6px;
    padding: 3px 10px;
    width: 280px;
    max-width: 100%;
}
html.dark-mode .table-search-wrapper {
    background: rgba(30, 41, 59, 0.95);
}
.table-search-icon {
    color: #DC2626;
    font-size: 11px;
    flex-shrink: 0;
}
.table-search-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 5px 2px;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    color: #1F2937;
    outline: none;
    min-width: 0;
}
html.dark-mode .table-search-input {
    color: #F9FAFB;
}
.table-search-input::placeholder {
    color: #9CA3AF;
    font-size: 11px;
}
.table-search-clear {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #FEE2E2;
    color: #DC2626;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 8px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}
.table-search-clear:hover {
    background: #DC2626;
    color: #FFFFFF;
}
.table-search-count {
    font-size: 9px;
    font-weight: 700;
    padding: 2px 8px;
    background: #F59E0B;
    color: #FFFFFF;
    border-radius: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Table Header (Red Background) */
.data-table thead tr:not(.table-header-row) {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.data-table thead th:not(.table-search-header) {
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.8px;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
}
.data-table thead th.text-right { text-align: right; }

.data-table tbody tr {
    border-bottom: 1px solid var(--cm-border);
    transition: background 0.2s ease;
}
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody tr:hover { background: var(--cm-hover); }
.data-table tbody td {
    padding: 10px 14px;
    color: var(--cm-text);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }

.transaction-row.hidden-by-search {
    display: none !important;
}

.capital-link {
    font-weight: 700;
    color: #3B82F6;
    text-decoration: none;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    transition: color 0.2s ease;
}
.capital-link:hover { color: #2563EB; text-decoration: underline; }

.date-display {
    font-size: 12px;
    font-weight: 600;
    color: var(--cm-text-secondary);
    white-space: nowrap;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
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

.source-display {
    display: flex;
    align-items: center;
    gap: 8px;
}
.source-icon-sm {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 12px;
    flex-shrink: 0;
}
.source-cash .source-icon-sm {
    background: linear-gradient(135deg, #10B981, #059669);
}
.source-info-sm {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
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
}

.employee-display {
    font-size: 12px;
    font-weight: 500;
    color: var(--cm-text-secondary);
    white-space: nowrap;
}

.amount-display {
    font-size: 13px;
    font-weight: 800;
    white-space: nowrap;
    font-family: 'Courier New', monospace;
}

.action-buttons {
    display: flex;
    gap: 4px;
    justify-content: center;
}
.btn-action {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 12px;
}
.btn-view { background: #DBEAFE; color: #1D4ED8; }
.btn-view:hover { background: #1D4ED8; color: #FFFFFF; transform: translateY(-1px); }
.btn-edit { background: #D1FAE5; color: #059669; }
.btn-edit:hover { background: #059669; color: #FFFFFF; transform: translateY(-1px); }
html.dark-mode .btn-view { background: #1E3A5F; color: #60A5FA; }
html.dark-mode .btn-view:hover { background: #3B82F6; color: #FFFFFF; }
html.dark-mode .btn-edit { background: #065F46; color: #34D399; }
html.dark-mode .btn-edit:hover { background: #10B981; color: #FFFFFF; }

/* ============================================================
   NO BRANCH RESULTS
   ============================================================ */
.no-branch-results {
    text-align: center;
    padding: 30px 20px;
    background: var(--cm-hover);
    border-top: 1px solid var(--cm-border);
}
.no-branch-results i {
    font-size: 32px;
    color: var(--cm-text-light);
    opacity: 0.5;
    display: block;
    margin-bottom: 8px;
}
.no-branch-results p {
    font-size: 13px;
    color: var(--cm-text-secondary);
    margin: 0 0 12px 0;
}

/* ============================================================
   BRANCH NO DATA
   ============================================================ */
.branch-no-data {
    text-align: center;
    padding: 50px 20px;
    background: var(--cm-hover);
}
.zero-badge {
    position: relative;
    display: inline-block;
    margin-bottom: 16px;
}
.zero-badge i {
    font-size: 48px;
    color: #F59E0B;
    opacity: 0.4;
}
.zero-count {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 20px;
    font-weight: 900;
    color: #F59E0B;
}
.branch-no-data h4 {
    font-size: 18px;
    font-weight: 700;
    color: var(--cm-text);
    margin: 0 0 8px 0;
}
.branch-no-data p {
    font-size: 14px;
    color: var(--cm-text-secondary);
    margin: 0 0 18px 0;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

/* ============================================================
   EMPTY STATE
   ============================================================ */
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
    margin: 0;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .grand-totals-grid { grid-template-columns: repeat(3, 1fr); }
    .grand-stat:nth-child(3n) { border-right: none; }
    .grand-stat { border-bottom: 1px solid rgba(255,255,255,0.1); }
}

@media (max-width: 768px) {
    .branch-filter-card { flex-direction: column; align-items: flex-start; padding: 14px 18px; }
    .filter-right { width: 100%; }
    .filter-right .btn { flex: 1; justify-content: center; }
    
    .grand-totals-grid { grid-template-columns: repeat(2, 1fr); }
    .grand-stat:nth-child(2n) { border-right: none; }
    .grand-stat:nth-child(3n) { border-right: 1px solid rgba(255,255,255,0.1); }
    
    .filters-form { flex-direction: column; }
    .filter-group { width: 100%; }
    .filter-group .form-control { width: 100%; }
    .filter-group.filter-buttons { flex-direction: column; }
    .filter-group.filter-buttons .btn { width: 100%; justify-content: center; }
    
    .branch-group-header { flex-direction: column; align-items: flex-start; }
    .branch-group-actions { width: 100%; }
    .branch-group-actions .btn { flex: 1; justify-content: center; }
    
    .branch-summary-bar { grid-template-columns: repeat(2, 1fr); }
    .summary-stat:nth-child(2n) { border-right: none; }
    
    .table-search-wrapper { width: 200px; }
}

@media (max-width: 480px) {
    .grand-totals-grid { grid-template-columns: 1fr; }
    .grand-stat { border-right: none; }
    .grand-stat-value { font-size: 14px; }
    
    .branch-title-info h3 { font-size: 15px; }
    .branch-icon-wrapper { width: 40px; height: 40px; font-size: 17px; }
    
    .branch-summary-bar { grid-template-columns: 1fr; }
    .summary-stat { border-right: none; }
    .summary-stat-value { font-size: 14px; }
    
    .type-pill { font-size: 10px; padding: 3px 8px; }
    .source-name { max-width: 90px; font-size: 11px; }
    .amount-display { font-size: 12px; }
    .table-search-wrapper { width: 150px; }
    .table-search-count { display: none; }
}

/* ============================================================
   PRINT
   ============================================================ */
@media print {
    .branch-filter-card .filter-right,
    .filters-bar,
    .branch-group-actions,
    .action-buttons,
    .table-header-row {
        display: none !important;
    }
    .branch-group-card {
        box-shadow: none;
        border: 1px solid #ccc;
        page-break-inside: avoid;
    }
    body { background: #FFFFFF !important; }
}
</style>

<script>
// ============================================================
// PER-BRANCH TABLE SEARCH
// ============================================================
function onTableSearch(input, branchId) {
    const searchTerm = input.value.toLowerCase().trim();
    const tbody = document.querySelector(`[data-branch-tbody="${branchId}"]`);
    const clearBtn = document.querySelector(`.table-search-clear[data-branch-id="${branchId}"]`);
    const countBadge = document.querySelector(`.table-search-count[data-branch-id="${branchId}"]`);
    const noResults = document.querySelector(`.no-branch-results[data-branch-id="${branchId}"]`);
    const card = document.querySelector(`.branch-group-card[data-branch-id="${branchId}"]`);
    const countText = card ? card.querySelector('.record-count-text') : null;
    const totalCount = countText ? parseInt(countText.getAttribute('data-total')) || 0 : 0;
    
    if (!tbody) return;
    
    const rows = tbody.querySelectorAll('.transaction-row');
    
    if (clearBtn) {
        clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    }
    
    if (searchTerm.length === 0) {
        rows.forEach(row => row.classList.remove('hidden-by-search'));
        rows.forEach((row, idx) => {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = idx + 1;
        });
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        if (countText) countText.textContent = totalCount + ' transaction' + (totalCount !== 1 ? 's' : '');
        return;
    }
    
    let matchCount = 0;
    rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            row.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            row.classList.add('hidden-by-search');
        }
    });
    
    let visibleIdx = 1;
    rows.forEach(row => {
        if (!row.classList.contains('hidden-by-search')) {
            const numCell = row.querySelector('.row-number');
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
    
    if (countText) {
        countText.textContent = matchCount + ' of ' + totalCount;
    }
}

function clearTableSearch(branchId) {
    const input = document.querySelector(`.table-search-input[data-branch-id="${branchId}"]`);
    if (input) {
        input.value = '';
        onTableSearch(input, branchId);
        input.focus();
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const firstInput = document.querySelector('.table-search-input');
        if (firstInput) {
            firstInput.focus();
            firstInput.select();
        }
    }
    if (e.key === 'Escape') {
        const focused = document.activeElement;
        if (focused && focused.classList.contains('table-search-input')) {
            const branchId = focused.getAttribute('data-branch-id');
            clearTableSearch(branchId);
        }
    }
});

// ============================================================
// EXPORT DROPDOWN
// ============================================================
function toggleDropdown() {
    document.getElementById('exportMenu').classList.toggle('show');
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-dropdown')) {
        var menu = document.getElementById('exportMenu');
        if (menu) menu.classList.remove('show');
    }
});
function exportData(format) {
    document.getElementById('exportMenu').classList.remove('show');
    const params = new URLSearchParams(window.location.search);
    window.location.href = 'export.php?format=' + format + '&' + params.toString();
}

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