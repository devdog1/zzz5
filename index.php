<?php
// index.php - Streamlined Portal Dashboard Home Page
require_once __DIR__ . '/functions.php';

// Redirect to login if user is not logged in
require_login();

// Check if we are executing a custom plugin route
$route = $_GET['route'] ?? null;
if ($route) {
    // Buffer output so JSON/AJAX routes that call exit() can return raw JSON without HTML headers
    ob_start();
    $handled = $pluginManager->handleRoute($route);
    $route_output = ob_get_clean();

    if ($handled) {
        // If route completed normally (did not call exit for raw JSON), wrap in theme templates
        require_once __DIR__ . '/header.php';
        echo $route_output;
        require_once __DIR__ . '/footer.php';
        exit;
    } else {
        require_once __DIR__ . '/header.php';
        echo '<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> No plugin found matching route: ' . htmlspecialchars($route) . '</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
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
    <div class="col-md-8 text-start">
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

<?php if (has_permission('manage_plugins')): ?>
    <div class="row text-start">
        <!-- Active Plugins list panel -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-puzzle-piece me-2"></i>Active Feature Modules</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">All portal features are dynamically served by independent feature packages. Active modules are monitored below:</p>
                    <?php if (empty($activePluginsList)): ?>
                        <div class="alert alert-light border small text-center mb-0">No dynamic feature modules are currently enabled on your portal.</div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($activePluginsList as $active_slug): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded border border-start border-3 border-success d-flex align-items-center justify-content-between">
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars(ucwords(str_replace('-', ' ', $active_slug))) ?></h6>
                                            <small class="text-muted font-monospace">slug: <?= htmlspecialchars($active_slug) ?></small>
                                        </div>
                                        <span class="badge bg-success small"><i class="fa-solid fa-circle-check me-1"></i>Running</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Quick Stats -->
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
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
