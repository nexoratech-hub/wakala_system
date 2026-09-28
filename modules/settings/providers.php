<?php
// ================================================================
// FILE: modules/settings/providers.php
// PROVIDERS MANAGEMENT - RED THEME
// ✅ Live search, scroll buttons, toggle status, dark mode
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

// Only admin and super_admin can access settings
if ($role !== 'admin' && $role !== 'super_admin') {
    header('Location: ../dashboard/employee.php');
    exit();
}

$error = '';
$success = '';

// ============================================================
// GET FILTERS
// ============================================================
$filter_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ============================================================
// GET PROVIDERS
// ============================================================
try {
    $sql = "
        SELECT 
            p.*,
            (SELECT COUNT(*) FROM branch_providers WHERE provider_id = p.id) as branch_count
        FROM providers p
        WHERE 1=1
    ";
    $params = [];
    
    if (!empty($filter_type)) {
        $sql .= " AND p.provider_type = ?";
        $params[] = $filter_type;
    }
    
    if (!empty($filter_status)) {
        if ($filter_status === 'active') {
            $sql .= " AND p.is_active = 1";
        } elseif ($filter_status === 'inactive') {
            $sql .= " AND p.is_active = 0";
        }
    }
    
    if (!empty($search)) {
        $sql .= " AND (p.provider_name LIKE ? OR p.provider_code LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    
    $sql .= " ORDER BY p.is_active DESC, p.display_order ASC, p.provider_name ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Summary stats
    $total_providers = count($providers);
    $active_count = 0;
    $inactive_count = 0;
    $bank_count = 0;
    $mobile_count = 0;
    $other_count = 0;
    
    foreach ($providers as $p) {
        if ($p['is_active']) $active_count++;
        else $inactive_count++;
        
        switch ($p['provider_type']) {
            case 'bank': $bank_count++; break;
            case 'mobile_money': $mobile_count++; break;
            default: $other_count++; break;
        }
    }
    
} catch (PDOException $e) {
    error_log("Error loading providers: " . $e->getMessage());
    $providers = [];
    $total_providers = 0;
    $active_count = 0;
    $inactive_count = 0;
    $bank_count = 0;
    $mobile_count = 0;
    $other_count = 0;
}

// ============================================================
// HANDLE ADD PROVIDER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_provider'])) {
    try {
        $provider_code = strtoupper(trim($_POST['provider_code'] ?? ''));
        $provider_name = trim($_POST['provider_name'] ?? '');
        $provider_type = $_POST['provider_type'] ?? 'bank';
        $category = trim($_POST['category'] ?? 'Financial');
        $icon_class = trim($_POST['icon_class'] ?? 'fas fa-university');
        $color_code = trim($_POST['color_code'] ?? '#0B5ED7');
        $description = trim($_POST['description'] ?? '');
        $display_order = intval($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $requires_cash_balance = isset($_POST['requires_cash_balance']) ? 1 : 0;
        
        if (empty($provider_code) || empty($provider_name)) {
            throw new Exception('Provider code and name are required.');
        }
        
        // Check duplicates
        $stmt = $db->prepare("SELECT COUNT(*) FROM providers WHERE provider_code = ?");
        $stmt->execute([$provider_code]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Provider code already exists!');
        }
        
        $stmt = $db->prepare("
            INSERT INTO providers (
                provider_code, provider_name, provider_type, category,
                icon_class, color_code, description, display_order,
                is_active, is_default, requires_cash_balance, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([
            $provider_code, $provider_name, $provider_type, $category,
            $icon_class, $color_code, $description, $display_order,
            $is_active, $requires_cash_balance, $_SESSION['user_id']
        ]);
        
        $new_id = $db->lastInsertId();
        logActivity($_SESSION['user_id'], 'Add Provider', 'Settings', $new_id, null, json_encode([
            'provider_code' => $provider_code,
            'provider_name' => $provider_name
        ]));
        
        $success = 'Provider added successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = ($e->getCode() == 23000) ? 'Duplicate entry. Provider code already exists.' : 'Database error: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE EDIT PROVIDER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_provider'])) {
    try {
        $id = intval($_POST['provider_id_hidden'] ?? 0);
        $provider_code = strtoupper(trim($_POST['provider_code'] ?? ''));
        $provider_name = trim($_POST['provider_name'] ?? '');
        $provider_type = $_POST['provider_type'] ?? 'bank';
        $category = trim($_POST['category'] ?? 'Financial');
        $icon_class = trim($_POST['icon_class'] ?? 'fas fa-university');
        $color_code = trim($_POST['color_code'] ?? '#0B5ED7');
        $description = trim($_POST['description'] ?? '');
        $display_order = intval($_POST['display_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $requires_cash_balance = isset($_POST['requires_cash_balance']) ? 1 : 0;
        
        if ($id <= 0 || empty($provider_code) || empty($provider_name)) {
            throw new Exception('Invalid data.');
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM providers WHERE provider_code = ? AND id != ?");
        $stmt->execute([$provider_code, $id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Provider code already exists!');
        }
        
        $stmt = $db->prepare("
            UPDATE providers SET 
                provider_code = ?, provider_name = ?, provider_type = ?, category = ?,
                icon_class = ?, color_code = ?, description = ?, display_order = ?,
                is_active = ?, requires_cash_balance = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $provider_code, $provider_name, $provider_type, $category,
            $icon_class, $color_code, $description, $display_order,
            $is_active, $requires_cash_balance, $id
        ]);
        
        logActivity($_SESSION['user_id'], 'Edit Provider', 'Settings', $id, null, json_encode([
            'provider_code' => $provider_code,
            'provider_name' => $provider_name
        ]));
        
        $success = 'Provider updated successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = ($e->getCode() == 23000) ? 'Duplicate entry.' : 'Database error: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE DELETE PROVIDER
// ============================================================
if (isset($_GET['delete'])) {
    try {
        $id = intval($_GET['delete']);
        
        $stmt = $db->prepare("SELECT provider_code, provider_name FROM providers WHERE id = ?");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$provider) {
            throw new Exception('Provider not found.');
        }
        
        // Check if used in branch_providers
        $stmt = $db->prepare("SELECT COUNT(*) FROM branch_providers WHERE provider_id = ?");
        $stmt->execute([$id]);
        $usage_count = $stmt->fetchColumn();
        
        if ($usage_count > 0) {
            throw new Exception("Cannot delete provider. It is assigned to {$usage_count} branch(es). Deactivate instead.");
        }
        
        $stmt = $db->prepare("DELETE FROM providers WHERE id = ?");
        $stmt->execute([$id]);
        
        logActivity($_SESSION['user_id'], 'Delete Provider', 'Settings', $id, null, json_encode([
            'provider_code' => $provider['provider_code'],
            'provider_name' => $provider['provider_name']
        ]));
        
        $success = 'Provider deleted successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// ============================================================
// HANDLE TOGGLE STATUS
// ============================================================
if (isset($_GET['toggle'])) {
    try {
        $id = intval($_GET['toggle']);
        
        $stmt = $db->prepare("SELECT provider_name, is_active FROM providers WHERE id = ?");
        $stmt->execute([$id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($provider) {
            $new_status = $provider['is_active'] ? 0 : 1;
            $stmt = $db->prepare("UPDATE providers SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            
            logActivity($_SESSION['user_id'], 'Toggle Provider Status', 'Settings', $id, null, json_encode([
                'provider_name' => $provider['provider_name'],
                'new_status' => $new_status
            ]));
            
            $success = 'Provider status updated successfully!';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

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
                <h2><i class="fas fa-university" style="color:#bb0404;"></i> Providers Management</h2>
                <p class="text-muted">Manage service providers for the system</p>
            </div>
            <div class="header-right">
                <button onclick="openAddModal()" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Add Provider
                </button>
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
                <div class="summary-icon"><i class="fas fa-handshake"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Total Providers</span>
                    <span class="summary-value"><?php echo number_format($total_providers); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-green">
                <div class="summary-icon"><i class="fas fa-university"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Banks</span>
                    <span class="summary-value"><?php echo number_format($bank_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-purple">
                <div class="summary-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Mobile Money</span>
                    <span class="summary-value"><?php echo number_format($mobile_count); ?></span>
                </div>
            </div>
            
            <div class="summary-card summary-orange">
                <div class="summary-icon"><i class="fas fa-toggle-on"></i></div>
                <div class="summary-info">
                    <span class="summary-label">Active</span>
                    <span class="summary-value"><?php echo number_format($active_count); ?></span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="" class="filter-form">
                <div class="filter-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" class="form-control" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Name or code...">
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-tags"></i> Type</label>
                    <select name="type" class="form-control">
                        <option value="">All Types</option>
                        <option value="bank" <?php echo $filter_type === 'bank' ? 'selected' : ''; ?>>Bank</option>
                        <option value="mobile_money" <?php echo $filter_type === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                        <option value="other" <?php echo $filter_type === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><i class="fas fa-toggle-on"></i> Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="providers.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Providers Table -->
        <div class="table-container">
            
            <!-- Table Header: Search Left, Scroll Right -->
            <div class="table-header">
                <div class="table-header-left">
                    <h4><i class="fas fa-list"></i> Providers List</h4>
                    <span class="count-badge" id="totalCountBadge"><?php echo count($providers); ?></span>
                </div>
                
                <div class="table-header-right">
                    <div class="table-search-live">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="liveSearchInput" 
                               placeholder="Search providers..."
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
                <table class="data-table" id="providersTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Provider</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Branches</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="providersTableBody">
                        <?php if (count($providers) > 0): ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($providers as $p): 
                                $provider_color = $p['color_code'] ?? '#0B5ED7';
                                $provider_icon = $p['icon_class'] ?? 'fas fa-university';
                                $p_type = $p['provider_type'] ?? 'bank';
                                $p_type_label = ucfirst(str_replace('_', ' ', $p_type));
                                
                                $search_text = strtolower(
                                    $p['provider_name'] . ' ' . 
                                    $p['provider_code'] . ' ' . 
                                    $p_type . ' ' . 
                                    ($p['category'] ?? '')
                                );
                            ?>
                                <tr class="provider-row" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <div class="provider-cell">
                                            <div class="provider-icon" style="background: <?php echo htmlspecialchars($provider_color); ?>;">
                                                <i class="<?php echo htmlspecialchars($provider_icon); ?>"></i>
                                            </div>
                                            <div class="provider-info">
                                                <span class="provider-name"><?php echo htmlspecialchars($p['provider_name']); ?></span>
                                                <?php if (!empty($p['description'])): ?>
                                                    <span class="provider-description"><?php echo htmlspecialchars($p['description']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="code-badge"><?php echo htmlspecialchars($p['provider_code']); ?></span>
                                    </td>
                                    <td>
                                        <span class="type-badge type-<?php echo htmlspecialchars($p_type); ?>">
                                            <i class="fas fa-<?php echo $p_type === 'mobile_money' ? 'mobile-alt' : ($p_type === 'bank' ? 'university' : 'wallet'); ?>"></i>
                                            <?php echo htmlspecialchars($p_type_label); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="category-text"><?php echo htmlspecialchars($p['category'] ?? 'Financial'); ?></span>
                                    </td>
                                    <td>
                                        <span class="branch-count-badge" title="<?php echo $p['branch_count']; ?> branches">
                                            <i class="fas fa-store-alt"></i> <?php echo number_format($p['branch_count']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="order-badge"><?php echo intval($p['display_order']); ?></span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $p['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick='editProvider(<?php echo json_encode($p); ?>)' 
                                                    class="btn-action btn-edit" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <a href="?toggle=<?php echo $p['id']; ?>" 
                                               class="btn-action <?php echo $p['is_active'] ? 'btn-warning' : 'btn-success'; ?>" 
                                               title="<?php echo $p['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                               onclick="return confirm('<?php echo $p['is_active'] ? 'Deactivate' : 'Activate'; ?> this provider?');">
                                                <i class="fas fa-<?php echo $p['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                            </a>
                                            
                                            <?php if ($p['branch_count'] == 0): ?>
                                                <a href="?delete=<?php echo $p['id']; ?>" 
                                                   class="btn-action btn-delete" 
                                                   title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this provider?');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-action btn-disabled" 
                                                        title="Cannot delete - used by branches" 
                                                        disabled>
                                                    <i class="fas fa-lock"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-university" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                    <p style="color:var(--text-muted);">No providers found</p>
                                    <p style="color:var(--text-muted);font-size:12px;">Click "Add Provider" to create one</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <!-- No search results row -->
                        <tr id="noSearchResultsRow" style="display:none;">
                            <td colspan="9" class="no-data">
                                <i class="fas fa-search-minus" style="font-size:48px;color:var(--text-light);display:block;margin:20px 0;"></i>
                                <p style="color:var(--text-muted);">No providers match your search</p>
                                <button type="button" class="btn btn-secondary" onclick="clearLiveSearch()" style="margin-top:12px;">
                                    <i class="fas fa-times"></i> Clear Search
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================================================
             ADD PROVIDER MODAL
             ============================================================ -->
        <div id="addModal" class="modal" style="display:none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h4><i class="fas fa-plus-circle"></i> Add New Provider</h4>
                    <button class="modal-close" onclick="closeAddModal()">&times;</button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        
                        <!-- Basic Info -->
                        <div class="modal-section">
                            <h5><i class="fas fa-info-circle"></i> Basic Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Provider Name <span class="required">*</span></label>
                                    <input type="text" name="provider_name" class="form-control" placeholder="e.g., NMB Bank" required>
                                </div>
                                <div class="form-group">
                                    <label>Provider Code <span class="required">*</span></label>
                                    <input type="text" name="provider_code" class="form-control" placeholder="e.g., NMB" required maxlength="20">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Provider Type <span class="required">*</span></label>
                                    <select name="provider_type" class="form-control" required>
                                        <option value="bank">Bank</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Category</label>
                                    <input type="text" name="category" class="form-control" value="Financial" maxlength="50">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea>
                            </div>
                        </div>
                        
                        <!-- Appearance -->
                        <div class="modal-section">
                            <h5><i class="fas fa-palette"></i> Appearance</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Icon Class (Font Awesome)</label>
                                    <input type="text" name="icon_class" class="form-control" value="fas fa-university" placeholder="e.g., fas fa-university">
                                    <small class="form-text">Examples: fas fa-university, fas fa-mobile-alt</small>
                                </div>
                                <div class="form-group">
                                    <label>Color Code</label>
                                    <input type="color" name="color_code" class="form-control color-input" value="#0B5ED7">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Settings -->
                        <div class="modal-section">
                            <h5><i class="fas fa-cog"></i> Settings</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Display Order</label>
                                    <input type="number" name="display_order" class="form-control" value="0" min="0">
                                    <small class="form-text">Lower numbers appear first</small>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_active" value="1" checked>
                                        <span>Active</span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="requires_cash_balance" value="1" checked>
                                        <span>Requires Cash Balance</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeAddModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="add_provider" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Provider
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================================
             EDIT PROVIDER MODAL
             ============================================================ -->
        <div id="editModal" class="modal" style="display:none;">
            <div class="modal-content modal-large">
                <div class="modal-header">
                    <h4><i class="fas fa-edit"></i> Edit Provider</h4>
                    <button class="modal-close" onclick="closeEditModal()">&times;</button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="provider_id_hidden" id="edit_provider_id_hidden">
                    <input type="hidden" name="edit_provider" value="1">
                    
                    <div class="modal-body">
                        
                        <div class="modal-section">
                            <h5><i class="fas fa-info-circle"></i> Basic Information</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Provider Name <span class="required">*</span></label>
                                    <input type="text" name="provider_name" id="edit_provider_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Provider Code <span class="required">*</span></label>
                                    <input type="text" name="provider_code" id="edit_provider_code" class="form-control" required maxlength="20">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Provider Type <span class="required">*</span></label>
                                    <select name="provider_type" id="edit_provider_type" class="form-control" required>
                                        <option value="bank">Bank</option>
                                        <option value="mobile_money">Mobile Money</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Category</label>
                                    <input type="text" name="category" id="edit_category" class="form-control" maxlength="50">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        
                        <div class="modal-section">
                            <h5><i class="fas fa-palette"></i> Appearance</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Icon Class (Font Awesome)</label>
                                    <input type="text" name="icon_class" id="edit_icon_class" class="form-control">
                                    <small class="form-text">Examples: fas fa-university, fas fa-mobile-alt</small>
                                </div>
                                <div class="form-group">
                                    <label>Color Code</label>
                                    <input type="color" name="color_code" id="edit_color_code" class="form-control color-input">
                                </div>
                            </div>
                        </div>
                        
                        <div class="modal-section">
                            <h5><i class="fas fa-cog"></i> Settings</h5>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Display Order</label>
                                    <input type="number" name="display_order" id="edit_display_order" class="form-control" min="0">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                                        <span>Active</span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="requires_cash_balance" id="edit_requires_cash_balance" value="1">
                                        <span>Requires Cash Balance</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Provider
                        </button>
                    </div>
                </form>
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

*, *::before, *::after {
    box-sizing: border-box;
}

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
    font-size: 20px;
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

/* FILTER BAR */
.filter-bar {
    background: var(--bg-card);
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
    width: 100%;
}

.filter-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.filter-group label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.filter-group label i { color: #bb0404; }

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.filter-actions .btn {
    flex: 1;
    justify-content: center;
}

/* FORM CONTROLS */
.form-control {
    padding: 9px 12px;
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

.color-input {
    height: 42px;
    padding: 4px;
    cursor: pointer;
}

/* BUTTONS */
.btn {
    padding: 9px 18px;
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
.btn-primary:hover { background: #8a0303; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(187,4,4,0.3); }

.btn-secondary {
    background: var(--bg-table-even);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
.btn-secondary:hover { background: var(--bg-table-hover); }

/* TABLE CONTAINER */
.table-container {
    background: var(--bg-card);
    border-radius: 10px;
    border: 1px solid var(--border-color);
    width: 100%;
    overflow: hidden;
}

/* TABLE HEADER */
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
    min-width: 1100px;
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
    padding: 10px 12px;
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

/* PROVIDER CELL */
.provider-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.provider-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: #FFFFFF;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,0.5);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.provider-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.provider-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}

.provider-description {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 200px;
}

.code-badge {
    display: inline-block;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 800;
    color: #bb0404;
    background: #FEE2E2;
    padding: 3px 10px;
    border-radius: 6px;
    white-space: nowrap;
    border: 1px solid #FCA5A5;
}

html.dark-mode .code-badge {
    background: #7F1D1D;
    color: #FCA5A5;
    border-color: #DC2626;
}

.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
    border: 1.5px solid;
}

.type-bank { background: #DBEAFE; color: #1E40AF; border-color: #93C5FD; }
.type-mobile_money { background: #EDE9FE; color: #7C3AED; border-color: #C4B5FD; }
.type-other { background: #FEF3C7; color: #B45309; border-color: #FCD34D; }

html.dark-mode .type-bank { background: #1E3A5F; color: #60A5FA; border-color: #3B82F6; }
html.dark-mode .type-mobile_money { background: #4C1D95; color: #C4B5FD; border-color: #8B5CF6; }
html.dark-mode .type-other { background: #5F3A1E; color: #FBBF24; border-color: #D97706; }

.category-text {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.branch-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    background: #F0FDF4;
    color: #047857;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid #6EE7B7;
}

html.dark-mode .branch-count-badge {
    background: #065F46;
    color: #6EE7B7;
    border-color: #059669;
}

.order-badge {
    display: inline-block;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-secondary);
    background: var(--bg-table-even);
    padding: 3px 10px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
}

.status-badge {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.status-badge.active {
    background: #D1FAE5;
    color: #065F46;
    border: 1px solid #6EE7B7;
}

.status-badge.inactive {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FCA5A5;
}

html.dark-mode .status-badge.active { background: #065F46; color: #6EE7B7; }
html.dark-mode .status-badge.inactive { background: #7F1D1D; color: #FCA5A5; }

.action-buttons { display: flex; gap: 4px; }

.btn-action {
    width: 30px;
    height: 30px;
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

.btn-edit { background: #DBEAFE; color: #1E40AF; }
.btn-edit:hover { background: #1E40AF; color: #ffffff; }

.btn-success { background: #D1FAE5; color: #065F46; }
.btn-success:hover { background: #065F46; color: #ffffff; }

.btn-warning { background: #FEF3C7; color: #B45309; }
.btn-warning:hover { background: #B45309; color: #ffffff; }

.btn-delete { background: #FEE2E2; color: #991B1B; }
.btn-delete:hover { background: #991B1B; color: #ffffff; }

.btn-disabled {
    background: var(--bg-table-even);
    color: var(--text-light);
    cursor: not-allowed;
    opacity: 0.5;
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
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.modal-large { max-width: 800px; }

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    background: linear-gradient(135deg, #bb0404 0%, #8a0303 100%);
    color: #FFFFFF;
    border-radius: 12px 12px 0 0;
    position: sticky;
    top: 0;
    z-index: 10;
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
    max-height: calc(90vh - 160px);
    overflow-y: auto;
}

.modal-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px dashed var(--border-color);
}

.modal-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.modal-section h5 {
    font-size: 13px;
    font-weight: 700;
    color: #bb0404;
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.modal-section h5 i { font-size: 14px; }

.modal-footer {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 20px 24px;
    border-top: 1px solid var(--border-color);
    background: var(--bg-table-even);
    border-radius: 0 0 12px 12px;
    position: sticky;
    bottom: 0;
}

.modal .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 14px;
}

.modal .form-row:last-child { margin-bottom: 0; }

.modal .form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.modal .form-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
}

.modal .form-group label .required { color: #DC2626; }

.modal .form-text {
    font-size: 11px;
    color: var(--text-muted);
    font-style: italic;
}

.modal .checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    cursor: pointer;
    padding: 9px 0;
}

.modal .checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #bb0404;
}

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
    .filter-form { grid-template-columns: 1fr; }
    .filter-actions { flex-direction: row; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .header-right .btn { flex: 1; justify-content: center; }
    .modal .form-row { grid-template-columns: 1fr; }
    .modal-footer { flex-direction: column; }
    .modal-footer .btn { width: 100%; justify-content: center; }
    .modal-body { max-height: 70vh; }
    
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
}
</style>

<script>
// ============================================================
// LIVE SEARCH
// ============================================================
function performLiveSearch(searchTerm) {
    const tableBody = document.getElementById('providersTableBody');
    if (!tableBody) return;
    
    const rows = tableBody.querySelectorAll('tr.provider-row');
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
        if (cell.querySelector('img') && cell.textContent.trim() === '') return;
        
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
            acceptNode: function(node) {
                if (!node.textContent.trim()) return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'MARK') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'I') return NodeFilter.FILTER_REJECT;
                if (node.parentNode.tagName === 'BUTTON') return NodeFilter.FILTER_REJECT;
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
// ADD MODAL
// ============================================================
function openAddModal() {
    document.getElementById('addModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeAddModal() {
    document.getElementById('addModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ============================================================
// EDIT MODAL
// ============================================================
function editProvider(provider) {
    document.getElementById('edit_provider_id_hidden').value = provider.id;
    document.getElementById('edit_provider_name').value = provider.provider_name || '';
    document.getElementById('edit_provider_code').value = provider.provider_code || '';
    document.getElementById('edit_provider_type').value = provider.provider_type || 'bank';
    document.getElementById('edit_category').value = provider.category || 'Financial';
    document.getElementById('edit_icon_class').value = provider.icon_class || 'fas fa-university';
    document.getElementById('edit_color_code').value = provider.color_code || '#0B5ED7';
    document.getElementById('edit_description').value = provider.description || '';
    document.getElementById('edit_display_order').value = provider.display_order || 0;
    document.getElementById('edit_is_active').checked = provider.is_active == 1;
    document.getElementById('edit_requires_cash_balance').checked = provider.requires_cash_balance == 1;
    
    document.getElementById('editModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ============================================================
// CLOSE MODALS
// ============================================================
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddModal();
        closeEditModal();
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
    
    console.log('%c 🏦 Providers Management Loaded', 
        'background:#bb0404; color:white; padding:4px 12px; border-radius:4px; font-size:12px;');
});
</script>

</body>
</html>