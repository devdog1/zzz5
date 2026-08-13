<?php
// index.php - Pure Portal Dashboard Home Page
require_once __DIR__ . '/functions.php';

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

// Otherwise, render Core Dashboard Portal Screen
require_once __DIR__ . '/header.php';

$activePluginsList = $pluginManager->getActivePlugins();
$activeCount = count($activePluginsList);

// Fetch current user details as dynamic widget context
$currentUserContext = [
    'id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['user']['email'] ?? '',
    'display_name' => $_SESSION['user']['name'] ?? 'User',
    'roles' => isset($_SESSION['roles']) ? array_keys($_SESSION['roles']) : [],
    'permissions' => isset($_SESSION['permissions']) ? array_keys($_SESSION['permissions']) : []
];
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Core Dashboard</h1>
        <p class="text-muted">Welcome to your Portal Homepage. Below are active dynamic widgets, diagnostic indicators, and audits.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <span class="badge bg-secondary p-2"><i class="fa-solid fa-clock me-1"></i> <?= date('Y-m-d H:i:s') ?></span>
    </div>
</div>

<!-- Extensible Dashboard Widget Hook (Per-User Contextual Widgets) -->
<div class="row mb-4">
    <?php
    // Passes the contextual user session object so widgets draw customized, user-specific data
    $pluginManager->doAction('index_dashboard_widgets', $currentUserContext);
    ?>
</div>

<div class="row">
    <!-- Platform Quick Overview -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-start border-5 border-primary mb-4 p-4 text-start">
            <h4 class="fw-bold text-dark"><i class="fa-solid fa-cubes text-info me-2"></i>Enterprise Pluggable Portal</h4>
            <p class="text-muted">This portal features a WordPress-inspired event broker engine, ensuring core platforms are detached from feature modules. You can extend, replace, or customize routes, themes, and schedulers seamlessly.</p>
            <div class="mt-2">
                <?php if (has_permission('manage_plugins')): ?>
                    <a href="admin-plugins.php" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-puzzle-piece me-1"></i>Configure Active Modules
                    </a>
                <?php endif; ?>
                <?php if (has_permission('manage_settings')): ?>
                    <a href="admin-users.php" class="btn btn-sm btn-outline-secondary ms-1">
                        <i class="fa-solid fa-users me-1"></i>Manage RBAC Mappings
                    </a>
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
