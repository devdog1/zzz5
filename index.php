<?php
// index.php - Main Portal Router and Core Administrative Panel
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';

// Check if we are executing a custom plugin route
$route = $_GET['route'] ?? null;
if ($route) {
    // Start page buffer and include header
    require_once __DIR__ . '/header.php';

    // Attempt to handle custom route
    $handled = $pluginManager->handleRoute($route);
    if (!$handled) {
        echo '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> No plugin found matching route: ' . htmlspecialchars($route) . '</div>';
    }

    require_once __DIR__ . '/footer.php';
    exit;
}

// Otherwise, render Core Dashboard & Plugin Management Panel
require_once __DIR__ . '/header.php';

$scheduler = Scheduler::getInstance();

// Fetch current user details as dynamic widget context
$currentUserContext = [
    'id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['user']['email'] ?? '',
    'display_name' => $_SESSION['user']['name'] ?? 'User',
    'roles' => isset($_SESSION['roles']) ? array_keys($_SESSION['roles']) : [],
    'permissions' => isset($_SESSION['permissions']) ? array_keys($_SESSION['permissions']) : []
];

// Handle Action activation/deactivations or Manual Cron Trigger
$msg = '';
$err = '';
if (has_permission('manage_plugins')) {
    if (isset($_GET['activate'])) {
        $slug = $_GET['activate'];
        if ($pluginManager->activatePlugin($slug)) {
            $msg = "Module <strong>" . htmlspecialchars($slug) . "</strong> has been successfully activated!";
            log_action('ACTIVATE_PLUGIN', ['slug' => $slug]);
        } else {
            $err = "Failed to activate module <strong>" . htmlspecialchars($slug) . "</strong>.";
        }
    } elseif (isset($_GET['deactivate'])) {
        $slug = $_GET['deactivate'];
        if ($pluginManager->deactivatePlugin($slug)) {
            $msg = "Module <strong>" . htmlspecialchars($slug) . "</strong> has been successfully deactivated!";
            log_action('DEACTIVATE_PLUGIN', ['slug' => $slug]);
        } else {
            $err = "Failed to deactivate module <strong>" . htmlspecialchars($slug) . "</strong>.";
        }
    } elseif (isset($_POST['trigger_cron'])) {
        // Force manual background tasks check/run for active plugins
        try {
            $scheduler->runPendingTasks();
            $msg = "Task Scheduler triggered successfully! Pending tasks processed safely.";
            log_action('SCHEDULER_MANUAL_TRIGGER', []);
        } catch (Exception $e) {
            $err = "Scheduler Trigger Error: " . $e->getMessage();
        }
    }
}

// Fetch discovered plugins
$plugins = $pluginManager->discoverPlugins();
$activeCount = count($pluginManager->getActivePlugins());
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Core Dashboard</h1>
        <p class="text-muted">Manage active modules, sandboxed background tasks, system permissions, and configuration options.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <span class="badge bg-secondary p-2"><i class="fa-solid fa-clock me-1"></i> <?= date('Y-m-d H:i:s') ?></span>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $err ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- 1. Extensible Dashboard Widget Hook (Per-User Contextual Widgets) -->
<div class="row mb-4">
    <?php
    // Passes the contextual user session object so widgets draw customized, user-specific data
    $pluginManager->doAction('index_dashboard_widgets', $currentUserContext);
    ?>
</div>

<div class="row">
    <!-- Left Column: Plugin Manager & Background Scheduler -->
    <div class="col-lg-8">

        <!-- Available Extension Plugins -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-puzzle-piece me-2"></i>Available Extension Modules / Plugins</span>
                <span class="badge bg-info"><?= $activeCount ?> Active / <?= count($plugins) ?> Discovered</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($plugins)): ?>
                    <p class="text-muted p-4 mb-0">No plugin modules discovered in the <code>plugins/</code> directory yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Module Info</th>
                                    <th>Slug</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugins as $slug => $meta): ?>
                                    <tr>
                                        <td>
                                            <h5 class="h6 mb-0 fw-bold text-primary"><?= htmlspecialchars($meta['name']) ?></h5>
                                            <p class="text-muted small mb-0"><?= htmlspecialchars($meta['description']) ?></p>
                                            <small class="text-muted">Version: <?= htmlspecialchars($meta['version']) ?> | Author: <?= htmlspecialchars($meta['author']) ?></small>
                                        </td>
                                        <td><code><?= htmlspecialchars($slug) ?></code></td>
                                        <td>
                                            <?php if ($meta['active']): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (has_permission('manage_plugins')): ?>
                                                <?php if ($meta['active']): ?>
                                                    <a href="index.php?deactivate=<?= urlencode($slug) ?>" class="btn btn-sm btn-outline-danger">
                                                        <i class="fa-solid fa-power-off me-1"></i>Deactivate
                                                    </a>
                                                <?php else: ?>
                                                    <a href="index.php?activate=<?= urlencode($slug) ?>" class="btn btn-sm btn-outline-success">
                                                        <i class="fa-solid fa-bolt me-1"></i>Activate
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">No Permission</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Task Scheduler Dashboard -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i>Background Task Scheduler (Sandboxed)</span>
                <?php if (has_permission('manage_plugins')): ?>
                    <form method="POST" class="m-0">
                        <button type="submit" name="trigger_cron" class="btn btn-xs btn-outline-warning text-white border-white btn-sm">
                            <i class="fa-solid fa-arrows-spin me-1"></i>Trigger Cron Check
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php $states = $scheduler->getTaskExecutionStates(); ?>
                <?php if (empty($states)): ?>
                    <p class="text-muted p-4 mb-0"><i class="fa-solid fa-circle-info me-1 text-primary"></i>No background tasks have been logged by active plugins yet. Activate the <strong>Sample Manager</strong> plugin to schedule dynamic tasks.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Task Identifier</th>
                                    <th>Plugin Source</th>
                                    <th>Frequency</th>
                                    <th>Last Execution</th>
                                    <th>Next Run</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($states as $task): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($task['task_key']) ?></code></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($task['plugin_slug']) ?></span></td>
                                        <td><?= htmlspecialchars($task['interval_seconds']) ?>s</td>
                                        <td><?= htmlspecialchars($task['last_run']) ?></td>
                                        <td><?= htmlspecialchars($task['next_run']) ?></td>
                                        <td>
                                            <?php if ($task['status'] === 'success'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Success</span>
                                            <?php elseif ($task['status'] === 'failed'): ?>
                                                <span class="badge bg-danger" title="<?= htmlspecialchars($task['error_message']) ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-info"><?= htmlspecialchars($task['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: System Audits & Config -->
    <div class="col-lg-4">
        <!-- Quick Stats -->
        <div class="card mb-4 shadow-sm border-info">
            <div class="card-header bg-info text-dark">
                <i class="fa-solid fa-chart-pie me-2"></i>System Quick Stats
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Registered Users</span>
                    <span class="badge bg-secondary"><?= count(get_all_users()) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Active Modules</span>
                    <span class="badge bg-success"><?= $activeCount ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Sandboxed Tasks</span>
                    <span class="badge bg-warning text-dark"><?= count($scheduler->getRegisteredTasks()) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span>Database Engine</span>
                    <span class="badge bg-dark">MySQL/PDO</span>
                </div>
            </div>
        </div>

        <!-- Recent Audit Logs -->
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <i class="fa-solid fa-receipt me-2 text-primary"></i>Recent Action Audit Trail
            </div>
            <div class="card-body p-0">
                <?php $logs = get_audit_logs(10); ?>
                <?php if (empty($logs)): ?>
                    <p class="text-muted p-3 mb-0">No actions recorded in audit trail yet.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush small" style="max-height: 350px; overflow-y: auto;">
                        <?php foreach ($logs as $log): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1 fw-bold"><?= htmlspecialchars($log['action']) ?></h6>
                                    <small class="text-muted"><?= date('H:i:s', strtotime($log['timestamp'])) ?></small>
                                </div>
                                <p class="mb-1 text-muted fs-7"><?= htmlspecialchars(substr($log['details'], 0, 80)) ?>...</p>
                                <small class="text-muted">By: <?= htmlspecialchars($log['display_name'] ?? $log['username'] ?? 'System') ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
