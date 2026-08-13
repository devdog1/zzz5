<?php
// admin-diagnostics.php - Core System Integrity and Diagnostics Panel
require_once __DIR__ . '/functions.php';

// Enforce admin permission
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$db_status = "Online";
$db_error = "";
$tables_found = [];

try {
    $stmt = $db->query("SHOW TABLES");
    $tables_found = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $db_status = "Offline / Connection Error";
    $db_error = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-stethoscope text-danger me-2"></i>System Diagnostics & Integrity</h1>
        <p class="text-muted">Validate database schemas, inspect active plugin table prefixes, check environment configurations, and monitor security controls.</p>
    </div>
</div>

<div class="row">
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
