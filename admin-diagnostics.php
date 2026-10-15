<?php
// admin-diagnostics.php - Core System Integrity, Diagnostics, Health Checks & Table Inspector
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

// Handle Admin Actions
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
    } elseif ($action === 'save_broadcast_banner') {
        $enabled = !empty($_POST['broadcast_banner_enabled']) ? '1' : '0';
        $message = trim($_POST['broadcast_banner_message'] ?? '');
        $type = trim($_POST['broadcast_banner_type'] ?? 'info');

        set_setting('broadcast_banner_enabled', $enabled);
        set_setting('broadcast_banner_message', $message);
        set_setting('broadcast_banner_type', $type);

        $msg = "Broadcast Announcement Banner settings updated successfully!";
        log_action('ADMIN_BROADCAST_BANNER_UPDATE', ['enabled' => $enabled, 'type' => $type, 'message' => $message]);
    } elseif ($action === 'run_health_checks') {
        $health_results = run_platform_health_checks($db);
        $_SESSION['platform_health_check'] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'results' => $health_results
        ];
        $msg = "Platform Health Checks executed successfully! Detailed diagnostics updated below.";
        log_action('PLATFORM_HEALTH_CHECK_RUN', ['passed' => $health_results['summary']['pass'], 'warnings' => $health_results['summary']['warn'], 'failed' => $health_results['summary']['fail']]);
    }
}

// Function to run comprehensive platform health checks
function run_platform_health_checks($db) {
    $checks = [];
    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];

    // Helper to log check result
    $addCheck = function($category, $title, $status, $message, $details = null) use (&$checks, &$summary) {
        $checks[] = [
            'category' => $category,
            'title'    => $title,
            'status'   => $status, // 'pass', 'warn', 'fail'
            'message'  => $message,
            'details'  => $details
        ];
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    };

    // 1. Database Connectivity & Ping
    try {
        $start = microtime(true);
        $db->query("SELECT 1");
        $latency = round((microtime(true) - $start) * 1000, 2);
        $addCheck('Database Engine', 'MySQL Database Connectivity', 'pass', "Connection responsive (Query latency: {$latency} ms).");
    } catch (Exception $e) {
        $addCheck('Database Engine', 'MySQL Database Connectivity', 'fail', "Database connection error: " . $e->getMessage());
    }

    // 2. Core Framework Database Schema Integrity
    $expected_core_tables = [
        'users', 'roles', 'permissions', 'user_roles', 'default_roles',
        'azure_group_roles', 'role_permissions', 'user_permissions',
        'denied_permissions', 'settings', 'audit_logs', 'active_plugins',
        'scheduled_tasks', 'scheduled_tasks_logs', 'user_widget_preferences',
        'email_logs', 'event_queue'
    ];

    try {
        $stmt = $db->query("SHOW TABLES");
        $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing_core = array_diff($expected_core_tables, $existing_tables);
        if (empty($missing_core)) {
            $addCheck('Database Schema', 'Core Framework Database Schema', 'pass', "All " . count($expected_core_tables) . " core framework tables present and verified.");
        } else {
            $addCheck('Database Schema', 'Core Framework Database Schema', 'fail', "Missing core database table(s): " . implode(', ', $missing_core));
        }
    } catch (Exception $e) {
        $addCheck('Database Schema', 'Core Framework Database Schema', 'fail', "Unable to inspect schema tables: " . $e->getMessage());
    }

    // 3. Plugin Database Tables Integrity
    $pluginManager = PluginManager::getInstance();
    $active_plugins = $pluginManager->getActivePlugins();
    if (!empty($active_plugins)) {
        $plugin_tables_found = 0;
        foreach ($existing_tables ?? [] as $t) {
            if (strpos($t, 'plug_') === 0) {
                $plugin_tables_found++;
            }
        }
        $addCheck('Plugin Architecture', 'Active Plugin Isolation Tables', 'pass', "Found {$plugin_tables_found} plugin data table(s) across " . count($active_plugins) . " active plugin(s).");
    } else {
        $addCheck('Plugin Architecture', 'Active Plugin Isolation Tables', 'warn', "No plugins are currently activated.");
    }

    // 4. File System & Directory Writeability Permissions
    $directories_to_test = [
        'plugins/'   => __DIR__ . '/plugins',
        'languages/' => __DIR__ . '/languages',
        'tmp/'       => sys_get_temp_dir()
    ];

    foreach ($directories_to_test as $name => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        if (is_writable($path)) {
            $addCheck('File System', "Directory Permission: {$name}", 'pass', "Directory '{$name}' is writable.");
        } else {
            $addCheck('File System', "Directory Permission: {$name}", 'warn', "Directory '{$name}' exists but is not writable by PHP process.");
        }
    }

    // 5. Environment Requirements & PHP Extensions
    if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
        $addCheck('PHP Runtime', 'PHP Engine Version', 'pass', "PHP version " . PHP_VERSION . " meets framework standards (>= 8.0).");
    } else {
        $addCheck('PHP Runtime', 'PHP Engine Version', 'warn', "PHP version " . PHP_VERSION . " is older than recommended PHP 8.0.");
    }

    $required_exts = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'curl', 'openssl'];
    $missing_exts = [];
    foreach ($required_exts as $ext) {
        if (!extension_loaded($ext)) {
            $missing_exts[] = $ext;
        }
    }
    if (empty($missing_exts)) {
        $addCheck('PHP Runtime', 'Required PHP Extensions', 'pass', "All required PHP extensions (" . implode(', ', $required_exts) . ") are loaded.");
    } else {
        $addCheck('PHP Runtime', 'Required PHP Extensions', 'fail', "Missing required PHP extension(s): " . implode(', ', $missing_exts));
    }

    // 6. Background Task Scheduler Integrity
    if (class_exists('Scheduler')) {
        $scheduler = Scheduler::getInstance();
        $failed_tasks = $scheduler->getFailedTasksByPlugin();
        $total_failed = 0;
        foreach ($failed_tasks as $ft_list) {
            $total_failed += count($ft_list);
        }

        if ($total_failed === 0) {
            $addCheck('Task Scheduler', 'Background Crons Execution', 'pass', "All background scheduled tasks operating without execution failures.");
        } else {
            $addCheck('Task Scheduler', 'Background Crons Execution', 'warn', "Detected {$total_failed} failed background task execution(s). Check Task Scheduler panel.");
        }
    }

    // 7. Outbound Email Delivery Engine
    $email_from = get_setting('email_from_address');
    $smtp_host = get_setting('email_smtp_host');
    if (!empty($email_from) && !empty($smtp_host)) {
        $addCheck('Mailer Engine', 'Outbound SMTP Settings', 'pass', "Mailer configured with From: {$email_from} and SMTP Host: {$smtp_host}.");
    } else {
        $addCheck('Mailer Engine', 'Outbound SMTP Settings', 'warn', "Outbound email server settings incomplete. Configure parameters in Email Settings.");
    }

    // 8. Event Bus Queue Health
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM event_queue WHERE status = 'failed'");
        $failed_events = $stmt->fetchColumn();
        if ($failed_events == 0) {
            $addCheck('Event Queue', 'Asynchronous Event Bus Queue', 'pass', "Event bus queue healthy with 0 failed events.");
        } else {
            $addCheck('Event Queue', 'Asynchronous Event Bus Queue', 'warn', "Detected {$failed_events} failed event queue execution(s).");
        }
    } catch (Exception $e) {
        $addCheck('Event Queue', 'Asynchronous Event Bus Queue', 'warn', "Event bus queue table pending check.");
    }

    return [
        'summary' => $summary,
        'checks' => $checks
    ];
}

// Retrieve DB Tables & Full Column Structures + Sample Rows
$table_details = [];
try {
    $stmt = $db->query("SHOW TABLES");
    $tables_found = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables_found as $table) {
        // Sanitize table name with backticks for raw metadata queries
        $quoted_table = "`" . str_replace("`", "``", $table) . "`";

        // 1. Structure / Columns
        $col_stmt = $db->query("SHOW FULL COLUMNS FROM {$quoted_table}");
        $columns = $col_stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Total Row Count
        $count_stmt = $db->query("SELECT COUNT(*) FROM {$quoted_table}");
        $row_count = (int)$count_stmt->fetchColumn();

        // 3. Example Sample Records (top 5 rows)
        $sample_stmt = $db->query("SELECT * FROM {$quoted_table} LIMIT 5");
        $sample_rows = $sample_stmt->fetchAll(PDO::FETCH_ASSOC);

        $is_plugin = (strpos($table, 'plug_') === 0);
        $plugin_slug = null;
        if ($is_plugin) {
            // Extract plugin slug format plug_{slug}_table
            $parts = explode('_', $table, 3);
            if (count($parts) >= 2) {
                $plugin_slug = $parts[1];
            }
        }

        $table_details[$table] = [
            'name'        => $table,
            'is_plugin'   => $is_plugin,
            'plugin_slug' => $plugin_slug,
            'columns'     => $columns,
            'row_count'   => $row_count,
            'sample_rows' => $sample_rows
        ];
    }
} catch (Exception $e) {
    $db_status = "Offline / Connection Error";
    $db_error = $e->getMessage();
}

$last_health_check = $_SESSION['platform_health_check'] ?? null;

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-stethoscope text-danger me-2"></i>System Diagnostics & Health Checks</h1>
        <p class="text-muted">Execute admin-triggered platform health checks, manage portal site settings, inspect database schema structures, and view sample records across core and plugin tables.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Admin Triggered Platform Health Checks Panel -->
<div class="card shadow-sm border-danger mb-4 text-start">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-bold fs-5"><i class="fa-solid fa-heart-pulse me-2"></i>Platform Health Check Diagnostic Runner</span>
        <form method="POST" class="m-0">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="run_health_checks">
            <button type="submit" class="btn btn-sm btn-light fw-bold text-danger">
                <i class="fa-solid fa-play me-1"></i>Run Platform Health Checks
            </button>
        </form>
    </div>
    <div class="card-body">
        <?php if ($last_health_check): ?>
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div>
                    <span class="text-muted small">Last Health Check Executed:</span>
                    <strong class="text-dark font-monospace"><?= htmlspecialchars($last_health_check['timestamp']) ?></strong>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-success fs-6"><i class="fa-solid fa-circle-check me-1"></i><?= $last_health_check['results']['summary']['pass'] ?> Passed</span>
                    <span class="badge bg-warning text-dark fs-6"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= $last_health_check['results']['summary']['warn'] ?> Warnings</span>
                    <span class="badge bg-danger fs-6"><i class="fa-solid fa-circle-xmark me-1"></i><?= $last_health_check['results']['summary']['fail'] ?> Failed</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%;">Category</th>
                            <th style="width: 25%;">Diagnostic Test</th>
                            <th style="width: 12%;">Status</th>
                            <th>Details & Diagnostics</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($last_health_check['results']['checks'] as $chk): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($chk['category']) ?></span></td>
                                <td><strong><?= htmlspecialchars($chk['title']) ?></strong></td>
                                <td>
                                    <?php if ($chk['status'] === 'pass'): ?>
                                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Passed</span>
                                    <?php elseif ($chk['status'] === 'warn'): ?>
                                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i>Warning</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-secondary"><?= htmlspecialchars($chk['message']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fa-solid fa-laptop-medical text-muted fs-1 mb-2"></i>
                <p class="text-muted mb-3">No platform health check diagnostic run has been triggered yet during this session.</p>
                <form method="POST" class="d-inline-block">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="run_health_checks">
                    <button type="submit" class="btn btn-danger">
                        <i class="fa-solid fa-play me-1"></i>Trigger Initial Health Check Run
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row text-start mb-4">
    <!-- Portal Branding Form -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-primary h-100">
            <div class="card-header bg-primary text-white">
                <i class="fa-solid fa-pen-to-square me-2"></i>Portal Branding & Site Settings
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <form method="POST" class="row align-items-end g-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_site_settings">
                    <div class="col-md-12">
                        <label for="site_name" class="form-label fw-bold">Portal Site Name (DB Parameter: <code>site_name</code>)</label>
                        <input type="text" name="site_name" id="site_name" class="form-control" value="<?= htmlspecialchars(get_setting('site_name', 'Framework Portal')) ?>" required>
                        <div class="form-text">This name appears on the browser title bar, login screen, and top navigation brand logo.</div>
                    </div>
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Site Name
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Site-Wide Broadcast Announcement Banner Form -->
    <div class="col-lg-6">
        <div class="card shadow-sm border-warning h-100">
            <div class="card-header bg-warning text-dark">
                <i class="fa-solid fa-bullhorn me-2"></i>Site-Wide Broadcast Announcement Banner
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="save_broadcast_banner">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="broadcast_banner_enabled" id="broadcast_banner_enabled" value="1" <?= get_setting('broadcast_banner_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="broadcast_banner_enabled">Enable Broadcast Announcement Banner</label>
                    </div>

                    <div class="mb-3">
                        <label for="broadcast_banner_message" class="form-label small fw-bold">Banner Message HTML / Text</label>
                        <input type="text" name="broadcast_banner_message" id="broadcast_banner_message" class="form-control form-control-sm" value="<?= htmlspecialchars(get_setting('broadcast_banner_message', 'Scheduled system maintenance tonight at 23:00 UTC.')) ?>" placeholder="e.g. Scheduled maintenance at 23:00 UTC.">
                    </div>

                    <div class="mb-3">
                        <label for="broadcast_banner_type" class="form-label small fw-bold">Banner Alert Style</label>
                        <select name="broadcast_banner_type" id="broadcast_banner_type" class="form-select form-select-sm">
                            <option value="info" <?= get_setting('broadcast_banner_type', 'info') === 'info' ? 'selected' : '' ?>>Info (Blue)</option>
                            <option value="warning" <?= get_setting('broadcast_banner_type', 'info') === 'warning' ? 'selected' : '' ?>>Warning (Yellow)</option>
                            <option value="danger" <?= get_setting('broadcast_banner_type', 'info') === 'danger' ? 'selected' : '' ?>>Danger (Red)</option>
                            <option value="success" <?= get_setting('broadcast_banner_type', 'info') === 'success' ? 'selected' : '' ?>>Success (Green)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-sm btn-warning text-dark fw-bold w-100">
                        <i class="fa-solid fa-bullhorn me-1"></i>Save Banner Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row text-start mb-4">
    <!-- Server Environment & Database Status -->
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-server me-2"></i>Environment & Connection Parameters
            </div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                        <tr>
                            <td><strong>Framework Core Version:</strong></td>
                            <td><code><?= get_framework_version() ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>PHP Engine Version:</strong></td>
                            <td><code><?= PHP_VERSION ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Operating System:</strong></td>
                            <td><code><?= PHP_OS_FAMILY . ' (' . PHP_OS . ')' ?></code></td>
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

    <!-- Active Tables Overview Card -->
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-database me-2 text-warning"></i>Database Architecture Overview
            </div>
            <div class="card-body">
                <h6 class="fw-bold mb-2">Total Tables Registered (<?= count($tables_found) ?>):</h6>
                <div style="max-height: 200px; overflow-y: auto;" class="border p-2 bg-light rounded">
                    <?php if (empty($tables_found)): ?>
                        <span class="text-muted small">No tables found in base_framework DB.</span>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 small font-monospace">
                            <?php foreach ($table_details as $t_info):
                                $badge = $t_info['is_plugin'] ? '<span class="badge bg-info text-dark fs-8">Plugin: ' . htmlspecialchars($t_info['plugin_slug'] ?? 'module') . '</span>' : '<span class="badge bg-secondary fs-8">Core</span>';
                            ?>
                                <li class="py-1 border-bottom d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fa-solid fa-table me-1 text-muted"></i>
                                        <strong><?= htmlspecialchars($t_info['name']) ?></strong>
                                        <small class="text-muted ms-2">(<?= $t_info['row_count'] ?> rows)</small>
                                    </span>
                                    <?= $badge ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="form-text mt-2 small text-muted">Core tables manage framework identity, RBAC, settings, and logs. Plugin tables are isolated via the <code>plug_{slug}_</code> prefix.</div>
            </div>
        </div>
    </div>
</div>

<!-- Comprehensive Database Tables Structure & Sample Records Inspector -->
<div class="card shadow-sm border-dark mb-4 text-start">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 fw-bold"><i class="fa-solid fa-sitemap me-2 text-warning"></i>Database Schema Structure & Example Records Inspector</h5>
        <span class="badge bg-warning text-dark fs-6"><?= count($tables_found) ?> Database Tables</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-4">Examine the exact column structure, data types, primary/foreign keys, and real sample records for both core framework tables and isolated plugin tables below.</p>

        <div class="accordion" id="tablesAccordion">
            <?php foreach ($table_details as $t_name => $td):
                $acc_id = "table_acc_" . md5($t_name);
                $is_plug = $td['is_plugin'];
            ?>
                <div class="accordion-item mb-2 border">
                    <h2 class="accordion-header" id="heading_<?= $acc_id ?>">
                        <button class="accordion-button collapsed py-2 px-3 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_<?= $acc_id ?>">
                            <div class="d-flex align-items-center w-100 justify-content-between pe-3">
                                <div>
                                    <i class="fa-solid fa-table text-primary me-2"></i>
                                    <span class="font-monospace text-dark"><?= htmlspecialchars($t_name) ?></span>
                                    <span class="badge bg-light text-dark border ms-2 font-monospace small"><?= count($td['columns']) ?> columns</span>
                                    <span class="badge bg-light text-secondary border ms-1 font-monospace small"><?= $td['row_count'] ?> records</span>
                                </div>
                                <div>
                                    <?php if ($is_plug): ?>
                                        <span class="badge bg-info text-dark">Plugin: <code><?= htmlspecialchars($td['plugin_slug'] ?? 'module') ?></code></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Core Framework</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="collapse_<?= $acc_id ?>" class="accordion-collapse collapse" data-bs-parent="#tablesAccordion">
                        <div class="accordion-body bg-light">
                            <!-- Section 1: Table Structure (Columns) -->
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-columns me-1 text-info"></i>Table Structure / Column Definitions:</h6>
                            <div class="table-responsive mb-4 bg-white border rounded">
                                <table class="table table-sm table-bordered align-middle mb-0 font-monospace small">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Field</th>
                                            <th>Type</th>
                                            <th>Null</th>
                                            <th>Key</th>
                                            <th>Default</th>
                                            <th>Extra</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($td['columns'] as $col): ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><?= htmlspecialchars($col['Field']) ?></td>
                                                <td><code><?= htmlspecialchars($col['Type']) ?></code></td>
                                                <td>
                                                    <?php if (strtoupper($col['Null']) === 'YES'): ?>
                                                        <span class="badge bg-light text-muted border">YES</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">NO</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($col['Key'] === 'PRI'): ?>
                                                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-key me-1"></i>PRI</span>
                                                    <?php elseif ($col['Key'] === 'UNI'): ?>
                                                        <span class="badge bg-info text-dark">UNI</span>
                                                    <?php elseif ($col['Key'] === 'MUL'): ?>
                                                        <span class="badge bg-light text-dark border">MUL</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?= $col['Default'] !== null ? htmlspecialchars($col['Default']) : 'NULL' ?></code></td>
                                                <td class="text-muted"><?= htmlspecialchars($col['Extra']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Section 2: Example / Sample DB Records -->
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-list-check me-1 text-success"></i>Example DB Records (Top <?= count($td['sample_rows']) ?> of <?= $td['row_count'] ?> rows):</h6>
                            <?php if (empty($td['sample_rows'])): ?>
                                <div class="alert alert-light border small text-muted mb-0">
                                    <i class="fa-solid fa-info-circle me-1 text-secondary"></i>No rows currently recorded in <code><?= htmlspecialchars($t_name) ?></code>.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive bg-white border rounded" style="max-height: 250px; overflow-y: auto;">
                                    <table class="table table-sm table-striped table-hover align-middle mb-0 font-monospace small">
                                        <thead class="table-secondary">
                                            <tr>
                                                <?php foreach (array_keys($td['sample_rows'][0]) as $col_name): ?>
                                                    <th><?= htmlspecialchars($col_name) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($td['sample_rows'] as $s_row): ?>
                                                <tr>
                                                    <?php foreach ($s_row as $val): ?>
                                                        <td>
                                                            <?php if ($val === null): ?>
                                                                <span class="text-muted fst-italic">NULL</span>
                                                            <?php else:
                                                                $val_str = (string)$val;
                                                                if (strlen($val_str) > 60) {
                                                                    $val_str = substr($val_str, 0, 57) . '...';
                                                                }
                                                                echo htmlspecialchars($val_str);
                                                            endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
