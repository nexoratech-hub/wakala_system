<?php
// ================================================================
// FILE: modules/settings/backup.php
// DATABASE BACKUP MANAGEMENT - RED THEME
// ================================================================

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

$role = $_SESSION['role'] ?? 'employee';

// Only super_admin can access backup (sensitive data)
if ($role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$error = '';
$success = '';

// ============================================================
// CONFIGURATION
// ============================================================
$backup_dir = '../../backups/';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
}

// Get database info
$db_name = 'wakala_system';
try {
    $stmt = $db->query("SELECT DATABASE()");
    $db_name = $stmt->fetchColumn() ?: 'wakala_system';
} catch (PDOException $e) {}

// ============================================================
// HANDLE CREATE BACKUP
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_backup'])) {
    try {
        $backup_type = $_POST['backup_type'] ?? 'full';
        
        // Generate filename
        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'backup_' . $db_name . '_' . $timestamp . '.sql';
        $filepath = $backup_dir . $filename;
        
        // Build mysqldump command (if available)
        $backup_success = false;
        
        // Try mysqldump first
        $mysqldump_paths = ['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'];
        $dump_path = null;
        
        foreach ($mysqldump_paths as $path) {
            $test = @shell_exec("which $path 2>/dev/null") ?: (file_exists($path) ? $path : null);
            if ($test) {
                $dump_path = $path;
                break;
            }
        }
        
        // Alternative: PHP-based backup
        if (!$dump_path) {
            // PHP-based dump
            $tables = [];
            $stmt = $db->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            $sql_dump = "-- Wakala System Database Backup\n";
            $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sql_dump .= "-- Database: {$db_name}\n";
            $sql_dump .= "-- Type: {$backup_type}\n";
            $sql_dump .= "-- --------------------------------------------------------\n\n";
            $sql_dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $sql_dump .= "START TRANSACTION;\n";
            $sql_dump .= "SET time_zone = \"+00:00\";\n\n\n";
            
            foreach ($tables as $table) {
                // Get CREATE TABLE
                $stmt2 = $db->query("SHOW CREATE TABLE `{$table}`");
                $create = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                $sql_dump .= "-- --------------------------------------------------------\n";
                $sql_dump .= "-- Table structure for table `{$table}`\n";
                $sql_dump .= "-- --------------------------------------------------------\n\n";
                $sql_dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql_dump .= $create['Create Table'] . ";\n\n";
                
                // Get data
                $stmt3 = $db->query("SELECT * FROM `{$table}`");
                $rows = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($rows) > 0) {
                    $sql_dump .= "-- Dumping data for table `{$table}`\n\n";
                    
                    foreach ($rows as $row) {
                        $columns = array_keys($row);
                        $values = array_map(function($v) use ($db) {
                            if ($v === null) return 'NULL';
                            return $db->quote($v);
                        }, array_values($row));
                        
                        $sql_dump .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql_dump .= "\n";
                }
            }
            
            $sql_dump .= "COMMIT;\n";
            
            if (file_put_contents($filepath, $sql_dump)) {
                $backup_success = true;
            }
        } else {
            // Use mysqldump
            $cmd = sprintf(
                '%s --host=%s --user=%s --password=%s %s > %s 2>&1',
                escapeshellarg($dump_path),
                escapeshellarg(DB_HOST),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASS),
                escapeshellarg($db_name),
                escapeshellarg($filepath)
            );
            
            @shell_exec($cmd);
            
            if (file_exists($filepath) && filesize($filepath) > 0) {
                $backup_success = true;
            }
        }
        
        if ($backup_success) {
            $file_size = filesize($filepath);
            
            logActivity($_SESSION['user_id'], 'Create Backup', 'Settings', null, null, json_encode([
                'filename' => $filename,
                'size' => $file_size,
                'type' => $backup_type
            ]));
            
            $success = 'Backup created successfully! (' . formatBytes($file_size) . ')';
        } else {
            throw new Exception('Failed to create backup file.');
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// HANDLE DELETE BACKUP
// ============================================================
if (isset($_GET['delete'])) {
    try {
        $file = basename($_GET['delete']);
        $filepath = $backup_dir . $file;
        
        if (!file_exists($filepath)) {
            throw new Exception('Backup file not found.');
        }
        
        if (!preg_match('/^backup_.*\.sql$/', $file)) {
            throw new Exception('Invalid backup file.');
        }
        
        if (!unlink($filepath)) {
            throw new Exception('Failed to delete backup file.');
        }
        
        logActivity($_SESSION['user_id'], 'Delete Backup', 'Settings', null, null, json_encode([
            'filename' => $file
        ]));
        
        $success = 'Backup deleted successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ============================================================
// HANDLE DOWNLOAD BACKUP
// ============================================================
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $backup_dir . $file;
    
    if (file_exists($filepath) && preg_match('/^backup_.*\.sql$/', $file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        
        readfile($filepath);
        exit();
    } else {
        $error = 'Backup file not found.';
    }
}

// ============================================================
// HELPER: Format bytes
// ============================================================
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ============================================================
// GET BACKUPS LIST
// ============================================================
$backups = [];
$total_size = 0;
$last_backup_time = null;

try {
    $files = glob($backup_dir . 'backup_*.sql');
    if ($files) {
        foreach ($files as $file) {
            $filename = basename($file);
            $file_size = filesize($file);
            $modified = filemtime($file);
            
            $backups[] = [
                'filename' => $filename,
                'size' => $file_size,
                'size_formatted' => formatBytes($file_size),
                'modified' => $modified,
                'modified_formatted' => date('d M Y H:i:s', $modified),
                'ago' => timeAgo($modified),
            ];
            
            $total_size += $file_size;
            
            if ($last_backup_time === null || $modified > $last_backup_time) {
                $last_backup_time = $modified;
            }
        }
        
        // Sort by modified date (newest first)
        usort($backups, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
    }
} catch (Exception $e) {
    $backups = [];
}

// ============================================================
// HELPER: Time ago
// ============================================================
function timeAgo($timestamp) {
    if (!$timestamp) return 'Never';
    
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    
    return date('d M Y', $timestamp);
}

// ============================================================
// GET DATABASE INFO
// ============================================================
$db_info = [
    'name' => $db_name,
    'tables' => 0,
    'total_records' => 0,
    'size' => 0,
];

try {
    // Get tables
    $stmt = $db->query("SHOW TABLE STATUS");
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($tables as $table) {
        $db_info['tables']++;
        $db_info['total_records'] += intval($table['Rows'] ?? 0);
        $db_info['size'] += intval($table['Data_length'] ?? 0) + intval($table['Index_length'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Error getting DB info: " . $e->getMessage());
}

$backup_count = count($backups);

include_once '../../includes/admin_header.php';
include_once '../../includes/admin_sidebar.php';
include_once '../../includes/admin_topbar.php';
?>

<div class="main-wrapper">
    <div class="main-content">
        
        <div class="dark-mode-toggle">
            <button id="darkModeToggle" class="dark-mode-btn" onclick="toggleDarkMode()">
                <i class="fas fa-moon"></i>
                <span>Dark Mode</span>
            </button>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-left">
                <h2><i class="fas fa-database" style="color:#bb0404;"></i> Database Backup</h2>
                <p class="text-muted">Create, download, and manage database backups</p>
            </div>
            <div class="header-right">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> 
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card summary-blue">
                <div class="summary-icon"><i class="fas fa-file-archive"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Backups</span>
                    <span class="summary-value"><?php echo number_format($backup_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-green">
                <div class="summary-icon"><i class="fas fa-hdd"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Size</span>
                    <span class="summary-value"><?php echo formatBytes($total_size); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-purple">
                <div class="summary-icon"><i class="fas fa-clock"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Last Backup</span>
                    <span class="summary-value"><?php echo $last_backup_time ? timeAgo($last_backup_time) : 'Never'; ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-orange">
                <div class="summary-icon"><i class="fas fa-table"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Tables / Records</span>
                    <span class="summary-value"><?php echo number_format($db_info['tables']); ?> / <?php echo number_format($db_info['total_records']); ?></span>
                </div>
            </div>
        </div>

        <!-- Create Backup Card -->
        <div class="form-card">
            <h4><i class="fas fa-plus-circle" style="color:#bb0404;"></i> Create New Backup</h4>
            <p class="form-intro">Create a complete backup of your database. The backup file will be saved as a .sql file that can be restored later.</p>
            
            <form method="POST" action="" class="backup-form" id="backupForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>Backup Type</label>
                        <select name="backup_type" class="form-control">
                            <option value="full">Full Backup (Structure + Data)</option>
                            <option value="structure">Structure Only</option>
                            <option value="data">Data Only</option>
                        </select>
                    </div>
                    <div class="form-group form-group-button">
                        <button type="submit" name="create_backup" class="btn btn-primary btn-create-backup" id="createBackupBtn">
                            <i class="fas fa-download"></i> Create Backup Now
                        </button>
                    </div>
                </div>
            </form>
            
            <div class="db-info">
                <div class="db-info-item">
                    <span class="db-info-label">Database Name</span>
                    <span class="db-info-value"><?php echo htmlspecialchars($db_info['name']); ?></span>
                </div>
                <div class="db-info-item">
                    <span class="db-info-label">Database Size</span>
                    <span class="db-info-value"><?php echo formatBytes($db_info['size']); ?></span>
                </div>
                <div class="db-info-item">
                    <span class="db-info-label">Total Tables</span>
                    <span class="db-info-value"><?php echo number_format($db_info['tables']); ?></span>
                </div>
                <div class="db-info-item">
                    <span class="db-info-label">Total Records</span>
                    <span class="db-info-value"><?php echo number_format($db_info['total_records']); ?></span>
                </div>
            </div>
        </div>

        <!-- Backups Table -->
        <div class="table-container">
            
            <div class="table-header">
                <div class="table-header-left">
                    <h4><i class="fas fa-list"></i> Backup History</h4>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($backups); ?></span>
                </div>
                
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search backups..."
                               oninput="performLiveSearch(this.value)"
                               autocomplete="off">
                        <button type="button" 
                                class="search-clear-btn" 
                                id="searchClearBtn" 
                                onclick="clearLiveSearch()" 
                                style="display:none;"
                                title="Clear search">
                            <i class="fas fa-times"></i>
                        </button>
                        <span class="search-count-badge" 
                              id="searchCountBadge" 
                              style="display:none;">0</span>
                    </div>
                    
                    <div class="table-scroll-buttons">
                        <button type="button" class="scroll-btn" onclick="scrollTable('left')" title="Scroll Left">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button type="button" class="scroll-btn" onclick="scrollTable('right')" title="Scroll Right">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive" id="tableWrapper">
                <table class="data-table" id="backupsTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Backup File</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Age</th>
                            <th style="width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="backupsTableBody">
                        <?php if (count($backups) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($backups as $b): 
                                $search_text = strtolower($b['filename'] . ' ' . $b['modified_formatted']);
                            ?>
                                <tr class="backup-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <div class="file-cell">
                                            <div class="file-icon">
                                                <i class="fas fa-file-code"></i>
                                            </div>
                                            <div class="file-info">
                                                <span class="file-name"><?php echo htmlspecialchars($b['filename']); ?></span>
                                                <span class="file-type">SQL Database Dump</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="size-badge">
                                            <i class="fas fa-database"></i> <?php echo htmlspecialchars($b['size_formatted']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <span class="date-main"><?php echo htmlspecialchars($b['modified_formatted']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="ago-badge">
                                            <i class="fas fa-clock"></i> <?php echo htmlspecialchars($b['ago']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?download=<?php echo urlencode($b['filename']); ?>" 
                                               class="btn-action btn-download" 
                                               title="Download"
                                               download>
                                                <i class="fas fa-download"></i>
                                            </a>
                                            
                                            <button onclick="showInfo('<?php echo htmlspecialchars(addslashes($b['filename'])); ?>', '<?php echo htmlspecialchars($b['size_formatted']); ?>', '<?php echo htmlspecialchars($b['modified_formatted']); ?>')" 
                                                    class="btn-action btn-info" 
                                                    title="Info">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                            
                                            <a href="?delete=<?php echo urlencode($b['filename']); ?>" 
                                               class="btn-action btn-delete" 
                                               title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this backup?\n\n<?php echo htmlspecialchars(addslashes($b['filename'])); ?>');">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="no-data">
                                    <i class="fas fa-database" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No backups found</p>
                                    <p style="color:var(--text-muted);font-size:12px;">Click "Create Backup Now" to create your first backup</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <!-- No search results row -->
                        <tr id="noSearchResultsRow" style="display:none;">
                            <td colspan="6" class="no-data">
                                <i class="fas fa-search-minus" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                <p style="color:var(--text-muted);">No backups match your search</p>
                                <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()" style="margin-top:12px;">
                                    <i class="fas fa-times"></i> Clear Search
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Warning Card -->
        <div class="warning-card">
            <div class="warning-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="warning-content">
                <h4>Important Notes</h4>
                <ul>
                    <li>Backups are stored in <code>/backups/</code> directory on the server.</li>
                    <li>Only <strong>Super Admin</strong> can create, download, or delete backups.</li>
                    <li>Always download important backups to a safe location.</li>
                    <li>Old backups should be deleted to save server space.</li>
                    <li>Test your backups periodically to ensure they work.</li>
                </ul>
            </div>
        </div>

        <!-- Info Modal -->
        <div id="infoModal" class="modal" style="display:none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-info-circle"></i> Backup Details</h4>
                    <button class="modal-close" onclick="closeInfoModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="info-detail">
                        <span class="info-detail-label">Filename</span>
                        <span class="info-detail-value" id="infoFilename"></span>
                    </div>
                    <div class="info-detail">
                        <span class="info-detail-label">Size</span>
                        <span class="info-detail-value" id="infoSize"></span>
                    </div>
                    <div class="info-detail">
                        <span class="info-detail-label">Created</span>
                        <span class="info-detail-value" id="infoCreated"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeInfoModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>

    </div>
    <?php include_once '../../includes/admin_footer.php'; ?>
</div>

<style>
/* ============================================================
   VARIABLES
   ============================================================ */
:root {
    --bg-body: #f3f4f6;
    --bg-card: #ffffff;
    --bg-table-even: #fafafa;
    --bg-table-hover: #f3f4f6;
    --bg-input: #f9fafb;
    --text-primary: #1f2937;
    --text-secondary: #374151;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --border-color: #e5e7eb;
    --shadow-color: rgba(0,0,0,0.06);
    --shadow-hover: rgba(0,0,0,0.1);
}

body.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-table-even: #1a2332;
    --bg-table-hover: #2d3a4f;
    --bg-input: #334155;
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --text-light: #64748b;
    --border-color: #334155;
    --shadow-color: rgba(0,0,0,0.4);
    --shadow-hover: rgba(0,0,0,0.6);
}

/* FIX OVERFLOW */
html, body {
    overflow-x: hidden !important;
    max-width: 100vw !important;
    width: 100% !important;
    margin: 0;
    padding: 0;
}

body {
    background: var(--bg-body) !important;
    color: var(--text-primary);
    transition: background 0.3s ease, color 0.3s ease;
}

.main-wrapper {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.main-content {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
    padding: 16px 20px !important;
    box-sizing: border-box !important;
}

*, *::before, *::after { box-sizing: border-box; }

/* PAGE HEADER */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
}

.page-header .header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.page-header .header-left h2 i { margin-right: 10px; }

.page-header .header-left .text-muted {
    font-size: 13px;
    color: var(--text-muted);
    margin: 4px 0 0 0;
}

.header-right { display: flex; gap: 8px; flex-wrap: wrap; }

/* SUMMARY CARDS */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
    width: 100%;
}

.summary-card {
    position: relative;
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.3s ease;
    min-width: 0;
    overflow: hidden;
    border: 1.5px solid transparent;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.summary-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(187, 4, 4, 0.15);
}

.summary-blue { background: rgba(37, 99, 235, 0.08); border-color: rgba(37, 99, 235, 0.2); }
.summary-blue .summary-icon { background: rgba(37, 99, 235, 0.15); color: #2563EB; border: 1.5px solid rgba(37, 99, 235, 0.3); }
.summary-blue .summary-value { color: #1D4ED8; }

.summary-green { background: rgba(5, 150, 105, 0.08); border-color: rgba(5, 150, 105, 0.2); }
.summary-green .summary-icon { background: rgba(5, 150, 105, 0.15); color: #059669; border: 1.5px solid rgba(5, 150, 105, 0.3); }
.summary-green .summary-value { color: #047857; }

.summary-purple { background: rgba(124, 58, 237, 0.08); border-color: rgba(124, 58, 237, 0.2); }
.summary-purple .summary-icon { background: rgba(124, 58, 237, 0.15); color: #7C3AED; border: 1.5px solid rgba(124, 58, 237, 0.3); }
.summary-purple .summary-value { color: #6D28D9; }

.summary-orange { background: rgba(217, 119, 6, 0.08); border-color: rgba(217, 119, 6, 0.2); }
.summary-orange .summary-icon { background: rgba(217, 119, 6, 0.15); color: #D97706; border: 1.5px solid rgba(217, 119, 6, 0.3); }
.summary-orange .summary-value { color: #B45309; }

html.dark-mode .summary-blue { background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }
html.dark-mode .summary-green { background: rgba(5, 150, 105, 0.15); border-color: rgba(5, 150, 105, 0.3); }
html.dark-mode .summary-purple { background: rgba(124, 58, 237, 0.15); border-color: rgba(124, 58, 237, 0.3); }
html.dark-mode .summary-orange { background: rgba(217, 119, 6, 0.15); border-color: rgba(217, 119, 6, 0.3); }
html.dark-mode .summary-blue .summary-value { color: #60A5FA; }
html.dark-mode .summary-green .summary-value { color: #6EE7B7; }
html.dark-mode .summary-purple .summary-value { color: #C4B5FD; }
html.dark-mode .summary-orange .summary-value { color: #FBBF24; }

.summary-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.summary-card:hover .summary-icon {
    transform: scale(1.08) rotate(-4deg);
}

.summary-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1;
    gap: 2px;
}

.summary-label {
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
}

.summary-value {
    font-size: 18px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
    word-break: break-word;
}

/* FORM CARD */
.form-card {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 24px;
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
    width: 100%;
}

.form-card h4 {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 8px 0;
}

.form-intro {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0 0 20px 0;
    line-height: 1.6;
}

.backup-form {
    margin-bottom: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 16px;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group-button {
    padding-bottom: 0;
}

.form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control {
    padding: 11px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--bg-input);
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    width: 100%;
}

.form-control:focus {
    outline: none;
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
}

.btn {
    padding: 11px 22px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
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

.btn-primary { background: #bb0404; color: white; }
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

.btn-create-backup {
    width: 100%;
    justify-content: center;
}

.btn-create-backup:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

/* DB INFO */
.db-info {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding-top: 20px;
    border-top: 1px dashed var(--border-color);
}

.db-info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 16px;
    background: var(--bg-table-even);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.db-info-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.db-info-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
}

/* TABLE CONTAINER */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    width: 100%;
    overflow: hidden;
    margin-bottom: 20px;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    flex-wrap: wrap;
    gap: 12px;
    width: 100%;
}

.table-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.table-header-left h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
    white-space: nowrap;
}

.table-header-left h4 i { color: #bb0404; margin-right: 8px; }

.count-badge {
    background: #bb0404;
    color: white;
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    min-width: 24px;
    text-align: center;
}

.table-header-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1;
    justify-content: flex-end;
    min-width: 0;
}

/* LIVE SEARCH */
.table-search-live {
    position: relative;
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-input);
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    padding: 6px 12px;
    min-width: 240px;
    max-width: 300px;
    transition: all 0.3s ease;
}

.table-search-live:focus-within {
    border-color: #bb0404;
    box-shadow: 0 0 0 3px rgba(187,4,4,0.1);
    background: var(--bg-card);
}

.table-search-live > i {
    color: #bb0404;
    font-size: 13px;
    flex-shrink: 0;
}

.table-search-live input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 4px 0;
    font-size: 13px;
    color: var(--text-primary);
    outline: none;
    font-family: 'Inter', sans-serif;
    min-width: 0;
}

.table-search-live input::placeholder {
    color: var(--text-muted);
    font-size: 12px;
}

.table-search-live .search-clear-btn {
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
    padding: 0;
}

.table-search-live .search-clear-btn:hover {
    background: #DC2626;
    color: #FFFFFF;
}

.table-search-live .search-count-badge {
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    background: #FCD34D;
    color: #78350F;
    border-radius: 8px;
    white-space: nowrap;
    flex-shrink: 0;
}

/* SCROLL BUTTONS */
.table-scroll-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.scroll-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: #bb0404;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    transition: all 0.2s ease;
    flex-shrink: 0;
    padding: 0;
}

.scroll-btn:hover {
    background: #bb0404;
    color: #ffffff;
    border-color: #bb0404;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(187,4,4,0.3);
}

/* TABLE RESPONSIVE */
.table-responsive {
    overflow-x: auto !important;
    overflow-y: hidden;
    max-width: 100% !important;
    width: 100% !important;
    -webkit-overflow-scrolling: touch;
    display: block;
    scroll-behavior: smooth;
}

.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-track {
    background: var(--bg-table-even);
    border-radius: 4px;
}
.table-responsive::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #bb0404, #8a0303);
    border-radius: 4px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 900px;
}

.data-table thead {
    background: #bb0404;
    position: sticky;
    top: 0;
    z-index: 2;
}

.data-table thead th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #FFFFFF;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #8a0303;
    white-space: nowrap;
}

.data-table tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.2s ease;
}

.data-table tbody tr:hover { background: var(--bg-table-hover); }
.data-table tbody tr:nth-child(even) { background: var(--bg-table-even); }
.data-table tbody tr.search-hidden { display: none !important; }
.data-table tbody tr.search-match { 
    background: linear-gradient(135deg, rgba(252, 211, 77, 0.18), rgba(252, 211, 77, 0.08)) !important;
    border-left: 4px solid #F59E0B;
}

.data-table tbody td {
    padding: 12px;
    color: var(--text-secondary);
    vertical-align: middle;
}

.data-table mark {
    background: #FEF08A;
    color: #78350F;
    padding: 1px 3px;
    border-radius: 3px;
    font-weight: 800;
}

/* FILE CELL */
.file-cell {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.file-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: linear-gradient(135deg, #bb0404, #8a0303);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    border: 2px solid #FCA5A5;
    box-shadow: 0 2px 6px rgba(187,4,4,0.25);
}

.file-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.file-name {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 320px;
}

.file-type {
    font-size: 10px;
    color: var(--text-muted);
    font-weight: 600;
}

/* SIZE BADGE */
.size-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    background: #FEF3C7;
    color: #B45309;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1px solid #FCD34D;
    white-space: nowrap;
}

html.dark-mode .size-badge {
    background: #5F3A1E;
    color: #FBBF24;
    border-color: #D97706;
}

/* DATE CELL */
.date-cell {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    font-family: 'Courier New', monospace;
    white-space: nowrap;
}

/* AGO BADGE */
.ago-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    background: #DBEAFE;
    color: #1E40AF;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid #93C5FD;
    white-space: nowrap;
}

html.dark-mode .ago-badge {
    background: #1E3A5F;
    color: #60A5FA;
    border-color: #3B82F6;
}

/* ACTION BUTTONS */
.action-buttons { display: flex; gap: 4px; }

.btn-action {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 13px;
    padding: 0;
}

.btn-download { background: #D1FAE5; color: #065F46; }
.btn-download:hover { background: #065F46; color: #ffffff; }

.btn-info { background: #DBEAFE; color: #1E40AF; }
.btn-info:hover { background: #1E40AF; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

/* WARNING CARD */
.warning-card {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 1.5px solid #FCD34D;
    border-left: 5px solid #D97706;
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    width: 100%;
    margin-bottom: 20px;
}

html.dark-mode .warning-card {
    background: linear-gradient(135deg, #5F3A1E, #78350F);
    border-color: #D97706;
    border-left-color: #FBBF24;
}

.warning-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #D97706;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
}

html.dark-mode .warning-icon {
    background: #FBBF24;
    color: #78350F;
}

.warning-content {
    flex: 1;
    min-width: 0;
}

.warning-content h4 {
    font-size: 15px;
    font-weight: 800;
    color: #78350F;
    margin: 0 0 12px 0;
}

html.dark-mode .warning-content h4 { color: #FCD34D; }

.warning-content ul {
    margin: 0;
    padding-left: 20px;
    list-style: disc;
}

.warning-content ul li {
    font-size: 13px;
    color: #78350F;
    line-height: 1.8;
    margin-bottom: 4px;
}

html.dark-mode .warning-content ul li { color: #FDE68A; }

.warning-content code {
    background: rgba(255, 255, 255, 0.5);
    padding: 2px 8px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    font-weight: 700;
}

html.dark-mode .warning-content code {
    background: rgba(0,0,0,0.3);
    color: #FCD34D;
}

/* ALERTS */
.alert {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
}

.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }

html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; border-color: #991B1B; }

/* MODAL */
.modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 20px;
    backdrop-filter: blur(4px);
}

.modal-content {
    background: var(--bg-card);
    border-radius: 12px;
    max-width: 500px;
    width: 100%;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
}

.modal-header h4 {
    font-size: 16px;
    font-weight: 700;
    margin: 0;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-close {
    background: rgba(255,255,255,0.15);
    border: none;
    font-size: 24px;
    color: #FFFFFF;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    line-height: 1;
}

.modal-close:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.modal-body {
    padding: 24px;
}

.info-detail {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 0;
    border-bottom: 1px dashed var(--border-color);
}

.info-detail:last-child { border-bottom: none; }

.info-detail-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-detail-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-primary);
    font-family: 'Courier New', monospace;
    word-break: break-all;
}

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 16px 24px;
    background: var(--bg-table-even);
    border-top: 1px solid var(--border-color);
}

/* NO DATA */
.no-data { padding: 40px 20px; text-align: center; }
.text-muted { color: var(--text-muted); }
.text-center { text-align: center; }

/* DARK MODE TOGGLE */
.dark-mode-toggle {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
}

.dark-mode-btn {
    background: var(--bg-card);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
}

.dark-mode-btn:hover {
    background: var(--bg-table-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px var(--shadow-color);
}

/* RESPONSIVE */
@media (max-width: 1024px) {
    .summary-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .db-info { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 900px) {
    .table-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .table-header-left {
        justify-content: space-between;
        width: 100%;
    }
    
    .table-header-right {
        width: 100%;
        justify-content: space-between;
        flex-wrap: wrap;
    }
    
    .table-search-live {
        flex: 1;
        min-width: 0;
        max-width: none;
    }
}

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .summary-cards { grid-template-columns: 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .db-info { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    
    .warning-card { flex-direction: column; align-items: flex-start; }
    
    .table-header-right {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    
    .table-search-live {
        width: 100%;
        max-width: none;
    }
    
    .table-scroll-buttons {
        justify-content: center;
        width: 100%;
    }
}

@media (max-width: 480px) {
    .summary-value { font-size: 16px; }
    .summary-icon { width: 42px; height: 42px; font-size: 18px; }
    .table-search-live { width: 100%; }
    .scroll-btn { width: 40px; height: 40px; font-size: 14px; }
    .page-header .header-left h2 { font-size: 18px; }
    .file-name { max-width: 180px; }
}
</style>

<script>
// ============================================================
// LIVE SEARCH
// ============================================================
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('backupsTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.backup-row');
    const clearBtn = document.getElementById('searchClearBtn');
    const countBadge = document.getElementById('searchCountBadge');
    const noResultsRow = document.getElementById('noSearchResultsRow');
    const totalBadge = document.getElementById('totalCountBadge');
    
    const term = searchTerm.trim();
    
    if (clearBtn) clearBtn.style.display = term.length > 0 ? 'flex' : 'none';
    
    if (term.length === 0) {
        rows.forEach(row => {
            row.classList.remove('search-match', 'search-hidden');
            removeAllMarks(row);
        });
        if (countBadge) { countBadge.style.display = 'none'; countBadge.textContent = '0'; }
        if (noResultsRow) noResultsRow.style.display = 'none';
        if (totalBadge) totalBadge.textContent = rows.length;
        return;
    }
    
    const searchLower = term.toLowerCase();
    let matchCount = 0;
    
    rows.forEach(row => {
        removeAllMarks(row);
        const searchText = (row.getAttribute('data-search') || '').toLowerCase();
        const rowText = row.textContent.toLowerCase();
        
        if (searchText.includes(searchLower) || rowText.includes(searchLower)) {
            row.classList.remove('search-hidden');
            row.classList.add('search-match');
            highlightMatchesInRow(row, term);
            matchCount++;
        } else {
            row.classList.add('search-hidden');
            row.classList.remove('search-match');
        }
    });
    
    if (countBadge) {
        countBadge.textContent = matchCount;
        countBadge.style.display = matchCount > 0 ? 'inline-block' : 'none';
    }
    
    if (totalBadge) totalBadge.textContent = matchCount;
    
    if (noResultsRow) {
        noResultsRow.style.display = matchCount === 0 && rows.length > 0 ? '' : 'none';
    }
}

function removeAllMarks(row) {
    const marks = row.querySelectorAll('mark');
    if (marks.length === 0) return;
    marks.forEach(mark => {
        if (mark.parentNode) {
            const textNode = document.createTextNode(mark.textContent);
            mark.parentNode.replaceChild(textNode, mark);
        }
    });
    const cells = row.querySelectorAll('td');
    cells.forEach(cell => cell.normalize());
}

function highlightMatchesInRow(row, term) {
    if (!term || term.length === 0) return;
    const searchLower = term.toLowerCase();
    const termLength = term.length;
    const cells = row.querySelectorAll('td');
    
    cells.forEach(cell => {
        if (cell.querySelector('button') || cell.querySelector('a')) return;
        
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
            acceptNode: function(node) {
                if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        
        const textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        
        textNodes.forEach(textNode => {
            const text = textNode.textContent;
            const lowerText = text.toLowerCase();
            if (!lowerText.includes(searchLower)) return;
            
            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            let index = lowerText.indexOf(searchLower);
            
            while (index !== -1) {
                if (index > lastIndex) {
                    fragment.appendChild(document.createTextNode(text.substring(lastIndex, index)));
                }
                const mark = document.createElement('mark');
                mark.textContent = text.substring(index, index + termLength);
                fragment.appendChild(mark);
                lastIndex = index + termLength;
                index = lowerText.indexOf(searchLower, lastIndex);
            }
            if (lastIndex < text.length) {
                fragment.appendChild(document.createTextNode(text.substring(lastIndex)));
            }
            textNode.parentNode.replaceChild(fragment, textNode);
        });
    });
}

function clearLiveSearch() {
    const input = document.getElementById('liveSearchInput');
    if (input) { 
        input.value = ''; 
        performLiveSearch(''); 
        input.focus(); 
    }
}

// ============================================================
// SCROLL TABLE
// ============================================================
function scrollTable(direction) {
    const wrapper = document.getElementById('tableWrapper');
    if (!wrapper) return;
    wrapper.scrollBy({
        left: direction === 'left' ? -350 : 350,
        behavior: 'smooth'
    });
}

// ============================================================
// INFO MODAL
// ============================================================
function showInfo(filename, size, created) {
    document.getElementById('infoFilename').textContent = filename;
    document.getElementById('infoSize').textContent = size;
    document.getElementById('infoCreated').textContent = created;
    document.getElementById('infoModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeInfoModal() {
    document.getElementById('infoModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeInfoModal();
        const i = document.getElementById('liveSearchInput');
        if (i && i.value.length > 0) clearLiveSearch();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const i = document.getElementById('liveSearchInput');
        if (i) { i.focus(); i.select(); }
    }
});

// ============================================================
// FORM SUBMIT (Backup)
// ============================================================
document.getElementById('backupForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('createBackupBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Backup...';
    }
});

// ============================================================
// DARK MODE
// ============================================================
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    const btn = document.getElementById('darkModeToggle');
    
    if (document.body.classList.contains('dark-mode')) {
        btn.querySelector('i').className = 'fas fa-sun';
        btn.querySelector('span').textContent = 'Light Mode';
        localStorage.setItem('darkMode', 'enabled');
    } else {
        btn.querySelector('i').className = 'fas fa-moon';
        btn.querySelector('span').textContent = 'Dark Mode';
        localStorage.setItem('darkMode', 'disabled');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Restore dark mode
    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
        const btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.querySelector('i').className = 'fas fa-sun';
            btn.querySelector('span').textContent = 'Light Mode';
        }
    }
    
    // Auto-hide alerts
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.4s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 400);
        }, 5000);
    });
    
    console.log('%c 💾 Backup Management Loaded', 
        'background:#bb0404; color:white; padding:4px 12px; border-radius:4px; font-size:12px;');
});
</script>

</body>
</html>