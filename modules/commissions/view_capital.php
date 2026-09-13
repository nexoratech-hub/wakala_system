<?php
// ================================================================
// FILE: modules/commissions/view_capital.php
// WAKALA FINANCIAL SYSTEM - VIEW CAPITAL DETAILS
// ✅ Shows capital addition details
// ✅ Shows provider breakdown (for float target)
// ✅ Action buttons: Edit | Delete | Back
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

if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: index.php');
    exit();
}

$capital_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($capital_id <= 0) {
    $_SESSION['error_message'] = 'Invalid capital ID.';
    header('Location: add_capital.php');
    exit();
}

// ============================================================
// GET CAPITAL ENTRY
// ============================================================
try {
    $stmt = $db->prepare("
        SELECT 
            c.*,
            emp.full_name as employee_name,
            emp.email as employee_email,
            b.branch_name,
            b.branch_code,
            b.location as branch_location
        FROM commissions c
        LEFT JOIN employees emp ON c.employee_id = emp.id
        LEFT JOIN branches b ON c.branch_id = b.id
        WHERE c.id = ? AND c.commission_number LIKE 'CAP-%'
    ");
    $stmt->execute([$capital_id]);
    $capital = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $capital = null;
}

if (!$capital) {
    $_SESSION['error_message'] = 'Capital entry not found.';
    header('Location: add_capital.php');
    exit();
}

// ============================================================
// PARSE PROVIDER DATA
// ============================================================
$provider_data = json_decode($capital['provider_data'] ?? '{}', true);
$capital_target = $provider_data['capital_target'] ?? 'float';
$entries = $provider_data['entries'] ?? [];

$target_label = ($capital_target === 'float') ? 'Provider Float' : 'Branch Cash';
$target_icon = ($capital_target === 'float') ? 'fa-university' : 'fa-money-bill-wave';
$target_class = ($capital_target === 'float') ? 'float' : 'cash';

// ============================================================
// GET PROVIDER DETAILS (if float target)
// ============================================================
$providers_breakdown = [];

if ($capital_target === 'float' && !empty($entries) && is_array($entries)) {
    foreach ($entries as $entry) {
        // Check if entry is an array (new format) or string (old format)
        if (is_array($entry) && isset($entry['provider_id'])) {
            $provider_id = intval($entry['provider_id']);
            $amount = floatval($entry['amount'] ?? 0);
            
            // Get provider info
            $stmt = $db->prepare("
                SELECT p.provider_name, p.provider_code, p.icon_class, p.color_code, p.provider_type
                FROM providers p
                WHERE p.id = ?
            ");
            $stmt->execute([$provider_id]);
            $provider_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($provider_info) {
                $providers_breakdown[] = [
                    'provider_id' => $provider_id,
                    'provider_name' => $provider_info['provider_name'],
                    'provider_code' => $provider_info['provider_code'],
                    'icon_class' => $provider_info['icon_class'] ?? 'fas fa-university',
                    'color_code' => $provider_info['color_code'] ?? '#3B82F6',
                    'provider_type' => $provider_info['provider_type'] ?? 'bank',
                    'amount' => $amount
                ];
            }
        } else {
            // Old format - just a string like "Provider Name: TSh X"
            // Try to parse it
            if (is_string($entry)) {
                $providers_breakdown[] = [
                    'provider_id' => 0,
                    'provider_name' => $entry,
                    'provider_code' => '-',
                    'icon_class' => 'fas fa-university',
                    'color_code' => '#6B7280',
                    'provider_type' => 'unknown',
                    'amount' => 0,
                    'raw_text' => $entry
                ];
            }
        }
    }
}

// ============================================================
// GET CURRENT STATUS FOR THIS BRANCH
// ============================================================
$current_capital = 0;
$current_float = 0;
$current_cash = 0;

$stmt = $db->prepare("
    SELECT current_cash, current_capital 
    FROM daily_reports 
    WHERE branch_id = ? 
    ORDER BY report_date DESC, id DESC 
    LIMIT 1
");
$stmt->execute([$capital['branch_id']]);
$dr = $stmt->fetch(PDO::FETCH_ASSOC);
if ($dr) {
    $current_cash = floatval($dr['current_cash'] ?? 0);
    $current_capital = floatval($dr['current_capital'] ?? 0);
}

$stmt = $db->prepare("
    SELECT COALESCE(SUM(drp.current_float), 0) as total_float
    FROM daily_report_providers drp
    INNER JOIN daily_reports dr ON drp.daily_report_id = dr.id
    WHERE dr.branch_id = ?
    AND dr.id = (SELECT MAX(id) FROM daily_reports WHERE branch_id = ?)
");
$stmt->execute([$capital['branch_id'], $capital['branch_id']]);
$current_float = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_float'] ?? 0);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <!-- BRANCH STATUS CARD -->
        <div class="branch-status-card">
            <div class="branch-status-icon">
                <i class="fas fa-store-alt"></i>
            </div>
            <div class="branch-status-info">
                <span class="branch-status-label">Capital Entry For</span>
                <span class="branch-status-name"><?php echo htmlspecialchars($capital['branch_name'] ?? 'Unknown'); ?></span>
                <?php if (!empty($capital['branch_code'])): ?>
                    <span class="branch-status-code"><?php echo htmlspecialchars($capital['branch_code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($capital['branch_location'])): ?>
                    <span class="branch-status-location">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars($capital['branch_location']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="add_capital.php?branch_id=<?php echo $capital['branch_id']; ?>" class="btn-back-card">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Capital</span>
            </a>
        </div>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-eye"></i> Capital Details</h2>
                <span class="page-subtitle">Reference: <?php echo htmlspecialchars($capital['commission_number']); ?></span>
            </div>
            <div class="page-header-right">
                <div class="header-actions">
                    <a href="edit_capital.php?id=<?php echo $capital_id; ?>" class="btn btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <a href="add_capital.php?delete_capital=<?php echo $capital_id; ?>&branch_id=<?php echo $capital['branch_id']; ?>" 
                       class="btn btn-delete" 
                       onclick="return confirmDeleteCapital('<?php echo addslashes($capital['commission_number']); ?>', '<?php echo formatCurrency($capital['allocated_amount']); ?>')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                    <a href="add_capital.php?branch_id=<?php echo $capital['branch_id']; ?>" class="btn btn-cancel">
                        <i class="fas fa-times"></i> Close
                    </a>
                </div>
            </div>
        </div>

        <!-- HERO CARD -->
        <div class="capital-hero-card">
            <div class="hero-icon hero-icon-<?php echo $target_class; ?>">
                <i class="fas <?php echo $target_icon; ?>"></i>
            </div>
            <div class="hero-content">
                <span class="hero-label">Capital Addition</span>
                <div class="hero-reference">
                    <i class="fas fa-hashtag"></i>
                    <?php echo htmlspecialchars($capital['commission_number']); ?>
                </div>
                <div class="hero-meta">
                    <span class="hero-meta-item">
                        <i class="far fa-calendar"></i>
                        <?php echo date('d M Y', strtotime($capital['commission_date'])); ?>
                    </span>
                    <span class="hero-meta-item">
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($capital['employee_name'] ?? 'N/A'); ?>
                    </span>
                    <span class="hero-meta-item target-badge target-<?php echo $target_class; ?>">
                        <i class="fas <?php echo $target_icon; ?>"></i>
                        <?php echo $target_label; ?>
                    </span>
                </div>
            </div>
            <div class="hero-amount">
                <span class="hero-amount-label">Amount Added</span>
                <span class="hero-amount-value">
                    <?php echo formatCurrency($capital['allocated_amount']); ?>
                </span>
            </div>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-cards-view">
            <div class="summary-view-card">
                <div class="svc-icon svc-icon-blue">
                    <i class="fas fa-university"></i>
                </div>
                <div class="svc-content">
                    <span class="svc-label">Current Float</span>
                    <span class="svc-value svc-blue"><?php echo formatCurrency($current_float); ?></span>
                </div>
            </div>
            
            <div class="summary-view-card">
                <div class="svc-icon svc-icon-green">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="svc-content">
                    <span class="svc-label">Current Cash</span>
                    <span class="svc-value svc-green"><?php echo formatCurrency($current_cash); ?></span>
                </div>
            </div>
            
            <div class="summary-view-card">
                <div class="svc-icon svc-icon-purple">
                    <i class="fas fa-vault"></i>
                </div>
                <div class="svc-content">
                    <span class="svc-label">Current Total Capital</span>
                    <span class="svc-value svc-purple"><?php echo formatCurrency($current_capital); ?></span>
                </div>
            </div>
        </div>

        <!-- PROVIDER BREAKDOWN (if float) -->
        <?php if ($capital_target === 'float' && count($providers_breakdown) > 0): ?>
            <div class="section-container-view">
                <div class="section-header-view">
                    <h3>
                        <i class="fas fa-university"></i>
                        Provider Breakdown
                        <span class="section-count-view"><?php echo count($providers_breakdown); ?></span>
                    </h3>
                </div>
                
                <div class="providers-grid-view">
                    <?php 
                    $total_providers_amount = 0;
                    foreach ($providers_breakdown as $pb): 
                        $total_providers_amount += $pb['amount'];
                    ?>
                        <div class="provider-view-card">
                            <div class="pvc-icon" style="background: <?php echo htmlspecialchars($pb['color_code']); ?>;">
                                <i class="<?php echo htmlspecialchars($pb['icon_class']); ?>"></i>
                            </div>
                            <div class="pvc-content">
                                <span class="pvc-name"><?php echo htmlspecialchars($pb['provider_name']); ?></span>
                                <?php if (!empty($pb['provider_code']) && $pb['provider_code'] !== '-'): ?>
                                    <span class="pvc-code"><?php echo htmlspecialchars($pb['provider_code']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="pvc-amount">
                                <?php if ($pb['amount'] > 0): ?>
                                    +<?php echo formatCurrency($pb['amount']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="providers-total-view">
                    <span class="ptv-label">
                        <i class="fas fa-calculator"></i> Total Added to Providers:
                    </span>
                    <span class="ptv-value">
                        <?php echo formatCurrency($total_providers_amount); ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- DETAILS SECTION -->
        <div class="section-container-view">
            <div class="section-header-view">
                <h3><i class="fas fa-info-circle"></i> Capital Entry Details</h3>
            </div>
            
            <div class="details-grid-view">
                <div class="detail-item-view">
                    <span class="div-label">Reference Number</span>
                    <span class="div-value div-highlight">
                        <?php echo htmlspecialchars($capital['commission_number']); ?>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Capital Date</span>
                    <span class="div-value">
                        <i class="far fa-calendar"></i>
                        <?php echo date('d M Y', strtotime($capital['commission_date'])); ?>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Branch</span>
                    <span class="div-value">
                        <i class="fas fa-store-alt"></i>
                        <?php echo htmlspecialchars($capital['branch_name'] ?? 'Main'); ?>
                        <?php if (!empty($capital['branch_code'])): ?>
                            (<?php echo htmlspecialchars($capital['branch_code']); ?>)
                        <?php endif; ?>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Added By</span>
                    <span class="div-value">
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($capital['employee_name'] ?? 'N/A'); ?>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Target</span>
                    <span class="div-value">
                        <span class="target-badge target-<?php echo $target_class; ?>">
                            <i class="fas <?php echo $target_icon; ?>"></i>
                            <?php echo $target_label; ?>
                        </span>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Amount Added</span>
                    <span class="div-value div-amount">
                        <i class="fas fa-arrow-up"></i>
                        <?php echo formatCurrency($capital['allocated_amount']); ?>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Created At</span>
                    <span class="div-value">
                        <i class="far fa-clock"></i>
                        <?php echo date('d M Y, h:i A', strtotime($capital['created_at'])); ?>
                    </span>
                </div>
                
                <div class="detail-item-view">
                    <span class="div-label">Last Updated</span>
                    <span class="div-value">
                        <i class="fas fa-sync-alt"></i>
                        <?php echo !empty($capital['updated_at']) ? date('d M Y, h:i A', strtotime($capital['updated_at'])) : '-'; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- NOTES SECTION -->
        <?php if (!empty($capital['notes'])): ?>
            <div class="section-container-view">
                <div class="section-header-view">
                    <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                </div>
                <div class="notes-content-view">
                    <?php 
                        $notes_text = str_replace('[CAPITAL ADDITION] ', '', $capital['notes']);
                        echo nl2br(htmlspecialchars($notes_text));
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- BOTTOM ACTIONS -->
        <div class="bottom-actions-view">
            <a href="edit_capital.php?id=<?php echo $capital_id; ?>" class="btn btn-edit btn-lg">
                <i class="fas fa-edit"></i> Edit This Capital
            </a>
            <a href="add_capital.php?delete_capital=<?php echo $capital_id; ?>&branch_id=<?php echo $capital['branch_id']; ?>" 
               class="btn btn-delete btn-lg" 
               onclick="return confirmDeleteCapital('<?php echo addslashes($capital['commission_number']); ?>', '<?php echo formatCurrency($capital['allocated_amount']); ?>')">
                <i class="fas fa-trash"></i> Delete
            </a>
            <a href="add_capital.php?branch_id=<?php echo $capital['branch_id']; ?>" class="btn btn-cancel btn-lg">
                <i class="fas fa-arrow-left"></i> Back to Capital List
            </a>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   GLOBAL
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
}
.main-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
}
.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    padding: 16px 20px !important;
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

/* BRANCH CARD */
.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    flex-wrap: wrap; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 50px; height: 50px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1; position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name { font-size: 18px; font-weight: 700; color: #FFFFFF; }
.branch-status-code {
    font-size: 11px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    font-family: 'Courier New', monospace;
}
.branch-status-location {
    display: flex; align-items: center; gap: 4px;
    font-size: 12px; color: rgba(255, 255, 255, 0.7);
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; }

/* PAGE HEADER */
.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; padding: 0 4px; flex-wrap: wrap; gap: 10px;
}
.page-header-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.page-header-left h2 {
    font-size: 20px; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.page-header-left h2 i { color: #7C3AED; margin-right: 8px; }
.page-subtitle {
    font-size: 13px; color: var(--text-muted);
    background: var(--bg-hover);
    padding: 3px 12px; border-radius: 12px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
}
.header-actions {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}

/* BUTTONS */
.btn {
    padding: 10px 20px; border-radius: 8px;
    font-weight: 600; font-size: 13px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
    white-space: nowrap;
}
.btn-edit { background: #F59E0B; color: white; }
.btn-edit:hover { background: #D97706; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); }
.btn-delete { background: #DC2626; color: white; }
.btn-delete:hover { background: #B91C1C; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4); }
.btn-cancel {
    background: var(--bg-card); color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-cancel:hover { background: var(--border-color); }
.btn-lg { padding: 12px 24px; font-size: 14px; }

/* HERO CARD */
.capital-hero-card {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 28px 32px;
    background: linear-gradient(135deg, #7C3AED 0%, #5B21B6 100%);
    border-radius: 16px;
    margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(124, 58, 237, 0.35);
    flex-wrap: wrap;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.capital-hero-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 400px; height: 400px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.hero-icon {
    width: 80px;
    height: 80px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    border: 2px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
}
.hero-icon-float { background: linear-gradient(135deg, #3B82F6, #1D4ED8); color: #FFFFFF; }
.hero-icon-cash { background: linear-gradient(135deg, #10B981, #059669); color: #FFFFFF; }

.hero-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    position: relative;
    z-index: 1;
}
.hero-label {
    font-size: 11px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    letter-spacing: 1.5px;
}
.hero-reference {
    font-size: 24px;
    font-weight: 900;
    color: #FFFFFF;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 8px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    word-break: break-word;
}
.hero-reference i {
    color: #FCD34D;
    font-size: 20px;
}
.hero-meta {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 4px;
}
.hero-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}
.hero-meta-item i { font-size: 11px; }

.hero-amount {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
    padding: 16px 24px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    border: 2px solid rgba(252, 211, 77, 0.3);
    backdrop-filter: blur(10px);
    position: relative;
    z-index: 1;
    min-width: 240px;
}
.hero-amount-label {
    font-size: 11px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase;
    letter-spacing: 1.2px;
}
.hero-amount-value {
    font-size: 28px;
    font-weight: 900;
    color: #FCD34D;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
    word-break: break-word;
}

/* TARGET BADGE */
.target-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
}
.target-float { 
    background: #DBEAFE; 
    color: #1E40AF; 
    border: 1.5px solid #93C5FD; 
}
.target-cash { 
    background: #DCFCE7; 
    color: #15803D; 
    border: 1.5px solid #86EFAC; 
}
html.dark-mode .target-float { background: #1E3A5F; color: #93C5FD; border-color: #3B82F6; }
html.dark-mode .target-cash { background: #14532D; color: #4ADE80; border-color: #16A34A; }

/* SUMMARY CARDS */
.summary-cards-view {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-view-card {
    background: var(--bg-card);
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    border: 1.5px solid var(--border-color);
    box-shadow: 0 2px 8px var(--shadow-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.summary-view-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px var(--shadow-hover);
}
.svc-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.svc-icon-blue { background: linear-gradient(135deg, #DBEAFE, #BFDBFE); color: #1D4ED8; }
.svc-icon-green { background: linear-gradient(135deg, #D1FAE5, #A7F3D0); color: #059669; }
.svc-icon-purple { background: linear-gradient(135deg, #EDE9FE, #DDD6FE); color: #7C3AED; }
html.dark-mode .svc-icon-blue { background: linear-gradient(135deg, #1E3A5F, #1E40AF); color: #60A5FA; }
html.dark-mode .svc-icon-green { background: linear-gradient(135deg, #065F46, #047857); color: #34D399; }
html.dark-mode .svc-icon-purple { background: linear-gradient(135deg, #4C1D95, #5B21B6); color: #C4B5FD; }

.svc-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
    flex: 1;
}
.svc-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.svc-value {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    word-break: break-word;
}
.svc-blue { color: #1D4ED8; }
.svc-green { color: #059669; }
.svc-purple { color: #7C3AED; }
html.dark-mode .svc-blue { color: #60A5FA; }
html.dark-mode .svc-green { color: #34D399; }
html.dark-mode .svc-purple { color: #C4B5FD; }

/* SECTION CONTAINER */
.section-container-view {
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px var(--shadow-color);
}
.section-header-view {
    padding: 16px 22px;
    background: linear-gradient(135deg, #7C3AED 0%, #5B21B6 100%);
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
}
.section-header-view::before {
    content: ''; position: absolute; top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.section-header-view h3 {
    font-size: 15px;
    font-weight: 800;
    color: #FFFFFF;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}
.section-header-view h3 i {
    color: #FCD34D;
    font-size: 16px;
}
.section-count-view {
    font-size: 11px;
    font-weight: 800;
    color: #FCD34D;
    padding: 3px 12px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    margin-left: auto;
}

/* PROVIDERS GRID */
.providers-grid-view {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    padding: 20px 22px;
}
.provider-view-card {
    background: var(--bg-input);
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    border: 1.5px solid var(--border-color);
    transition: all 0.3s ease;
    min-width: 0;
}
.provider-view-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--shadow-hover);
    border-color: #7C3AED;
}
.pvc-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-size: 16px;
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
}
.pvc-content {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    flex: 1;
}
.pvc-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pvc-code {
    font-size: 10px;
    font-weight: 700;
    color: #1D4ED8;
    background: #DBEAFE;
    padding: 2px 8px;
    border-radius: 6px;
    align-self: flex-start;
    font-family: 'Courier New', monospace;
}
html.dark-mode .pvc-code { background: #1E3A5F; color: #60A5FA; }
.pvc-amount {
    font-size: 14px;
    font-weight: 900;
    color: #059669;
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    text-align: right;
}
html.dark-mode .pvc-amount { color: #34D399; }

.providers-total-view {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 22px;
    background: linear-gradient(135deg, #EDE9FE, #DDD6FE);
    border-top: 2px solid #C4B5FD;
    flex-wrap: wrap;
    gap: 10px;
}
html.dark-mode .providers-total-view {
    background: linear-gradient(135deg, #4C1D95, #5B21B6);
    border-top-color: #A78BFA;
}
.ptv-label {
    font-size: 13px;
    font-weight: 700;
    color: #5B21B6;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ptv-label i { color: #7C3AED; }
.ptv-value {
    font-size: 20px;
    font-weight: 900;
    color: #5B21B6;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
}
html.dark-mode .ptv-label, html.dark-mode .ptv-value { color: #C4B5FD; }

/* DETAILS GRID */
.details-grid-view {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0;
    padding: 0;
}
.detail-item-view {
    padding: 16px 22px;
    border-bottom: 1px solid var(--border-color);
    border-right: 1px solid var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.detail-item-view:nth-child(2n) { border-right: none; }
.detail-item-view:nth-last-child(-n+2) { border-bottom: none; }

.div-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.div-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.div-value i {
    color: #7C3AED;
    font-size: 12px;
}
.div-highlight {
    color: #7C3AED;
    font-family: 'Courier New', monospace;
    font-weight: 800;
    font-size: 15px;
}
html.dark-mode .div-highlight { color: #C4B5FD; }
.div-amount {
    color: #059669;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    font-size: 16px;
}
html.dark-mode .div-amount { color: #34D399; }

/* NOTES */
.notes-content-view {
    padding: 18px 22px;
    font-size: 14px;
    color: var(--text-secondary);
    line-height: 1.7;
    white-space: pre-wrap;
    word-wrap: break-word;
    background: var(--bg-input);
    font-style: italic;
}

/* BOTTOM ACTIONS */
.bottom-actions-view {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
    padding: 20px;
    background: var(--bg-card);
    border-radius: 12px;
    border: 1.5px solid var(--border-color);
    margin-bottom: 20px;
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summary-cards-view { grid-template-columns: repeat(3, 1fr); }
    .capital-hero-card { padding: 22px 24px; }
    .hero-reference { font-size: 20px; }
    .hero-amount { min-width: 200px; }
    .hero-amount-value { font-size: 24px; }
}
@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 10px; padding: 14px 18px; }
    .branch-status-info { width: 100%; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-actions { width: 100%; flex-direction: column; }
    .header-actions .btn { width: 100%; justify-content: center; }
    
    .capital-hero-card { flex-direction: column; align-items: stretch; text-align: center; padding: 20px; }
    .hero-icon { align-self: center; width: 64px; height: 64px; font-size: 28px; }
    .hero-content { align-items: center; }
    .hero-reference { justify-content: center; font-size: 18px; }
    .hero-meta { justify-content: center; }
    .hero-amount { min-width: 100%; align-items: center; padding: 14px 18px; }
    
    .summary-cards-view { grid-template-columns: 1fr; }
    .details-grid-view { grid-template-columns: 1fr; }
    .detail-item-view { border-right: none !important; border-bottom: 1px solid var(--border-color); }
    .detail-item-view:last-child { border-bottom: none; }
    
    .providers-grid-view { grid-template-columns: 1fr; }
    .bottom-actions-view { flex-direction: column; }
    .bottom-actions-view .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .branch-status-name { font-size: 15px; }
    .branch-status-icon { width: 42px; height: 42px; font-size: 18px; }
    .page-header-left h2 { font-size: 17px; }
    .hero-reference { font-size: 16px; }
    .hero-amount-value { font-size: 22px; }
    .section-header-view h3 { font-size: 13px; }
    .svc-value { font-size: 16px; }
    .ptv-value { font-size: 18px; }
}
</style>

<script>
// ============================================================
// CONFIRM DELETE CAPITAL
// ============================================================
function confirmDeleteCapital(reference, amount) {
    var msg = 'Are you sure you want to DELETE this capital addition?\n\n' +
              'Reference: ' + reference + '\n' +
              'Amount: ' + amount + '\n\n' +
              'WARNING: This will REVERSE the capital addition from daily reports.\n' +
              'The branch capital will be reduced by this amount.\n\n' +
              'This action cannot be undone.';
    return confirm(msg);
}

// ============================================================
// INITIALIZE
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