<?php
// admin-diagnostics.php - Core System Integrity, Diagnostics, and Portal Branding Panel
require_once __DIR__ . '/functions.php';

// Enforce admin permission
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$msg = '';
$err = '';
$db_status = "Online";
$db_error = "";
$tables_found = [];

// Handle Site Settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_site_settings') {
        $site_name = trim($_POST['site_name'] ?? '');
        if (!empty($site_name)) {
            set_setting('site_name', $site_name);
            $msg = "Site Name updated to '<strong>" . htmlspecialchars($site_name) . "</strong>' successfully!";
            log_action('ADMIN_SITE_NAME_UPDATE', ['site_name' => $site_name]);
        } else {
            $err = "Site Name cannot be empty.";
        }
    }
}

try {
    $stmt = $db->query("SHOW TABLES");
    $tables_found = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $db_status = "Offline / Connection Error";
    $db_error = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-stethoscope text-danger me-2"></i>System Diagnostics & Settings</h1>
        <p class="text-muted">Manage portal site branding, validate database schemas, inspect active plugin table prefixes, and monitor environment parameters.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row text-start mb-4">
    <!-- Portal Branding Form -->
    <div class="col-lg-12">
        <div class="card shadow-sm border-primary">
            <div class="card-header bg-primary text-white">
                <i class="fa-solid fa-pen-to-square me-2"></i>Portal Branding & Site Settings
            </div>
            <div class="card-body">
                <form method="POST" class="row align-items-end g-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_site_settings">
                    <div class="col-md-8">
                        <label for="site_name" class="form-label fw-bold">Portal Site Name (DB Parameter: <code>site_name</code>)</label>
                        <input type="text" name="site_name" id="site_name" class="form-control" value="<?= htmlspecialchars(get_setting('site_name', 'Framework Portal')) ?>" required>
                        <div class="form-text">This name appears on the browser title bar, login screen, and top navigation brand logo.</div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Site Name
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row text-start">
    <!-- Server Environment & Database Status -->
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-server me-2"></i>Environment & Connection Parameters
            </div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <tr>
                            <td><strong>PHP Version:</strong></td>
                            <td><code><?= PHP_VERSION ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Operating System:</strong></td>
                            <td><code><?= PHP_OS_NAME ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Database Engine Status:</strong></td>
                            <td>
                                <?php if ($db_status === 'Online'): ?>
                                    <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Online</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fa-solid fa-circle-exclamation me-1"></i><?= htmlspecialchars($db_status) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Active Database Name:</strong></td>
                            <td><code>base_framework</code></td>
                        </tr>
                        <tr>
                            <td><strong>Sessions State:</strong></td>
                            <td><span class="badge bg-success">Active (<?= session_id() ? 'PHP Session OK' : 'PHP Session Fail' ?>)</span></td>
                        </tr>
                    </tbody>
                </table>
                <?php if ($db_error): ?>
                    <div class="alert alert-danger mt-3 mb-0 small">
                        <strong>DB Connection Error:</strong> <?= htmlspecialchars($db_error) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- DB Table Prefix Scanner -->
    <div class="col-lg-6">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-database me-2 text-warning"></i>Active Table Schema Scanner
            </div>
            <div class="card-body">
                <h6 class="fw-bold mb-2">Registered Tables Found (<?= count($tables_found) ?>):</h6>
                <div style="max-height: 250px; overflow-y: auto;" class="border p-2 bg-light rounded">
                    <?php if (empty($tables_found)): ?>
                        <span class="text-muted small">No tables found in base_framework DB.</span>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 small font-monospace">
                            <?php foreach ($tables_found as $table):
                                $is_plugin = (strpos($table, 'plug_') === 0);
                                $badge = $is_plugin ? '<span class="badge bg-info text-dark fs-8">Plugin</span>' : '<span class="badge bg-secondary fs-8">Core</span>';
                            ?>
                                <li class="py-1 border-bottom d-flex justify-content-between align-items-center">
                                    <span><i class="fa-solid fa-table me-1 text-muted"></i> <?= htmlspecialchars($table) ?></span>
                                    <?= $badge ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="form-text mt-2 small text-muted">Core tables are fully managed by base migrations. Tables with the <code>plug_*</code> prefix are isolated plugin-specific data structures.</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
