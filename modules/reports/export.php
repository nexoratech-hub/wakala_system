<?php
// ================================================================
// FILE: modules/reports/export.php
// WAKALA FINANCIAL SYSTEM - EXPORT REPORTS
// WITH FULL DARK MODE SUPPORT
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
// GET PARAMETERS
// ============================================================
$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'csv';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// ============================================================
// PROCESS EXPORT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? 'daily';
    $format = $_POST['format'] ?? 'csv';
    $start_date = $_POST['start_date'] ?? date('Y-m-01');
    $end_date = $_POST['end_date'] ?? date('Y-m-d');
    
    // Redirect to export with parameters
    header("Location: export.php?type=$type&format=$format&start_date=$start_date&end_date=$end_date");
    exit();
}

// ============================================================
// INCLUDE HEADER
// ============================================================
if ($role === 'employee') {
    include_once '../../includes/employee_header.php';
    include_once '../../includes/employee_sidebar.php';
    include_once '../../includes/employee_topbar.php';
} else {
    include_once '../../includes/admin_header.php';
    include_once '../../includes/admin_sidebar.php';
    include_once '../../includes/admin_topbar.php';
}
?>

<!-- ============================================================
CONTENT
============================================================ -->
<div class="main-wrapper">
    <div class="main-content">
        
        <!-- ===== PAGE HEADER ===== -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-file-export"></i> Export Reports</h2>
                <span class="record-count">Export Data</span>
            </div>
            <div class="page-header-right">
                <a href="index.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Reports
                </a>
            </div>
        </div>

        <!-- ============================================================
        EXPORT FORM
        ============================================================ -->
        <div class="form-container">
            <form method="POST" action="" class="export-form">
                
                <!-- ===== EXPORT TYPE ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-file-alt"></i> Export Type
                    </div>
                    
                    <div class="export-options">
                        <label class="export-option <?php echo $type == 'daily' ? 'selected' : ''; ?>">
                            <input type="radio" name="type" value="daily" <?php echo $type == 'daily' ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <i class="fas fa-calendar-day"></i>
                                <span class="option-label">Daily Reports</span>
                                <span class="option-desc">Export daily transaction reports</span>
                            </div>
                        </label>

                        <label class="export-option <?php echo $type == 'commission' ? 'selected' : ''; ?>">
                            <input type="radio" name="type" value="commission" <?php echo $type == 'commission' ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <i class="fas fa-hand-holding-usd"></i>
                                <span class="option-label">Commission Reports</span>
                                <span class="option-desc">Export commission records</span>
                            </div>
                        </label>

                        <label class="export-option <?php echo $type == 'expense' ? 'selected' : ''; ?>">
                            <input type="radio" name="type" value="expense" <?php echo $type == 'expense' ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <i class="fas fa-receipt"></i>
                                <span class="option-label">Expense Reports</span>
                                <span class="option-desc">Export expense records</span>
                            </div>
                        </label>

                        <label class="export-option <?php echo $type == 'profit' ? 'selected' : ''; ?>">
                            <input type="radio" name="type" value="profit" <?php echo $type == 'profit' ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <i class="fas fa-chart-line"></i>
                                <span class="option-label">Profit & Loss</span>
                                <span class="option-desc">Export profit and loss summary</span>
                            </div>
                        </label>

                        <label class="export-option <?php echo $type == 'all' ? 'selected' : ''; ?>">
                            <input type="radio" name="type" value="all" <?php echo $type == 'all' ? 'checked' : ''; ?>>
                            <div class="option-content">
                                <i class="fas fa-database"></i>
                                <span class="option-label">All Data</span>
                                <span class="option-desc">Export all report data</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- ===== DATE RANGE ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-calendar-alt"></i> Date Range
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="start_date" class="form-label required">Start Date</label>
                            <input type="date" id="start_date" name="start_date" 
                                   class="form-control" 
                                   value="<?php echo $start_date; ?>"
                                   required>
                        </div>
                        <div class="form-group">
                            <label for="end_date" class="form-label required">End Date</label>
                            <input type="date" id="end_date" name="end_date" 
                                   class="form-control" 
                                   value="<?php echo $end_date; ?>"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- ===== EXPORT FORMAT ===== -->
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-file-export"></i> Export Format
                    </div>
                    
                    <div class="format-options">
                        <label class="format-option <?php echo $format == 'csv' ? 'selected' : ''; ?>">
                            <input type="radio" name="format" value="csv" <?php echo $format == 'csv' ? 'checked' : ''; ?>>
                            <div class="format-content">
                                <i class="fas fa-file-csv" style="color:#0B5ED7;"></i>
                                <span>CSV</span>
                                <small>Comma Separated Values</small>
                            </div>
                        </label>

                        <label class="format-option <?php echo $format == 'excel' ? 'selected' : ''; ?>">
                            <input type="radio" name="format" value="excel" <?php echo $format == 'excel' ? 'checked' : ''; ?>>
                            <div class="format-content">
                                <i class="fas fa-file-excel" style="color:#1D7D1D;"></i>
                                <span>Excel</span>
                                <small>Microsoft Excel</small>
                            </div>
                        </label>

                        <label class="format-option <?php echo $format == 'pdf' ? 'selected' : ''; ?>">
                            <input type="radio" name="format" value="pdf" <?php echo $format == 'pdf' ? 'checked' : ''; ?>>
                            <div class="format-content">
                                <i class="fas fa-file-pdf" style="color:#DC2626;"></i>
                                <span>PDF</span>
                                <small>PDF Document</small>
                            </div>
                        </label>

                        <label class="format-option <?php echo $format == 'print' ? 'selected' : ''; ?>">
                            <input type="radio" name="format" value="print" <?php echo $format == 'print' ? 'checked' : ''; ?>>
                            <div class="format-content">
                                <i class="fas fa-print" style="color:#6B7280;"></i>
                                <span>Print</span>
                                <small>Print Report</small>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- ===== FORM ACTIONS ===== -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-file-export"></i> Export Report
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>

            </form>
        </div>

        <!-- ============================================================
        EXPORT HELP
        ============================================================ -->
        <div class="help-section">
            <div class="help-card">
                <i class="fas fa-info-circle"></i>
                <div class="help-content">
                    <h4>How to Export</h4>
                    <p>1. Select the type of report you want to export.</p>
                    <p>2. Choose the date range for the data.</p>
                    <p>3. Select your preferred export format.</p>
                    <p>4. Click the "Export Report" button.</p>
                </div>
            </div>
            <div class="help-card">
                <i class="fas fa-clock"></i>
                <div class="help-content">
                    <h4>Tips</h4>
                    <p>• For large datasets, use CSV or Excel format.</p>
                    <p>• Use PDF for professional presentation.</p>
                    <p>• Print format is best for physical copies.</p>
                    <p>• Date range affects the amount of data exported.</p>
                </div>
            </div>
        </div>

    </div>
    
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <?php if ($role === 'employee') {
        include_once '../../includes/employee_footer.php';
    } else {
        include_once '../../includes/admin_footer.php';
    } ?>
</div>

<!-- ============================================================
STYLES
============================================================ -->
<style>
:root {
    --export-bg: #FFFFFF;
    --export-text: #1F2937;
    --export-text-secondary: #6B7280;
    --export-text-light: #9CA3AF;
    --export-border: #E5E7EB;
    --export-card-bg: #FFFFFF;
    --export-hover: #F3F4F6;
    --export-shadow: rgba(0,0,0,0.06);
    --export-shadow-lg: rgba(0,0,0,0.12);
    --export-section-border: #E5E7EB;
}

html.dark-mode {
    --export-bg: #1F2937;
    --export-text: #F9FAFB;
    --export-text-secondary: #9CA3AF;
    --export-text-light: #6B7280;
    --export-border: #374151;
    --export-card-bg: #1F2937;
    --export-hover: #374151;
    --export-shadow: rgba(0,0,0,0.3);
    --export-shadow-lg: rgba(0,0,0,0.4);
    --export-section-border: #374151;
}

body {
    background: var(--export-bg) !important;
    color: var(--export-text);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper { background: var(--export-bg) !important; }
.main-content { background: var(--export-bg) !important; }

/* Page Header */
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
    color: var(--export-text);
    margin: 0;
}

.page-header-left h2 i {
    color: #4F46E5;
    margin-right: 8px;
}

.record-count {
    font-size: 13px;
    color: var(--export-text-secondary);
    background: var(--export-hover);
    padding: 2px 12px;
    border-radius: 12px;
}

.btn-back {
    background: var(--export-hover);
    color: var(--export-text);
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--export-border);
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--export-border);
}

/* Form Container */
.form-container {
    background: var(--export-card-bg);
    border-radius: 10px;
    box-shadow: 0 1px 3px var(--export-shadow);
    border: 1px solid var(--export-border);
    padding: 24px;
    margin-bottom: 16px;
}

/* Form Sections */
.form-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--export-section-border);
}

.form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.form-section-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--export-text);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-section-title i {
    color: #4F46E5;
}

/* Form Grid */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px 24px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.form-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--export-text);
}

.form-label.required::after {
    content: ' *';
    color: #DC2626;
}

.form-control {
    padding: 10px 14px;
    border-radius: 8px;
    border: 1.5px solid var(--export-border);
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s ease;
    background: var(--export-hover);
    color: var(--export-text);
    width: 100%;
}

.form-control:focus {
    outline: none;
    border-color: #DC2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,0.08);
}

/* Export Options */
.export-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

.export-option {
    cursor: pointer;
    border: 2px solid var(--export-border);
    border-radius: 10px;
    padding: 14px 16px;
    transition: all 0.3s ease;
    background: var(--export-hover);
}

.export-option:hover {
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--export-shadow-lg);
}

.export-option.selected {
    border-color: #DC2626;
    background: rgba(220,38,38,0.05);
}

html.dark-mode .export-option.selected {
    background: rgba(220,38,38,0.15);
}

.export-option input[type="radio"] {
    display: none;
}

.option-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 4px;
}

.option-content i {
    font-size: 28px;
    color: #4F46E5;
}

.option-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--export-text);
}

.option-desc {
    font-size: 11px;
    color: var(--export-text-secondary);
}

/* Format Options */
.format-options {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.format-option {
    cursor: pointer;
    border: 2px solid var(--export-border);
    border-radius: 10px;
    padding: 12px 14px;
    transition: all 0.3s ease;
    background: var(--export-hover);
    text-align: center;
}

.format-option:hover {
    border-color: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px var(--export-shadow-lg);
}

.format-option.selected {
    border-color: #DC2626;
    background: rgba(220,38,38,0.05);
}

html.dark-mode .format-option.selected {
    background: rgba(220,38,38,0.15);
}

.format-option input[type="radio"] {
    display: none;
}

.format-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.format-content i {
    font-size: 28px;
}

.format-content span {
    font-size: 14px;
    font-weight: 600;
    color: var(--export-text);
}

.format-content small {
    font-size: 10px;
    color: var(--export-text-secondary);
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid var(--export-section-border);
}

.btn {
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: #DC2626;
    color: white;
}

.btn-primary:hover {
    background: #B91C1C;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220,38,38,0.3);
}

.btn-secondary {
    background: var(--export-hover);
    color: var(--export-text);
    border: 1px solid var(--export-border);
}

.btn-secondary:hover {
    background: var(--export-border);
}

/* Help Section */
.help-section {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.help-card {
    background: var(--export-card-bg);
    border-radius: 10px;
    padding: 16px 20px;
    border: 1px solid var(--export-border);
    box-shadow: 0 1px 3px var(--export-shadow);
    display: flex;
    gap: 14px;
    align-items: flex-start;
}

.help-card i {
    font-size: 24px;
    color: #4F46E5;
    margin-top: 2px;
}

.help-content h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--export-text);
    margin: 0 0 6px 0;
}

.help-content p {
    font-size: 12px;
    color: var(--export-text-secondary);
    margin: 2px 0;
}

/* Responsive */
@media (max-width: 1024px) {
    .export-options {
        grid-template-columns: repeat(2, 1fr);
    }
    .format-options {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .form-container {
        padding: 16px;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .export-options {
        grid-template-columns: 1fr 1fr;
    }
    
    .format-options {
        grid-template-columns: 1fr 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        justify-content: center;
        width: 100%;
    }
    
    .help-section {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .export-options {
        grid-template-columns: 1fr;
    }
    
    .format-options {
        grid-template-columns: 1fr 1fr;
    }
    
    .form-container {
        padding: 12px;
    }
    
    .help-card {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Export option selection
    document.querySelectorAll('.export-option').forEach(function(option) {
        option.addEventListener('click', function() {
            var radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                document.querySelectorAll('.export-option').forEach(function(opt) {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
            }
        });
    });
    
    // Format option selection
    document.querySelectorAll('.format-option').forEach(function(option) {
        option.addEventListener('click', function() {
            var radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                document.querySelectorAll('.format-option').forEach(function(opt) {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
            }
        });
    });
    
    // Dark Mode Sync
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