<?php
// ================================================================
// FILE: modules/morning_report/export.php
// WAKALA FINANCIAL SYSTEM - EXPORT MORNING REPORTS TO CSV
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

// ============================================================
// GET FILTERS
// ============================================================
$selected_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date   = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$single_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Employee: force own branch
if ($role !== 'admin' && $role !== 'super_admin') {
    $stmt = $db->prepare("SELECT branch_id FROM employees WHERE id = ?");
    $stmt->execute([$user_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    $selected_branch = intval($emp['branch_id'] ?? 0);
}

// ============================================================
// BUILD QUERY
// ============================================================
$where = [];
$params = [];

if ($single_id > 0) {
    $where[] = "mr.id = ?";
    $params[] = $single_id;
} else {
    $where[] = "mr.report_date BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;

    if ($selected_branch > 0) {
        $where[] = "mr.branch_id = ?";
        $params[] = $selected_branch;
    }
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT 
        mr.id,
        mr.report_number,
        mr.report_date,
        mr.branch,
        mr.branch_id,
        mr.cash_balance,
        mr.cumm_total,
        mr.source_type,
        mr.is_locked,
        mr.notes,
        mr.submitted_at,
        e.full_name AS employee_name,
        e.employee_id AS employee_code,
        b.branch_name AS branch_display_name,
        b.branch_code AS branch_display_code,
        es.stock_number AS source_stock_number,
        es.stock_date AS source_stock_date
    FROM morning_reports mr
    LEFT JOIN employees e ON mr.employee_id = e.id
    LEFT JOIN branches b ON mr.branch_id = b.id
    LEFT JOIN evening_stocks es ON mr.source_evening_stock_id = es.id
    $where_sql
    ORDER BY mr.report_date DESC, mr.id DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FETCH ALL PROVIDERS FOR THESE REPORTS
// ============================================================
$report_ids = array_column($reports, 'id');
$providers_by_report = [];

if (!empty($report_ids)) {
    $placeholders = implode(',', array_fill(0, count($report_ids), '?'));
    $stmt = $db->prepare("
        SELECT 
            mrp.report_id,
            mrp.provider_name,
            mrp.provider_code,
            mrp.float_balance,
            p.provider_type
        FROM morning_report_providers mrp
        LEFT JOIN providers p ON mrp.provider_id = p.id
        WHERE mrp.report_id IN ($placeholders)
        ORDER BY mrp.report_id, p.display_order, mrp.provider_name
    ");
    $stmt->execute($report_ids);
    $all_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_providers as $ap) {
        $rid = intval($ap['report_id']);
        if (!isset($providers_by_report[$rid])) {
            $providers_by_report[$rid] = [];
        }
        $providers_by_report[$rid][] = $ap;
    }
}

// ============================================================
// GET COMPANY NAME
// ============================================================
$company_name = 'Wakala System';
try {
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
    $stmt->execute();
    $company_name = $stmt->fetchColumn() ?: $company_name;
} catch (Exception $e) {}

// ============================================================
// GENERATE FILENAME
// ============================================================
$filename = 'morning_reports_';
if ($single_id > 0 && !empty($reports)) {
    $filename .= str_replace(['/', '\\', ' '], '_', $reports[0]['report_number']);
} else {
    $filename .= date('Y-m-d', strtotime($from_date)) . '_to_' . date('Y-m-d', strtotime($to_date));
}
$filename .= '_' . date('His') . '.csv';

// ============================================================
// OUTPUT CSV
// ============================================================
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM for Excel UTF-8
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// ============================================================
// TITLE BLOCK
// ============================================================
fputcsv($output, ['MORNING REPORTS EXPORT']);
fputcsv($output, ['Company', $company_name]);
fputcsv($output, ['Period', $from_date . ' to ' . $to_date]);
fputcsv($output, ['Generated', date('d M Y H:i:s')]);
fputcsv($output, ['Total Reports', count($reports)]);
fputcsv($output, []);

// ============================================================
// SUMMARY
// ============================================================
$grand_total_float = 0;
$grand_total_cash = 0;
$grand_total_cumm = 0;

foreach ($reports as $r) {
    $rid = intval($r['id']);
    $rep_float = 0;
    if (isset($providers_by_report[$rid])) {
        foreach ($providers_by_report[$rid] as $pp) {
            $rep_float += floatval($pp['float_balance']);
        }
    }
    $grand_total_float += $rep_float;
    $grand_total_cash += floatval($r['cash_balance']);
    $grand_total_cumm += floatval($r['cumm_total']);
}

fputcsv($output, ['SUMMARY']);
fputcsv($output, ['Total Float', number_format($grand_total_float, 2, '.', '')]);
fputcsv($output, ['Total Cash', number_format($grand_total_cash, 2, '.', '')]);
fputcsv($output, ['Cumm. Total', number_format($grand_total_cumm, 2, '.', '')]);
fputcsv($output, []);

// ============================================================
// HEADER ROW
// ============================================================
fputcsv($output, [
    '#',
    'Report Number',
    'Report Date',
    'Branch',
    'Branch Code',
    'Employee',
    'Employee Code',
    'Provider',
    'Provider Code',
    'Provider Type',
    'Float Balance',
    'Report Cash',
    'Report Cumm. Total',
    'Source Stock',
    'Source Date',
    'Status',
    'Notes',
    'Submitted At'
]);

// ============================================================
// DATA ROWS
// ============================================================
$i = 1;
foreach ($reports as $r) {
    $rid = intval($r['id']);
    $rep_providers = $providers_by_report[$rid] ?? [];

    if (empty($rep_providers)) {
        // Report with no providers
        fputcsv($output, [
            $i++,
            $r['report_number'],
            $r['report_date'],
            $r['branch_display_name'] ?? $r['branch'],
            $r['branch_display_code'] ?? '',
            $r['employee_name'] ?? '',
            $r['employee_code'] ?? '',
            '-',
            '-',
            '-',
            '0.00',
            number_format($r['cash_balance'], 2, '.', ''),
            number_format($r['cumm_total'], 2, '.', ''),
            $r['source_stock_number'] ?? '',
            $r['source_stock_date'] ?? '',
            intval($r['is_locked']) === 1 ? 'Locked' : 'Open',
            str_replace(["\r", "\n"], ' ', $r['notes'] ?? ''),
            $r['submitted_at']
        ]);
    } else {
        foreach ($rep_providers as $pp) {
            fputcsv($output, [
                $i++,
                $r['report_number'],
                $r['report_date'],
                $r['branch_display_name'] ?? $r['branch'],
                $r['branch_display_code'] ?? '',
                $r['employee_name'] ?? '',
                $r['employee_code'] ?? '',
                $pp['provider_name'],
                $pp['provider_code'],
                ucfirst(str_replace('_', ' ', $pp['provider_type'] ?? 'bank')),
                number_format($pp['float_balance'], 2, '.', ''),
                number_format($r['cash_balance'], 2, '.', ''),
                number_format($r['cumm_total'], 2, '.', ''),
                $r['source_stock_number'] ?? '',
                $r['source_stock_date'] ?? '',
                intval($r['is_locked']) === 1 ? 'Locked' : 'Open',
                str_replace(["\r", "\n"], ' ', $r['notes'] ?? ''),
                $r['submitted_at']
            ]);
        }
    }
}

// ============================================================
// FOOTER
// ============================================================
fputcsv($output, []);
fputcsv($output, ['--- END OF REPORT ---']);
fputcsv($output, ['Generated by', $user_id]);
fputcsv($output, ['System', 'Wakala Financial System']);

fclose($output);

// Log activity
try {
    logActivity(
        $user_id,
        'Export Morning Reports',
        'Morning Report',
        null,
        '',
        'Exported ' . count($reports) . ' morning reports (' . $from_date . ' to ' . $to_date . ')'
    );
} catch (Exception $e) {}

exit();