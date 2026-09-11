<?php
// ================================================================
// FILE: modules/daily_report/view_provider_transactions.php
// WAKALA FINANCIAL SYSTEM - VIEW PROVIDER TRANSACTIONS
// ✅ Shows ALL transactions for a provider
// ✅ Shows WHO made each transaction (employee name)
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
// GET PARAMETERS
// ============================================================
$provider_id = isset($_GET['provider_id']) ? intval($_GET['provider_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');

if ($provider_id <= 0 || $branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider or branch.';
    header('Location: index.php');
    exit();
}

// ============================================================
// GET PROVIDER INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE id = ?");
$stmt->execute([$provider_id]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index.php?branch_id=' . $branch_id);
    exit();
}

// ============================================================
// GET BRANCH INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch(PDO::FETCH_ASSOC);

$branch_name = $branch['branch_name'] ?? 'Unknown';
$branch_code = $branch['branch_code'] ?? '';

// ============================================================
// GET BRANCH PROVIDER CODE
// ============================================================
$stmt = $db->prepare("
    SELECT provider_code 
    FROM branch_providers 
    WHERE branch_id = ? AND provider_id = ? 
    LIMIT 1
");
$stmt->execute([$branch_id, $provider_id]);
$bp = $stmt->fetch(PDO::FETCH_ASSOC);
$provider_branch_code = $bp['provider_code'] ?? $provider['provider_code'];

// ============================================================
// GET ALL TRANSACTIONS FOR THIS PROVIDER
// ============================================================
$sql = "
    SELECT 
        t.*,
        e.full_name as employee_name,
        e.profile_pic as employee_pic,
        b.branch_name,
        b.branch_code
    FROM transactions t
    LEFT JOIN employees e ON t.employee_id = e.id
    LEFT JOIN branches b ON t.branch_id = b.id
    WHERE t.provider_id = ?
";
$params = [$provider_id];

if ($branch_id > 0) {
    $sql .= " AND t.branch_id = ?";
    $params[] = $branch_id;
}

$sql .= " ORDER BY t.created_at DESC, t.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// CALCULATE SUMMARY
// ============================================================
$total_deposits = 0;
$total_withdrawals = 0;
$total_deposit_count = 0;
$total_withdrawal_count = 0;
$employee_summary = [];

foreach ($transactions as $t) {
    $amount = floatval($t['amount'] ?? 0);
    $emp_name = $t['employee_name'] ?? 'N/A';
    
    if ($t['transaction_type'] === 'deposit') {
        $total_deposits += $amount;
        $total_deposit_count++;
    } else {
        $total_withdrawals += $amount;
        $total_withdrawal_count++;
    }
    
    // Build employee summary
    if (!isset($employee_summary[$emp_name])) {
        $employee_summary[$emp_name] = [
            'name' => $emp_name,
            'deposits' => 0,
            'withdrawals' => 0,
            'count' => 0
        ];
    }
    if ($t['transaction_type'] === 'deposit') {
        $employee_summary[$emp_name]['deposits'] += $amount;
    } else {
        $employee_summary[$emp_name]['withdrawals'] += $amount;
    }
    $employee_summary[$emp_name]['count']++;
}

// ============================================================
// GET CURRENT FLOAT FOR THIS PROVIDER (from latest daily_report_providers)
// ============================================================
$stmt = $db->prepare("
    SELECT 
        drp.current_float,
        drp.current_cash,
        drp.morning_float,
        drp.total_deposits,
        drp.total_withdrawals,
        dr.report_date
    FROM daily_report_providers drp
    INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
    WHERE drp.provider_id = ? AND dr.branch_id = ?
    ORDER BY dr.report_date DESC, dr.id DESC
    LIMIT 1
");
$stmt->execute([$provider_id, $branch_id]);
$current = $stmt->fetch(PDO::FETCH_ASSOC);

$current_float = floatval($current['current_float'] ?? 0);
$current_cash = floatval($current['current_cash'] ?? 0);
$morning_float = floatval($current['morning_float'] ?? 0);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ============================================================
        PROVIDER HEADER CARD
        ============================================================ -->
        <div class="provider-header-card" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?> 0%, <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?>dd 100%);">
            <div class="provider-header-left">
                <div class="provider-header-icon">
                    <i class="<?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
                </div>
                <div class="provider-header-info">
                    <span class="provider-header-label">Provider Transactions</span>
                    <h1 class="provider-header-name"><?php echo htmlspecialchars($provider['provider_name']); ?></h1>
                    <div class="provider-header-meta">
                        <span class="provider-meta-item">
                            <i class="fas fa-tag"></i>
                            <?php echo htmlspecialchars($provider_branch_code); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-store-alt"></i>
                            <?php echo htmlspecialchars($branch_name); ?>
                        </span>
                        <span class="provider-meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d M Y', strtotime($report_date)); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="provider-header-right">
                <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn-back-header">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Reports</span>
                </a>
            </div>
        </div>

        <!-- ============================================================
        STATS CARDS
        ============================================================ -->
        <div class="stats-grid">
            <!-- Current Float -->
            <div class="stat-card stat-card-float">
                <div class="stat-card-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Current Float</span>
                    <span class="stat-card-value"><?php echo formatCurrency($current_float); ?></span>
                    <span class="stat-card-sub">
                        <i class="fas fa-arrow-right"></i>
                        Morning: <?php echo formatCurrency($morning_float); ?>
                    </span>
                </div>
            </div>
            
            <!-- Total Deposits -->
            <div class="stat-card stat-card-deposit">
                <div class="stat-card-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Deposits</span>
                    <span class="stat-card-value"><?php echo formatCurrency($total_deposits); ?></span>
                    <span class="stat-card-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($total_deposit_count); ?> transactions
                    </span>
                </div>
            </div>
            
            <!-- Total Withdrawals -->
            <div class="stat-card stat-card-withdraw">
                <div class="stat-card-icon">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Withdrawals</span>
                    <span class="stat-card-value"><?php echo formatCurrency($total_withdrawals); ?></span>
                    <span class="stat-card-sub">
                        <i class="fas fa-list"></i>
                        <?php echo number_format($total_withdrawal_count); ?> transactions
                    </span>
                </div>
            </div>
            
            <!-- Total Transactions -->
            <div class="stat-card stat-card-count">
                <div class="stat-card-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Total Transactions</span>
                    <span class="stat-card-value"><?php echo number_format(count($transactions)); ?></span>
                    <span class="stat-card-sub">
                        <i class="fas fa-users"></i>
                        By <?php echo count($employee_summary); ?> employee(s)
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================
        EMPLOYEE SUMMARY
        ============================================================ -->
        <?php if (count($employee_summary) > 0): ?>
        <div class="employee-summary-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-users"></i>
                    Employees Who Made Transactions
                    <span class="section-count"><?php echo count($employee_summary); ?></span>
                </h2>
            </div>
            <div class="employee-grid">
                <?php foreach ($employee_summary as $emp): ?>
                    <div class="employee-card">
                        <div class="employee-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="employee-info">
                            <span class="employee-name"><?php echo htmlspecialchars($emp['name']); ?></span>
                            <div class="employee-stats">
                                <?php if ($emp['deposits'] > 0): ?>
                                    <span class="employee-stat deposit-stat">
                                        <i class="fas fa-arrow-down"></i>
                                        <?php echo formatCurrency($emp['deposits']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($emp['withdrawals'] > 0): ?>
                                    <span class="employee-stat withdrawal-stat">
                                        <i class="fas fa-arrow-up"></i>
                                        <?php echo formatCurrency($emp['withdrawals']); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="employee-stat count-stat">
                                    <i class="fas fa-list"></i>
                                    <?php echo $emp['count']; ?> txn
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        TRANSACTIONS LIST
        ============================================================ -->
        <div class="transactions-section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-history"></i>
                    All Transactions
                    <span class="section-count"><?php echo count($transactions); ?></span>
                </h2>
                <div class="transactions-filters">
                    <button type="button" class="filter-btn active" onclick="filterTransactions('all', this)">
                        <i class="fas fa-list"></i> All
                    </button>
                    <button type="button" class="filter-btn filter-btn-deposit" onclick="filterTransactions('deposit', this)">
                        <i class="fas fa-arrow-down"></i> Deposits
                    </button>
                    <button type="button" class="filter-btn filter-btn-withdrawal" onclick="filterTransactions('withdrawal', this)">
                        <i class="fas fa-arrow-up"></i> Withdrawals
                    </button>
                </div>
            </div>

            <?php if (count($transactions) > 0): ?>
                <!-- Search Bar -->
                <div class="transactions-search-bar">
                    <div class="search-input-group">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="txnSearchInput" 
                               placeholder="Search by transaction #, reference, employee..."
                               oninput="searchTransactions(this)">
                        <button type="button" id="txnSearchClear" onclick="clearTxnSearch()" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <span class="txn-count" id="txnCount"><?php echo count($transactions); ?> transactions</span>
                </div>

                <div class="transactions-list" id="transactionsList">
                    <?php foreach ($transactions as $t): 
                        $is_deposit = $t['transaction_type'] === 'deposit';
                        $amount = floatval($t['amount'] ?? 0);
                        $created = $t['created_at'] ?? $t['transaction_date'];
                        $txn_date = date('d M Y', strtotime($created));
                        $txn_time = date('h:i A', strtotime($created));
                        
                        $search_text = strtolower(
                            ($t['transaction_number'] ?? '') . ' ' .
                            ($t['reference_number'] ?? '') . ' ' .
                            ($t['employee_name'] ?? '') . ' ' .
                            ($t['description'] ?? '') . ' ' .
                            $amount
                        );
                    ?>
                        <div class="txn-item txn-item-<?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>"
                             data-type="<?php echo $t['transaction_type']; ?>"
                             data-search="<?php echo htmlspecialchars($search_text); ?>">
                            
                            <!-- Icon -->
                            <div class="txn-item-icon">
                                <i class="fas fa-arrow-<?php echo $is_deposit ? 'down' : 'up'; ?>"></i>
                            </div>
                            
                            <!-- Main Content -->
                            <div class="txn-item-content">
                                <div class="txn-item-top">
                                    <div class="txn-item-left">
                                        <span class="txn-badge txn-badge-<?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                                            <?php echo $is_deposit ? 'DEPOSIT' : 'WITHDRAWAL'; ?>
                                        </span>
                                        <span class="txn-number"><?php echo htmlspecialchars($t['transaction_number']); ?></span>
                                    </div>
                                    <div class="txn-item-amount txn-amount-<?php echo $is_deposit ? 'deposit' : 'withdrawal'; ?>">
                                        <?php echo $is_deposit ? '+' : '-'; ?>
                                        <?php echo formatCurrency($amount); ?>
                                    </div>
                                </div>
                                
                                <div class="txn-item-bottom">
                                    <div class="txn-meta">
                                        <span class="txn-meta-item">
                                            <i class="far fa-calendar"></i>
                                            <?php echo $txn_date; ?>
                                        </span>
                                        <span class="txn-meta-item">
                                            <i class="far fa-clock"></i>
                                            <?php echo $txn_time; ?>
                                        </span>
                                        <span class="txn-meta-item txn-employee">
                                            <i class="fas fa-user-circle"></i>
                                            <strong><?php echo htmlspecialchars($t['employee_name'] ?? 'N/A'); ?></strong>
                                        </span>
                                        <?php if (!empty($t['reference_number'])): ?>
                                            <span class="txn-meta-item">
                                                <i class="fas fa-hashtag"></i>
                                                <?php echo htmlspecialchars($t['reference_number']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($t['description'])): ?>
                                        <div class="txn-description">
                                            <i class="fas fa-comment-alt"></i>
                                            <?php echo htmlspecialchars($t['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($t['notes'])): ?>
                                        <div class="txn-notes">
                                            <i class="fas fa-info-circle"></i>
                                            <?php echo htmlspecialchars($t['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Status -->
                            <div class="txn-item-status">
                                <span class="status-badge status-<?php echo htmlspecialchars($t['status'] ?? 'approved'); ?>">
                                    <i class="fas fa-check-circle"></i>
                                    <?php echo ucfirst($t['status'] ?? 'approved'); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="no-results" id="noResults" style="display:none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>No transactions found</h3>
                    <p>No transactions match your search.</p>
                    <button type="button" class="btn btn-secondary" onclick="clearTxnSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No transactions yet</h3>
                    <p>Provider huyu hajafanya transactions yoyote bado.</p>
                    <a href="index.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                </div>
            <?php endif; ?>
        </div>

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
    --shadow-hover: rgba(0,0,0,0.12);
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

/* ============================================================
   PROVIDER HEADER CARD
   ============================================================ */
.provider-header-card {
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
    position: relative;
    overflow: hidden;
    color: #FFFFFF;
}

.provider-header-card::before {
    content: '';
    position: absolute;
    top: -60%;
    right: -5%;
    width: 300px;
    height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}

.provider-header-card::after {
    content: '';
    position: absolute;
    bottom: -50%;
    left: 10%;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    pointer-events: none;
}

.provider-header-left {
    display: flex;
    align-items: center;
    gap: 18px;
    min-width: 0;
    flex: 1;
    position: relative;
    z-index: 1;
}

.provider-header-icon {
    width: 68px;
    height: 68px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

.provider-header-info {
    min-width: 0;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.provider-header-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.75);
}

.provider-header-name {
    font-size: 26px;
    font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.3px;
    line-height: 1.1;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}

.provider-header-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.provider-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(4px);
}

.provider-meta-item i {
    font-size: 11px;
    opacity: 0.9;
}

.btn-back-header {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.18);
    color: #FFFFFF;
    border-radius: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    transition: all 0.25s ease;
    backdrop-filter: blur(8px);
    position: relative;
    z-index: 1;
    white-space: nowrap;
}

.btn-back-header:hover {
    background: #FFFFFF;
    color: #1F2937;
    transform: translateX(-4px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}

/* ============================================================
   STATS GRID
   ============================================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px var(--shadow-color);
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--shadow-hover);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
}

.stat-card-float::before { background: #1D4ED8; }
.stat-card-deposit::before { background: #059669; }
.stat-card-withdraw::before { background: #DC2626; }
.stat-card-count::before { background: #7C3AED; }

.stat-card-icon {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.stat-card-float .stat-card-icon {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}

.stat-card-deposit .stat-card-icon {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border: 1.5px solid #6EE7B7;
}

.stat-card-withdraw .stat-card-icon {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #DC2626;
    border: 1.5px solid #FCA5A5;
}

.stat-card-count .stat-card-icon {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    border: 1.5px solid #C4B5FD;
}

html.dark-mode .stat-card-float .stat-card-icon { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .stat-card-deposit .stat-card-icon { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }
html.dark-mode .stat-card-withdraw .stat-card-icon { background: linear-gradient(135deg, #7F1D1D, #991B1B); color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .stat-card-count .stat-card-icon { background: linear-gradient(135deg, #2D1B5F, #4C1D95); color: #C4B5FD; border-color: #A78BFA; }

.stat-card-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.stat-card-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-card-value {
    font-size: 20px;
    font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
}

.stat-card-sub {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-card-sub i {
    font-size: 10px;
    color: var(--text-light);
}

/* ============================================================
   SECTION HEADER
   ============================================================ */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.section-header h2 {
    font-size: 17px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    letter-spacing: 0.2px;
}

.section-header h2 i {
    color: #2563EB;
    font-size: 18px;
}

.section-count {
    font-size: 12px;
    font-weight: 800;
    background: #DBEAFE;
    color: #1D4ED8;
    padding: 4px 12px;
    border-radius: 12px;
    margin-left: 6px;
}

html.dark-mode .section-count {
    background: #1E3A5F;
    color: #60A5FA;
}

/* ============================================================
   EMPLOYEE SUMMARY
   ============================================================ */
.employee-summary-section {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 20px 22px;
    margin-bottom: 20px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.employee-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.employee-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--bg-input);
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    transition: all 0.25s ease;
}

.employee-card:hover {
    border-color: #2563EB;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
}

.employee-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.employee-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.employee-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.employee-stats {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.employee-stat {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.employee-stat i {
    font-size: 9px;
}

.employee-stat.deposit-stat {
    background: #DCFCE7;
    color: #15803D;
    border: 1px solid #BBF7D0;
}

.employee-stat.withdrawal-stat {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

.employee-stat.count-stat {
    background: #DBEAFE;
    color: #1D4ED8;
    border: 1px solid #BFDBFE;
}

html.dark-mode .employee-stat.deposit-stat { background: #14532D; color: #4ADE80; border-color: #16A34A; }
html.dark-mode .employee-stat.withdrawal-stat { background: #7F1D1D; color: #FCA5A5; border-color: #DC2626; }
html.dark-mode .employee-stat.count-stat { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }

/* ============================================================
   TRANSACTIONS SECTION
   ============================================================ */
.transactions-section {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 20px 22px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
}

.transactions-filters {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 7px 14px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-input);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.25s ease;
    font-family: 'Inter', sans-serif;
}

.filter-btn:hover {
    background: var(--bg-body);
    border-color: #94A3B8;
}

.filter-btn.active {
    background: linear-gradient(135deg, #1E40AF, #2563EB);
    color: #FFFFFF;
    border-color: #2563EB;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.filter-btn-deposit.active {
    background: linear-gradient(135deg, #059669, #10B981);
    border-color: #10B981;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.filter-btn-withdrawal.active {
    background: linear-gradient(135deg, #DC2626, #EF4444);
    border-color: #EF4444;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}

/* Search Bar */
.transactions-search-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.search-input-group {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    padding: 8px 14px;
    flex: 1;
    max-width: 500px;
    transition: all 0.25s ease;
}

.search-input-group:focus-within {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.search-input-group > i {
    color: #2563EB;
    font-size: 13px;
}

.search-input-group input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    font-family: 'Inter', sans-serif;
    min-width: 0;
}

.search-input-group input::placeholder {
    color: var(--text-light);
}

.search-input-group button {
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

.search-input-group button:hover {
    background: #DC2626;
    color: #FFFFFF;
}

.txn-count {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    background: var(--bg-input);
    padding: 8px 16px;
    border-radius: 10px;
    border: 1.5px solid var(--border-color);
    white-space: nowrap;
}

/* ============================================================
   TRANSACTION ITEM
   ============================================================ */
.transactions-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.txn-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 16px 18px;
    background: var(--bg-input);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    position: relative;
    overflow: hidden;
    transition: all 0.25s ease;
}

.txn-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    width: 5px;
    height: 100%;
}

.txn-item-deposit::before { background: #10B981; }
.txn-item-withdrawal::before { background: #EF4444; }

.txn-item:hover {
    background: var(--bg-card);
    transform: translateX(4px);
    box-shadow: 0 6px 20px var(--shadow-color);
}

.txn-item.hidden-by-filter {
    display: none !important;
}

.txn-item-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    border: 2px solid;
}

.txn-item-deposit .txn-item-icon {
    background: linear-gradient(135deg, #DCFCE7, #BBF7D0);
    color: #15803D;
    border-color: #10B981;
}

.txn-item-withdrawal .txn-item-icon {
    background: linear-gradient(135deg, #FEE2E2, #FECACA);
    color: #991B1B;
    border-color: #EF4444;
}

html.dark-mode .txn-item-deposit .txn-item-icon {
    background: linear-gradient(135deg, #14532D, #166534);
    color: #4ADE80;
}

html.dark-mode .txn-item-withdrawal .txn-item-icon {
    background: linear-gradient(135deg, #7F1D1D, #991B1B);
    color: #FCA5A5;
}

.txn-item-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.txn-item-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.txn-item-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    min-width: 0;
}

.txn-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    white-space: nowrap;
}

.txn-badge-deposit {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #10B981;
}

.txn-badge-withdrawal {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #EF4444;
}

html.dark-mode .txn-badge-deposit { background: #14532D; color: #4ADE80; border-color: #10B981; }
html.dark-mode .txn-badge-withdrawal { background: #7F1D1D; color: #FCA5A5; border-color: #EF4444; }

.txn-number {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    font-weight: 800;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 4px 12px;
    border-radius: 8px;
    white-space: nowrap;
}

html.dark-mode .txn-number { background: #1E3A5F; color: #60A5FA; }

.txn-item-amount {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 20px;
    font-weight: 900;
    white-space: nowrap;
    letter-spacing: -0.3px;
}

.txn-amount-deposit { color: #15803D; }
.txn-amount-withdrawal { color: #991B1B; }
html.dark-mode .txn-amount-deposit { color: #4ADE80; }
html.dark-mode .txn-amount-withdrawal { color: #FCA5A5; }

.txn-item-bottom {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.txn-meta {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.txn-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.txn-meta-item i {
    font-size: 10px;
    color: var(--text-muted);
}

.txn-employee {
    background: #FEF3C7;
    color: #92400E;
    padding: 3px 10px;
    border-radius: 8px;
    font-weight: 700;
    border: 1px solid #FDE68A;
}

.txn-employee i {
    color: #D97706;
    font-size: 11px;
}

html.dark-mode .txn-employee { background: #5F3A1E; color: #FBBF24; border-color: #F59E0B; }
html.dark-mode .txn-employee i { color: #FBBF24; }

.txn-description,
.txn-notes {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.5;
    padding: 6px 10px;
    background: var(--bg-card);
    border-radius: 8px;
    border-left: 3px solid #2563EB;
}

.txn-description i,
.txn-notes i {
    font-size: 11px;
    color: #2563EB;
    margin-top: 2px;
    flex-shrink: 0;
}

.txn-notes {
    border-left-color: #F59E0B;
}

.txn-notes i {
    color: #F59E0B;
}

.txn-item-status {
    flex-shrink: 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}

.status-badge.status-approved {
    background: #D1FAE5;
    color: #065F46;
    border: 1.5px solid #10B981;
}

.status-badge.status-pending {
    background: #FEF3C7;
    color: #92400E;
    border: 1.5px solid #F59E0B;
}

.status-badge.status-rejected,
.status-badge.status-cancelled {
    background: #FEE2E2;
    color: #991B1B;
    border: 1.5px solid #EF4444;
}

html.dark-mode .status-badge.status-approved { background: #065F46; color: #D1FAE5; }
html.dark-mode .status-badge.status-pending { background: #5F3A1E; color: #FBBF24; }
html.dark-mode .status-badge.status-rejected,
html.dark-mode .status-badge.status-cancelled { background: #7F1D1D; color: #FEE2E2; }

/* ============================================================
   EMPTY / NO RESULTS
   ============================================================ */
.empty-state, .no-results {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i, .no-results i {
    font-size: 64px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}

.empty-state h3, .no-results h3 {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.empty-state p, .no-results p {
    font-size: 14px;
    color: var(--text-muted);
    margin: 0 0 20px 0;
}

.btn {
    padding: 10px 22px;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
}

.btn-primary {
    background: linear-gradient(135deg, #1E40AF 0%, #2563EB 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
    color: #FFFFFF;
}

.btn-secondary {
    background: var(--bg-input);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.btn-secondary:hover {
    background: var(--bg-body);
    color: var(--text-primary);
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .provider-header-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px;
    }
    
    .provider-header-name {
        font-size: 20px;
    }
    
    .provider-header-icon {
        width: 56px;
        height: 56px;
        font-size: 22px;
    }
    
    .btn-back-header {
        width: 100%;
        justify-content: center;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .transactions-filters {
        width: 100%;
    }
    
    .filter-btn {
        flex: 1;
        justify-content: center;
    }
    
    .txn-item {
        flex-direction: column;
        gap: 12px;
        padding: 14px;
    }
    
    .txn-item-top {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .txn-item-amount {
        font-size: 17px;
    }
    
    .txn-meta {
        gap: 8px;
    }
    
    .transactions-search-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-input-group {
        max-width: 100%;
    }
    
    .txn-count {
        text-align: center;
    }
}

@media (max-width: 480px) {
    .provider-header-name {
        font-size: 17px;
    }
    
    .provider-meta-item {
        font-size: 10px;
        padding: 3px 9px;
    }
    
    .stat-card-value {
        font-size: 17px;
    }
    
    .stat-card-icon {
        width: 42px;
        height: 42px;
        font-size: 17px;
    }
    
    .employee-grid {
        grid-template-columns: 1fr;
    }
    
    .txn-number {
        font-size: 10px;
        padding: 3px 8px;
    }
    
    .txn-badge {
        font-size: 9px;
        padding: 3px 9px;
    }
    
    .txn-item-amount {
        font-size: 15px;
    }
}
</style>

<script>
// ============================================================
// FILTER TRANSACTIONS (All / Deposit / Withdrawal)
// ============================================================
function filterTransactions(type, btn) {
    // Update active button
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    const items = document.querySelectorAll('.txn-item');
    let visibleCount = 0;
    
    items.forEach(item => {
        const itemType = item.getAttribute('data-type');
        if (type === 'all' || itemType === type) {
            item.classList.remove('hidden-by-filter');
            visibleCount++;
        } else {
            item.classList.add('hidden-by-filter');
        }
    });
    
    // Update count
    const countEl = document.getElementById('txnCount');
    if (countEl) {
        countEl.textContent = visibleCount + ' transactions';
    }
    
    // Show/hide no results
    const noResults = document.getElementById('noResults');
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
    
    // Clear search when filtering
    const searchInput = document.getElementById('txnSearchInput');
    if (searchInput) {
        searchInput.value = '';
        const clearBtn = document.getElementById('txnSearchClear');
        if (clearBtn) clearBtn.style.display = 'none';
    }
}

// ============================================================
// SEARCH TRANSACTIONS
// ============================================================
function searchTransactions(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const items = document.querySelectorAll('.txn-item');
    const clearBtn = document.getElementById('txnSearchClear');
    const countEl = document.getElementById('txnCount');
    const noResults = document.getElementById('noResults');
    
    if (clearBtn) {
        clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    }
    
    // Check active filter
    const activeFilter = document.querySelector('.filter-btn.active');
    const activeType = activeFilter ? activeFilter.textContent.trim().toLowerCase() : 'all';
    
    let visibleCount = 0;
    
    items.forEach(item => {
        const itemType = item.getAttribute('data-type');
        const searchData = item.getAttribute('data-search') || '';
        
        // Check filter
        let matchesFilter = true;
        if (activeType.includes('deposit')) matchesFilter = itemType === 'deposit';
        else if (activeType.includes('withdrawal')) matchesFilter = itemType === 'withdrawal';
        
        // Check search
        let matchesSearch = true;
        if (searchTerm.length > 0) {
            matchesSearch = searchData.includes(searchTerm);
        }
        
        if (matchesFilter && matchesSearch) {
            item.classList.remove('hidden-by-filter');
            visibleCount++;
        } else {
            item.classList.add('hidden-by-filter');
        }
    });
    
    if (countEl) {
        countEl.textContent = visibleCount + ' transactions';
    }
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

function clearTxnSearch() {
    const input = document.getElementById('txnSearchInput');
    if (input) {
        input.value = '';
        searchTransactions(input);
        input.focus();
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + K - Focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const input = document.getElementById('txnSearchInput');
        if (input) {
            input.focus();
            input.select();
        }
    }
    
    // Escape - Clear search
    if (e.key === 'Escape') {
        const input = document.getElementById('txnSearchInput');
        if (input && input.value.length > 0) {
            clearTxnSearch();
        }
    }
});

// ============================================================
// DARK MODE SYNC
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    function syncDarkMode() {
        var html = document.documentElement;
        var isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) html.classList.add('dark-mode');
        else html.classList.remove('dark-mode');
    }
    syncDarkMode();
    document.addEventListener('darkModeChanged', function(e) { syncDarkMode(); });
    
    console.log('%c📊 View Provider Transactions', 'font-size:16px; font-weight:bold; color:#2563EB;');
    console.log('%cProvider: <?php echo htmlspecialchars($provider["provider_name"]); ?>', 'font-size:13px; color:#2563EB;');
    console.log('%cTotal Transactions: <?php echo count($transactions); ?>', 'font-size:13px; color:#059669;');
    console.log('%cEmployees: <?php echo count($employee_summary); ?>', 'font-size:13px; color:#7C3AED;');
});
</script>

</body>
</html>