<?php
// admin-plugins.php - Dedicated Module Discovery, Enablement, and Cron Tasks Monitor Page
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';

// Enforce permission checks
if (!has_permission('manage_plugins')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to manage portal modules.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$scheduler = Scheduler::getInstance();
$msg = '';
$err = '';

// Handle Activation, Deactivation, and Manual Cron triggers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'activate') {
        $slug = $_POST['plugin_slug'] ?? '';
        if ($pluginManager->activatePlugin($slug)) {
            $msg = "Module <strong>" . htmlspecialchars($slug) . "</strong> has been successfully activated!";
            log_action('ACTIVATE_PLUGIN', ['slug' => $slug]);
        } else {
            // Retrieve global err if any
            global $err;
            $err = $err ?: "Failed to activate module <strong>" . htmlspecialchars($slug) . "</strong>.";
        }
    } elseif ($action === 'deactivate') {
        $slug = $_POST['plugin_slug'] ?? '';
        if ($pluginManager->deactivatePlugin($slug)) {
            $msg = "Module <strong>" . htmlspecialchars($slug) . "</strong> has been successfully deactivated!";
            log_action('DEACTIVATE_PLUGIN', ['slug' => $slug]);
        } else {
            $err = "Failed to deactivate module <strong>" . htmlspecialchars($slug) . "</strong>.";
        }
    } elseif ($action === 'trigger_cron') {
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

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-puzzle-piece text-info me-2"></i>Module & Extension Manager</h1>
        <p class="text-muted">Discover new pluggable features, toggle dynamic extensions, register custom roles, and monitor background crons.</p>
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

<div class="row">
    <!-- Plugins Table List -->
    <div class="col-lg-8">
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
                                            <p class="text-muted small mb-1"><?= htmlspecialchars($meta['description']) ?></p>
                                            <small class="text-muted">Version: <?= htmlspecialchars($meta['version']) ?> | Author: <?= htmlspecialchars($meta['author']) ?></small>
                                            <?php if (!empty($meta['permissions'])): ?>
                                                <div class="mt-1"><span class="small text-secondary"><strong>Permissions Provided:</strong> <code><?= htmlspecialchars($meta['permissions']) ?></code></span></div>
                                            <?php endif; ?>
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
                                            <form method="POST" class="m-0 d-inline-block">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="plugin_slug" value="<?= htmlspecialchars($slug) ?>">
                                                <?php if ($meta['active']): ?>
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="fa-solid fa-power-off me-1"></i>Deactivate
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        <i class="fa-solid fa-bolt me-1"></i>Activate
                                                    </button>
                                                <?php endif; ?>
                                            </form>
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

    <!-- Right Sidebar Quick Info -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-info mb-4">
            <div class="card-header bg-info text-dark">
                <i class="fa-solid fa-circle-info me-2"></i>Extension Info
            </div>
            <div class="card-body small">
                <h6>Dynamic RBAC Integration:</h6>
                <p class="text-muted mb-3">Activating a module automatically registers its declared custom Permissions and Roles in the centralized database directories, making them instantly visible inside the <strong>Users & RBAC</strong> panel.</p>
                <h6>Database Prefix Sandbox:</h6>
                <p class="text-muted mb-0">Plugins operate inside segregated database contexts using the prefix format <code>plug_{slug}_</code> to guarantee other modules remain uncompromised.</p>
            </div>
        </div>
    </div>
</div>

<!-- Task Scheduler Dashboard -->
<div class="row">
    <div class="col-lg-12">
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i>Background Task Scheduler (Sandboxed)</span>
                <form method="POST" class="m-0">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="trigger_cron">
                    <button type="submit" class="btn btn-xs btn-outline-warning text-white border-white btn-sm">
                        <i class="fa-solid fa-arrows-spin me-1"></i>Trigger Cron Check
                    </button>
                </form>
            </div>
            <div class="card-body p-0">
                <?php $states = $scheduler->getTaskExecutionStates(); ?>
                <?php if (empty($states)): ?>
                    <p class="text-muted p-4 mb-0"><i class="fa-solid fa-circle-info me-1 text-primary"></i>No background tasks have been logged by active plugins yet.</p>
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
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
