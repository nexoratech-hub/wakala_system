<?php
// ================================================================
// FILE: modules/commissions/view_provider_employee.php
// WAKALA FINANCIAL SYSTEM - VIEW PROVIDER COMMISSIONS (EMPLOYEE)
// ✅ Shows employee's OWN commissions for a specific provider
// ✅ Employee sees ONLY their own transactions
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
$user_id = $_SESSION['user_id'];

if ($role !== 'employee') {
    header('Location: ../dashboard/index.php');
    exit();
}

// ============================================================
// GET PARAMETERS
// ============================================================
$provider_id = isset($_GET['provider_id']) ? intval($_GET['provider_id']) : 0;
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

if ($provider_id <= 0 || $branch_id <= 0) {
    $_SESSION['error_message'] = 'Invalid provider or branch.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET EMPLOYEE DATA
// ============================================================
$stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$user_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee || intval($employee['branch_id']) !== $branch_id) {
    $_SESSION['error_message'] = 'Access denied.';
    header('Location: index_employee.php');
    exit();
}

// ============================================================
// GET PROVIDER INFO
// ============================================================
$stmt = $db->prepare("SELECT * FROM providers WHERE id = ? AND is_active = 1");
$stmt->execute([$provider_id]);
$provider = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$provider) {
    $_SESSION['error_message'] = 'Provider not found.';
    header('Location: index_employee.php');
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
$branch_location = $branch['location'] ?? '';

// Branch provider code
$stmt = $db->prepare("SELECT provider_code FROM branch_providers WHERE branch_id = ? AND provider_id = ? LIMIT 1");
$stmt->execute([$branch_id, $provider_id]);
$bp = $stmt->fetch(PDO::FETCH_ASSOC);
$provider_branch_code = $bp['provider_code'] ?? $provider['provider_code'];

// ============================================================
// ✅ GET ALL MY COMMISSIONS FOR THIS PROVIDER
// ============================================================
$my_commissions = [];
$total_amount = 0;

try {
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.commission_number,
            c.commission_date,
            c.created_at,
            c.notes,
            CAST(JSON_EXTRACT(c.provider_data, CONCAT('$.\"', ?, '\"')) AS DECIMAL(15,2)) as provider_amount
        FROM commissions c
        WHERE c.branch_id = ?
        AND c.employee_id = ?
        AND JSON_EXTRACT(c.provider_data, CONCAT('$.\"', ?, '\"')) IS NOT NULL
        ORDER BY c.commission_date DESC, c.id DESC
    ");
    $stmt->execute([$provider_id, $branch_id, $user_id, $provider_id]);
    $my_commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($my_commissions as $c) {
        $total_amount += floatval($c['provider_amount'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $my_commissions = [];
}

$total_count = count($my_commissions);

// ============================================================
// GET LAST COMMISSION DATE
// ============================================================
$last_date = null;
if (!empty($my_commissions)) {
    $last_date = $my_commissions[0]['commission_date'];
}

include_once '../../includes/employee_header.php';
include_once '../../includes/employee_sidebar.php';
include_once '../../includes/employee_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- PROVIDER HEADER CARD -->
        <div class="provider-header-card" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?> 0%, <?php echo htmlspecialchars($provider['color_code'] ?? '#0B5ED7'); ?>dd 100%);">
            <div class="provider-header-left">
                <div class="provider-header-icon">
                    <i class="<?php echo htmlspecialchars($provider['icon_class'] ?? 'fas fa-university'); ?>"></i>
                </div>
                <div class="provider-header-info">
                    <span class="provider-header-label">My Commissions For</span>
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
                        <?php if ($branch_location): ?>
                            <span class="provider-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?php echo htmlspecialchars($branch_location); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="provider-header-right">
                <a href="index_employee.php" class="btn-back-header">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to List</span>
                </a>
            </div>
        </div>

        <!-- INFO BAR -->
        <div class="info-bar-employee">
            <div class="ibe-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="ibe-content">
                <span class="ibe-text">
                    Showing <strong>MY</strong> commissions for <strong><?php echo htmlspecialchars($provider['provider_name']); ?></strong>
                    at <strong><?php echo htmlspecialchars($branch_name); ?></strong>.
                </span>
            </div>
        </div>

        <!-- 3 SUMMARY CARDS -->
        <div class="stats-grid">
            <div class="stat-card stat-card-amount">
                <div class="stat-card-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">My Total Commission</span>
                    <span class="stat-card-value"><?php echo formatCurrency($total_amount); ?></span>
                    <span class="stat-card-sub">
                        <i class="fas fa-chart-line"></i> From this provider
                    </span>
                </div>
            </div>
            
            <div class="stat-card stat-card-count">
                <div class="stat-card-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Transactions</span>
                    <span class="stat-card-value"><?php echo number_format($total_count); ?></span>
                    <span class="stat-card-sub">
                        <i class="fas fa-list"></i> Times I entered
                    </span>
                </div>
            </div>
            
            <div class="stat-card stat-card-date">
                <div class="stat-card-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-card-content">
                    <span class="stat-card-label">Last Commission</span>
                    <span class="stat-card-value">
                        <?php echo $last_date ? date('d M Y', strtotime($last_date)) : '-'; ?>
                    </span>
                    <span class="stat-card-sub">
                        <i class="fas fa-clock"></i> Most recent
                    </span>
                </div>
            </div>
        </div>

        <!-- TRANSACTIONS SECTION -->
        <div class="transactions-section">
            <div class="transactions-header-red">
                <div class="thr-left">
                    <h3>
                        <i class="fas fa-history"></i>
                        My Commission History
                        <span class="section-count-red"><?php echo $total_count; ?></span>
                    </h3>
                </div>
                <div class="thr-center">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="txnSearchInput" 
                               placeholder="Search reference, date..."
                               oninput="filterTransactions(this)">
                        <button type="button" id="txnSearchClear" onclick="clearTxnSearch()" style="display:none;">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count" id="txnSearchCount" style="display:none;">0</span>
                    </div>
                </div>
                <div class="thr-right">
                    <span class="record-count-red">
                        <i class="fas fa-coins"></i>
                        Total: <?php echo formatCurrency($total_amount); ?>
                    </span>
                </div>
            </div>

            <?php if ($total_count > 0): ?>
                <div class="transactions-list" id="transactionsList">
                    <?php foreach ($my_commissions as $txn): 
                        $amount = floatval($txn['provider_amount'] ?? 0);
                        $txn_date = $txn['commission_date'];
                        
                        $search_text = strtolower(
                            ($txn['commission_number'] ?? '') . ' ' .
                            ($txn['notes'] ?? '') . ' ' .
                            $txn_date . ' ' .
                            $amount
                        );
                    ?>
                        <div class="txn-item txn-item-commission" data-search="<?php echo htmlspecialchars($search_text); ?>">
                            
                            <!-- ICON -->
                            <div class="txn-item-icon">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            
                            <!-- CONTENT -->
                            <div class="txn-item-content">
                                <div class="txn-item-top">
                                    <div class="txn-item-left">
                                        <span class="txn-badge txn-badge-commission">
                                            COMMISSION
                                        </span>
                                        <span class="txn-number"><?php echo htmlspecialchars($txn['commission_number']); ?></span>
                                    </div>
                                    <div class="txn-item-amount">
                                        + <?php echo formatCurrency($amount); ?>
                                    </div>
                                </div>
                                
                                <div class="txn-item-bottom">
                                    <div class="txn-meta">
                                        <span class="txn-meta-item">
                                            <i class="far fa-calendar"></i>
                                            <?php echo date('d M Y', strtotime($txn_date)); ?>
                                        </span>
                                        <?php if (!empty($txn['created_at'])): ?>
                                            <span class="txn-meta-item">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('h:i A', strtotime($txn['created_at'])); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="txn-meta-item txn-employee">
                                            <i class="fas fa-user-circle"></i>
                                            <strong><?php echo htmlspecialchars($employee['full_name']); ?></strong>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($txn['notes'])): ?>
                                        <div class="txn-notes">
                                            <i class="fas fa-comment-alt"></i>
                                            <?php echo htmlspecialchars($txn['notes']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
                    <h3>No Commissions Yet</h3>
                    <p>You haven't added any commission for this provider yet.</p>
                    <a href="add_employee.php?branch_id=<?php echo $branch_id; ?>" class="btn btn-add-commission">
                        <i class="fas fa-plus-circle"></i> Add Commission
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php include_once '../../includes/employee_footer.php'; ?>
</div>

<style>
/* ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
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
.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    padding: 20px 24px !important;
}
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

:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-input: #f9fafb;
    --bg-hover: #f3f4f6;
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
    --bg-hover: #2d3a4f;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
}
body { background: var(--bg-body) !important; color: var(--text-primary); }
.main-wrapper, .main-content { background: var(--bg-body) !important; }

/* PROVIDER HEADER CARD */
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
    top: -60%; right: -5%;
    width: 300px; height: 300px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 50%;
    pointer-events: none;
}
.provider-header-left {
    display: flex; align-items: center; gap: 18px;
    min-width: 0; flex: 1;
    position: relative; z-index: 1;
}
.provider-header-icon {
    width: 68px; height: 68px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; color: #FFFFFF;
    flex-shrink: 0;
    backdrop-filter: blur(8px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}
.provider-header-info {
    min-width: 0; flex: 1;
    display: flex; flex-direction: column; gap: 4px;
}
.provider-header-label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1.5px;
    color: rgba(255, 255, 255, 0.75);
}
.provider-header-name {
    font-size: 26px; font-weight: 900;
    color: #FFFFFF;
    margin: 0;
    letter-spacing: 0.3px;
    line-height: 1.1;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}
.provider-header-meta {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; margin-top: 6px;
}
.provider-meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
    padding: 4px 12px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(4px);
}
.btn-back-header {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.18);
    color: #FFFFFF;
    border-radius: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    text-decoration: none;
    font-size: 13px; font-weight: 700;
    transition: all 0.25s ease;
    backdrop-filter: blur(8px);
    position: relative; z-index: 1;
    white-space: nowrap;
}
.btn-back-header:hover {
    background: #FFFFFF;
    color: #1F2937;
    transform: translateX(-4px);
}

/* INFO BAR */
.info-bar-employee {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #DBEAFE 0%, #BFDBFE 100%);
    border: 1.5px solid #93C5FD;
    border-radius: 12px;
    margin-bottom: 16px;
    color: #1E40AF;
}
.ibe-icon {
    width: 42px; height: 42px;
    background: rgba(30, 64, 175, 0.15);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #2563EB;
    flex-shrink: 0;
}
.ibe-content { flex: 1; min-width: 0; }
.ibe-text {
    font-size: 13px; font-weight: 600;
    color: #1E40AF; line-height: 1.5;
}
.ibe-text strong {
    background: rgba(30, 64, 175, 0.12);
    padding: 2px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: 800;
}
html.dark-mode .info-bar-employee {
    background: linear-gradient(135deg, #1E3A5F 0%, #1E40AF 100%);
    border-color: #3B82F6;
}
html.dark-mode .ibe-icon {
    background: rgba(96, 165, 250, 0.2);
    color: #60A5FA;
}
html.dark-mode .ibe-text { color: #93C5FD; }
html.dark-mode .ibe-text strong {
    background: rgba(96, 165, 250, 0.2);
}

/* STATS GRID */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}
.stat-card {
    background: var(--bg-card);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1.5px solid var(--border-color);
    display: flex; align-items: center; gap: 14px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px var(--shadow-color);
    position: relative; overflow: hidden;
    min-width: 0;
}
.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px var(--shadow-hover);
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
}
.stat-card-amount::before { background: #10B981; }
.stat-card-count::before { background: #3B82F6; }
.stat-card-date::before { background: #7C3AED; }

.stat-card-icon {
    width: 50px; height: 50px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.stat-card-amount .stat-card-icon {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border: 1.5px solid #6EE7B7;
}
.stat-card-count .stat-card-icon {
    background: linear-gradient(135deg, #DBEAFE, #BFDBFE);
    color: #1D4ED8;
    border: 1.5px solid #93C5FD;
}
.stat-card-date .stat-card-icon {
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    color: #7C3AED;
    border: 1.5px solid #C4B5FD;
}
html.dark-mode .stat-card-amount .stat-card-icon { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; border-color: #10B981; }
html.dark-mode .stat-card-count .stat-card-icon { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .stat-card-date .stat-card-icon { background: linear-gradient(135deg, #4C1D95, #5B21B6); color: #C4B5FD; border-color: #A78BFA; }

.stat-card-content {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 2px;
}
.stat-card-label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.8px;
    color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.stat-card-value {
    font-size: 22px; font-weight: 900;
    color: var(--text-primary);
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.15;
    word-break: break-word;
}
.stat-card-sub {
    font-size: 10px; font-weight: 600;
    color: var(--text-light);
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 2px;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.stat-card-sub i { font-size: 9px; }

/* TRANSACTIONS SECTION */
.transactions-section {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1.5px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* RED HEADER */
.transactions-header-red {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.transactions-header-red::before {
    content: '';
    position: absolute;
    top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%;
    pointer-events: none;
}
.thr-left {
    display: flex; align-items: center; justify-content: flex-start;
    position: relative; z-index: 1;
}
.thr-left h3 {
    font-size: 15px; font-weight: 800;
    color: #FFFFFF;
    margin: 0;
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap;
}
.thr-left h3 i { color: #FCD34D; font-size: 16px; }
.section-count-red {
    font-size: 11px; font-weight: 800;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    margin-left: auto;
}
.thr-center {
    display: flex; align-items: center; justify-content: center;
    position: relative; z-index: 1;
}
.thr-right {
    display: flex; align-items: center; justify-content: flex-end;
    position: relative; z-index: 1;
}

.search-wrapper {
    display: flex; align-items: center; gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    padding: 6px 12px;
    width: 280px; max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.search-wrapper i { color: #DC2626; font-size: 12px; flex-shrink: 0; }
.search-wrapper input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 12px;
    color: #1F2937; outline: none;
    min-width: 0; font-family: 'Inter', sans-serif;
}
.search-wrapper input::placeholder { color: #9CA3AF; font-size: 11px; }
.search-wrapper button {
    width: 20px; height: 20px;
    border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px;
}
.search-wrapper button:hover { background: #DC2626; color: white; }
.search-count {
    font-size: 10px; font-weight: 800;
    padding: 2px 8px;
    background: #FCD34D; color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
}

.record-count-red {
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px 14px;
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.record-count-red i { font-size: 11px; color: #FCD34D; }

/* TRANSACTIONS LIST */
.transactions-list {
    padding: 16px 20px;
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
    left: 0; top: 0;
    width: 5px; height: 100%;
}
.txn-item-commission::before { background: #10B981; }

.txn-item:hover {
    background: var(--bg-card);
    transform: translateX(4px);
    box-shadow: 0 6px 20px var(--shadow-color);
}
.txn-item.hidden-by-search { display: none !important; }

.txn-item-icon {
    width: 48px; height: 48px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    border: 2px solid;
}
.txn-item-commission .txn-item-icon {
    background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
    color: #059669;
    border-color: #10B981;
}
html.dark-mode .txn-item-commission .txn-item-icon {
    background: linear-gradient(135deg, #065F46, #047857);
    color: #34D399;
}

.txn-item-content {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 10px;
}

.txn-item-top {
    display: flex; align-items: center;
    justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
}
.txn-item-left {
    display: flex; align-items: center;
    gap: 10px; flex-wrap: wrap; min-width: 0;
}

.txn-badge {
    display: inline-flex; align-items: center;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 10px; font-weight: 800;
    letter-spacing: 1px;
    white-space: nowrap;
}
.txn-badge-commission {
    background: #DCFCE7;
    color: #15803D;
    border: 1.5px solid #10B981;
}
html.dark-mode .txn-badge-commission {
    background: #14532D;
    color: #4ADE80;
    border-color: #10B981;
}

.txn-number {
    font-family: 'Courier New', monospace;
    font-size: 12px; font-weight: 800;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 4px 12px;
    border-radius: 8px;
    white-space: nowrap;
}
html.dark-mode .txn-number {
    background: #1E3A5F;
    color: #60A5FA;
}

.txn-item-amount {
    font-family: 'Inter', 'Courier New', monospace;
    font-size: 20px; font-weight: 900;
    color: #15803D;
    white-space: nowrap;
    letter-spacing: -0.3px;
}
html.dark-mode .txn-item-amount { color: #4ADE80; }

.txn-item-bottom {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.txn-meta {
    display: flex; align-items: center;
    gap: 14px; flex-wrap: wrap;
}
.txn-meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}
.txn-meta-item i { font-size: 10px; color: var(--text-muted); }

.txn-employee {
    background: #FEF3C7;
    color: #92400E;
    padding: 3px 10px;
    border-radius: 8px;
    font-weight: 700;
    border: 1px solid #FDE68A;
}
.txn-employee i { color: #D97706; font-size: 11px; }
html.dark-mode .txn-employee {
    background: #5F3A1E;
    color: #FBBF24;
    border-color: #F59E0B;
}
html.dark-mode .txn-employee i { color: #FBBF24; }

.txn-notes {
    display: flex; align-items: flex-start;
    gap: 6px;
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.5;
    padding: 6px 10px;
    background: var(--bg-card);
    border-radius: 8px;
    border-left: 3px solid #2563EB;
}
.txn-notes i {
    font-size: 11px; color: #2563EB;
    margin-top: 2px; flex-shrink: 0;
}

/* NO RESULTS */
.no-results {
    text-align: center;
    padding: 60px 20px;
}
.no-results i {
    font-size: 64px;
    color: var(--text-light);
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.no-results h3 {
    font-size: 20px; font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.no-results p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 20px 0;
}

/* EMPTY STATE */
.empty-state {
    text-align: center;
    padding: 60px 20px;
}
.empty-state i {
    font-size: 64px;
    color: #10B981;
    opacity: 0.4;
    display: block;
    margin-bottom: 16px;
}
.empty-state h3 {
    font-size: 20px; font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}
.empty-state p {
    font-size: 14px; color: var(--text-muted);
    margin: 0 0 20px 0;
}

/* BUTTONS */
.btn {
    padding: 10px 22px;
    border: none; border-radius: 10px;
    font-weight: 700; font-size: 13px;
    cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    white-space: nowrap;
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
.btn-add-commission {
    background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}
.btn-add-commission:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
    color: #FFFFFF;
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .stats-grid { grid-template-columns: repeat(3, 1fr); }
    .stat-card-value { font-size: 20px; }
}
@media (max-width: 768px) {
    .provider-header-card {
        flex-direction: column; align-items: flex-start;
        padding: 20px;
    }
    .provider-header-name { font-size: 20px; }
    .provider-header-icon { width: 56px; height: 56px; font-size: 22px; }
    .btn-back-header { width: 100%; justify-content: center; }
    
    .stats-grid { grid-template-columns: 1fr; }
    
    .transactions-header-red {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .thr-left, .thr-center, .thr-right {
        justify-content: center;
        width: 100%;
    }
    .search-wrapper { width: 100%; }
    
    .txn-item { flex-direction: column; gap: 12px; padding: 14px; }
    .txn-item-top { flex-direction: column; align-items: flex-start; }
    .txn-item-amount { font-size: 17px; }
    .txn-meta { gap: 8px; }
}
@media (max-width: 480px) {
    .provider-header-name { font-size: 17px; }
    .provider-meta-item { font-size: 10px; padding: 3px 9px; }
    .stat-card { padding: 14px 16px; gap: 12px; }
    .stat-card-icon { width: 44px; height: 44px; font-size: 18px; }
    .stat-card-value { font-size: 18px; }
    .txn-item-amount { font-size: 15px; }
    .txn-number { font-size: 10px; padding: 3px 8px; }
    .txn-badge { font-size: 9px; padding: 3px 9px; }
}
</style>

<script>
// ============================================================
// SEARCH TRANSACTIONS
// ============================================================
function filterTransactions(input) {
    const searchTerm = input.value.toLowerCase().trim();
    const items = document.querySelectorAll('.txn-item');
    const clearBtn = document.getElementById('txnSearchClear');
    const countBadge = document.getElementById('txnSearchCount');
    const noResults = document.getElementById('noResults');
    
    if (clearBtn) clearBtn.style.display = searchTerm.length > 0 ? 'flex' : 'none';
    
    if (searchTerm.length === 0) {
        items.forEach(item => item.classList.remove('hidden-by-search'));
        if (countBadge) countBadge.style.display = 'none';
        if (noResults) noResults.style.display = 'none';
        return;
    }
    
    let matchCount = 0;
    items.forEach(item => {
        const searchData = item.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm)) {
            item.classList.remove('hidden-by-search');
            matchCount++;
        } else {
            item.classList.add('hidden-by-search');
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

function clearTxnSearch() {
    const input = document.getElementById('txnSearchInput');
    if (input) {
        input.value = '';
        filterTransactions(input);
        input.focus();
    }
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const input = document.getElementById('txnSearchInput');
        if (input) { input.focus(); input.select(); }
    }
    if (e.key === 'Escape') {
        const input = document.getElementById('txnSearchInput');
        if (input && input.value.length > 0 && document.activeElement === input) {
            clearTxnSearch();
        }
    }
});

// ============================================================
// DARK MODE
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
});
</script>
</body>
</html>