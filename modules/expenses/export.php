<?php
// ================================================================
// FILE: modules/expenses/export.php
// WAKALA FINANCIAL SYSTEM - EXPORT EXPENSES
// ✅ CSV, Excel, PDF, Print
// ✅ RED theme
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

$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'];

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$selected_branch = 0;
if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && intval($_GET['branch_id']) > 0) {
    $selected_branch = intval($_GET['branch_id']);
} elseif (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== '0') {
    $selected_branch = intval($_GET['branch']);
}

// Company info
$company_name = 'Wakala System';
$company_address = '';
$company_phone = '';
$company_email = '';

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_address', 'company_phone', 'company_email')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings as $s) {
        if ($s['setting_key'] === 'company_name') $company_name = $s['setting_value'];
        if ($s['setting_key'] === 'company_address') $company_address = $s['setting_value'];
        if ($s['setting_key'] === 'company_phone') $company_phone = $s['setting_value'];
        if ($s['setting_key'] === 'company_email') $company_email = $s['setting_value'];
    }
} catch (Exception $e) {}

// Branch info
$branch_info = null;
if ($selected_branch > 0) {
    $stmt = $db->prepare("SELECT * FROM branches WHERE id = ?");
    $stmt->execute([$selected_branch]);
    $branch_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get employee branch restriction
$employee_branch_restrict = 0;
if ($role === 'employee') {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $employee_branch_restrict = intval($emp['branch_id'] ?? 0);
}

// Build query
try {
    $sql = "SELECT 
                e.id, e.expense_number, e.expense_date, e.expense_name, e.category,
                e.amount, e.description, e.is_business_expense, e.notes, e.created_at,
                emp.full_name as employee_name,
                b.branch_name as branch_name, b.branch_code as branch_code
            FROM expenses e
            LEFT JOIN employees emp ON e.employee_id = emp.id
            LEFT JOIN branches b ON e.branch_id = b.id
            WHERE 1=1";
    $params = [];

    if ($selected_branch > 0) {
        $sql .= " AND e.branch_id = ?";
        $params[] = $selected_branch;
    }
    
    if ($role === 'employee' && $employee_branch_restrict > 0) {
        $sql .= " AND e.branch_id = ?";
        $params[] = $employee_branch_restrict;
    }

    if (!empty($from_date)) {
        $sql .= " AND e.expense_date >= ?";
        $params[] = $from_date;
    }
    if (!empty($to_date)) {
        $sql .= " AND e.expense_date <= ?";
        $params[] = $to_date;
    }

    if (!empty($search_query)) {
        $sql .= " AND (e.expense_number LIKE ? OR e.expense_name LIKE ? OR e.category LIKE ? OR e.description LIKE ? OR emp.full_name LIKE ?)";
        $search_like = '%' . $search_query . '%';
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
    }

    $sql .= " ORDER BY e.expense_date DESC, e.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $expenses = [];
}

// Calculate totals
$total_amount = 0;
$total_business = 0;
$total_personal = 0;
foreach ($expenses as $e) {
    $amt = floatval($e['amount']);
    $total_amount += $amt;
    if ($e['is_business_expense'] == 1) $total_business += $amt;
    else $total_personal += $amt;
}

// Logo
$logo_path = '../../assets/images/logo.PNG';
$logo_data_uri = '';
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_data);
}

// ============================================================
// CSV
// ============================================================
if ($format == 'csv') {
    $filename = 'expenses_export_' . date('Y-m-d') . '.csv';
    if ($branch_info) {
        $filename = 'expenses_' . strtolower(str_replace(' ', '_', $branch_info['branch_name'])) . '_' . date('Y-m-d') . '.csv';
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, [$company_name]);
    if ($company_address) fputcsv($output, [$company_address]);
    if ($company_phone) fputcsv($output, ['Phone: ' . $company_phone]);
    if ($company_email) fputcsv($output, ['Email: ' . $company_email]);
    fputcsv($output, []);
    fputcsv($output, ['Expenses Report']);
    
    if ($branch_info) {
        fputcsv($output, ['Branch: ' . $branch_info['branch_name'] . ' (' . $branch_info['branch_code'] . ')']);
    } else {
        fputcsv($output, ['Branch: All Branches']);
    }
    fputcsv($output, ['Generated: ' . date('d M Y H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, ['#', 'Expense No.', 'Date', 'Name', 'Category', 'Branch', 'Employee', 'Amount', 'Type']);
    
    $counter = 1;
    foreach ($expenses as $e) {
        fputcsv($output, [
            $counter++,
            $e['expense_number'],
            date('Y-m-d', strtotime($e['expense_date'])),
            $e['expense_name'],
            $e['category'],
            $e['branch_name'] ?? 'Main',
            $e['employee_name'] ?? 'N/A',
            number_format($e['amount'], 2),
            $e['is_business_expense'] == 1 ? 'Business' : 'Personal'
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Amount:', '', '', '', '', '', '', number_format($total_amount, 2)]);
    fputcsv($output, ['Business:', '', '', '', '', '', '', number_format($total_business, 2)]);
    fputcsv($output, ['Personal/Other:', '', '', '', '', '', '', number_format($total_personal, 2)]);
    fputcsv($output, ['Total Records:', '', '', '', '', '', '', count($expenses)]);
    
    fclose($output);
    exit();
}

// ============================================================
// EXCEL
// ============================================================
elseif ($format == 'excel') {
    $filename = 'expenses_export_' . date('Y-m-d') . '.xls';
    if ($branch_info) {
        $filename = 'expenses_' . strtolower(str_replace(' ', '_', $branch_info['branch_name'])) . '_' . date('Y-m-d') . '.xls';
    }
    
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Expenses Export</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 10px; }
            .header-table { width: 100%; border: none; margin-bottom: 15px; }
            .header-table td { border: none; vertical-align: middle; padding: 5px; }
            .logo-img { width: 70px; height: 70px; border-radius: 50%; }
            .company-name { font-size: 20px; font-weight: bold; color: #DC2626; margin: 0; }
            .company-info { font-size: 11px; color: #666; margin: 2px 0; }
            .report-title { font-size: 14px; font-weight: bold; color: #333; margin: 4px 0 0 0; }
            table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
            table.data th { background: #DC2626; color: white; padding: 8px; text-align: left; font-size: 11px; border: 1px solid #B91C1C; }
            table.data td { padding: 6px; border: 1px solid #ccc; font-size: 11px; }
            table.data tr:nth-child(even) { background: #fef2f2; }
            .text-right { text-align: right; }
            .summary-table { margin-top: 15px; border-collapse: collapse; width: 350px; margin-left: auto; }
            .summary-table td { padding: 6px 10px; border: 1px solid #ccc; font-size: 12px; }
            .summary-table .label { font-weight: bold; background: #f3f4f6; }
            .summary-table .total-row { background: #FEE2E2; font-weight: bold; font-size: 13px; color: #991B1B; }
        </style>
    </head>
    <body>
        <table class="header-table">
            <tr>
                <?php if ($logo_data_uri): ?>
                <td style="width: 80px;"><img src="<?php echo $logo_data_uri; ?>" class="logo-img"></td>
                <?php endif; ?>
                <td>
                    <p class="company-name"><?php echo htmlspecialchars($company_name); ?></p>
                    <?php if ($company_address): ?><p class="company-info"><?php echo htmlspecialchars($company_address); ?></p><?php endif; ?>
                    <p class="report-title">EXPENSES REPORT</p>
                </td>
            </tr>
        </table>
        
        <table style="width:100%; border:none; margin-bottom:10px; font-size:12px;">
            <tr>
                <td style="border:none;"><strong>Branch:</strong> <?php echo $branch_info ? htmlspecialchars($branch_info['branch_name']) : 'All Branches'; ?></td>
                <td style="border:none; text-align:right;"><strong>Generated:</strong> <?php echo date('d M Y H:i'); ?></td>
            </tr>
            <tr>
                <td style="border:none;"><strong>Records:</strong> <?php echo count($expenses); ?></td>
                <td style="border:none; text-align:right;"><?php if (!empty($search_query)): ?><strong>Search:</strong> "<?php echo htmlspecialchars($search_query); ?>"<?php endif; ?></td>
            </tr>
        </table>
        
        <table class="data">
            <thead>
                <tr>
                    <th>#</th><th>Expense No.</th><th>Date</th><th>Name</th><th>Category</th>
                    <th>Branch</th><th>Employee</th><th class="text-right">Amount</th><th>Type</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr><td colspan="9" style="text-align:center; padding:30px; color:#999;">No expenses found.</td></tr>
                <?php else: $i=1; foreach ($expenses as $e): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo htmlspecialchars($e['expense_number']); ?></td>
                        <td><?php echo date('d M Y', strtotime($e['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars($e['expense_name']); ?></td>
                        <td><?php echo htmlspecialchars($e['category']); ?></td>
                        <td><?php echo htmlspecialchars($e['branch_name'] ?? 'Main'); ?></td>
                        <td><?php echo htmlspecialchars($e['employee_name'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo formatCurrency($e['amount']); ?></td>
                        <td><?php echo $e['is_business_expense'] == 1 ? 'Business' : 'Personal'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        
        <table class="summary-table">
            <tr><td class="label">Total Amount:</td><td class="text-right"><?php echo formatCurrency($total_amount); ?></td></tr>
            <tr><td class="label">Business:</td><td class="text-right"><?php echo formatCurrency($total_business); ?></td></tr>
            <tr><td class="label">Personal:</td><td class="text-right"><?php echo formatCurrency($total_personal); ?></td></tr>
            <tr class="total-row"><td class="label">Total Records:</td><td class="text-right"><?php echo count($expenses); ?></td></tr>
        </table>
    </body>
    </html>
    <?php
    exit();
}

// ============================================================
// PDF / PRINT
// ============================================================
elseif ($format == 'pdf' || $format == 'print') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Expenses Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', Arial, sans-serif; padding: 20px; color: #333; font-size: 12px; }
            
            .report-header {
                display: flex; align-items: center; gap: 20px;
                padding-bottom: 15px; border-bottom: 3px solid #DC2626;
                margin-bottom: 15px;
            }
            .report-header .logo {
                width: 80px; height: 80px; border-radius: 50%;
                border: 3px solid #DC2626; object-fit: cover;
            }
            .report-header .company-name { font-size: 22px; font-weight: 800; color: #DC2626; margin-bottom: 3px; }
            .report-header .company-details { font-size: 11px; color: #666; line-height: 1.5; }
            .report-header .report-title { font-size: 14px; font-weight: 700; color: #333; text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; }
            
            .report-meta {
                display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px;
                padding: 12px 16px; background: #fef2f2; border-radius: 8px;
                border-left: 4px solid #DC2626; margin-bottom: 15px; font-size: 12px;
            }
            .report-meta .meta-item { display: flex; gap: 6px; }
            .report-meta .meta-label { font-weight: 600; color: #666; }
            
            table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 11px; }
            table.data-table thead { background: #DC2626; }
            table.data-table thead th {
                color: #FFFFFF; padding: 8px 6px; text-align: left;
                font-weight: 700; font-size: 10px; text-transform: uppercase;
                border: 1px solid #B91C1C;
            }
            table.data-table tbody td { padding: 6px; border: 1px solid #e5e7eb; }
            table.data-table tbody tr:nth-child(even) { background: #fef2f2; }
            .text-right { text-align: right; }
            
            .summary-section { display: flex; justify-content: flex-end; margin-bottom: 20px; }
            .summary-table { width: 350px; border-collapse: collapse; font-size: 12px; }
            .summary-table td { padding: 8px 12px; border: 1px solid #e5e7eb; }
            .summary-table .summary-label { font-weight: 600; background: #f3f4f6; }
            .summary-table .summary-value { text-align: right; font-weight: 700; }
            .summary-table .total-row { background: #FEE2E2; font-size: 13px; border-top: 2px solid #DC2626; }
            .summary-table .total-row td { font-weight: 800; color: #991B1B; }
            
            .report-footer {
                margin-top: 30px; padding-top: 15px;
                border-top: 2px solid #DC2626;
                display: flex; justify-content: space-between;
                font-size: 10px; color: #999;
            }
            .signature-box {
                border: 1px dashed #ccc; padding: 8px 20px;
                border-radius: 6px; min-width: 150px; text-align: center;
            }
            
            .print-actions {
                position: fixed; top: 20px; right: 20px;
                z-index: 1000; display: flex; gap: 10px;
            }
            .print-btn {
                background: #DC2626; color: white; border: none;
                padding: 10px 20px; border-radius: 8px;
                font-size: 13px; font-weight: 600; cursor: pointer;
                display: inline-flex; align-items: center; gap: 6px;
                box-shadow: 0 4px 12px rgba(220,38,38,0.3);
            }
            .close-btn {
                background: #6b7280; color: white; border: none;
                padding: 10px 20px; border-radius: 8px;
                font-size: 13px; font-weight: 600; cursor: pointer;
            }
            
            @media print {
                body { padding: 0; font-size: 10px; }
                .print-actions { display: none !important; }
                .report-header .logo { width: 60px; height: 60px; }
                table.data-table { font-size: 9px; }
                tr { page-break-inside: avoid; }
            }
            @page { size: A4 landscape; margin: 1cm; }
        </style>
    </head>
    <body>
        <div class="print-actions">
            <button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
            <button class="close-btn" onclick="closeReport()">✕ Close</button>
        </div>
        
        <div class="report-header">
            <?php if ($logo_data_uri): ?>
                <img src="<?php echo $logo_data_uri; ?>" class="logo" alt="Logo">
            <?php endif; ?>
            <div>
                <div class="company-name"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="company-details">
                    <?php if ($company_address): ?>📍 <?php echo htmlspecialchars($company_address); ?><br><?php endif; ?>
                    <?php if ($company_phone): ?>📞 <?php echo htmlspecialchars($company_phone); ?><?php endif; ?>
                    <?php if ($company_email): ?> | ✉ <?php echo htmlspecialchars($company_email); ?><?php endif; ?>
                </div>
                <div class="report-title">💰 Expenses Report</div>
            </div>
        </div>
        
        <div class="report-meta">
            <div class="meta-item"><span class="meta-label">Branch:</span><span><?php echo $branch_info ? htmlspecialchars($branch_info['branch_name']) : 'All Branches'; ?></span></div>
            <div class="meta-item"><span class="meta-label">Records:</span><span><?php echo count($expenses); ?></span></div>
            <div class="meta-item"><span class="meta-label">Generated:</span><span><?php echo date('d M Y H:i:s'); ?></span></div>
            <?php if (!empty($search_query)): ?>
            <div class="meta-item"><span class="meta-label">Search:</span><span>"<?php echo htmlspecialchars($search_query); ?>"</span></div>
            <?php endif; ?>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:30px;">#</th><th>Expense No.</th><th>Date</th><th>Name</th>
                    <th>Category</th><th>Branch</th><th>Employee</th>
                    <th class="text-right">Amount</th><th>Type</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                    <tr><td colspan="9" class="text-right" style="text-align:center;padding:30px;color:#999;">No expenses found.</td></tr>
                <?php else: $i=1; foreach ($expenses as $e): ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td style="font-family:monospace;color:#DC2626;font-weight:700;"><?php echo htmlspecialchars($e['expense_number']); ?></td>
                        <td><?php echo date('d M Y', strtotime($e['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars($e['expense_name']); ?></td>
                        <td><?php echo htmlspecialchars($e['category']); ?></td>
                        <td><?php echo htmlspecialchars($e['branch_name'] ?? 'Main'); ?></td>
                        <td><?php echo htmlspecialchars($e['employee_name'] ?? 'N/A'); ?></td>
                        <td class="text-right" style="color:#991B1B;font-weight:700;"><?php echo formatCurrency($e['amount']); ?></td>
                        <td><?php echo $e['is_business_expense'] == 1 ? 'Business' : 'Personal'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        
        <div class="summary-section">
            <table class="summary-table">
                <tr><td class="summary-label">Total Amount:</td><td class="summary-value"><?php echo formatCurrency($total_amount); ?></td></tr>
                <tr><td class="summary-label">Business:</td><td class="summary-value"><?php echo formatCurrency($total_business); ?></td></tr>
                <tr><td class="summary-label">Personal / Other:</td><td class="summary-value"><?php echo formatCurrency($total_personal); ?></td></tr>
                <tr class="total-row"><td>Total Records:</td><td class="summary-value"><?php echo count($expenses); ?></td></tr>
            </table>
        </div>
        
        <div class="report-footer">
            <div>
                Generated by <strong><?php echo htmlspecialchars($company_name); ?></strong> Management System<br>
                Report ID: EXP-<?php echo date('YmdHis'); ?>
            </div>
            <div class="signature-box">
                <div style="font-size:9px;color:#999;text-transform:uppercase;margin-bottom:3px;">Authorized By</div>
                <div style="font-size:11px;color:#333;font-weight:600;">_________________</div>
            </div>
        </div>
        
        <script>
        function closeReport() {
            try { window.close(); } catch (e) {}
            setTimeout(function() {
                if (!window.closed) {
                    if (window.history.length > 1) window.history.back();
                    else window.location.href = 'index.php';
                }
            }, 150);
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeReport();
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') { e.preventDefault(); window.print(); }
        });
        </script>
    </body>
    </html>
    <?php
    exit();
}

header('Location: index.php');
exit();
?>