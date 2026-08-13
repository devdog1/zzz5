<?php
/*
Plugin Name: Sample Manager
Description: A sample plugin showing how to register custom settings, custom route, navigation tab, background tasks, widgets, dynamic table creation, and shared inter-plugin services inside the Base Framework.
Version: 1.4.0
Author: Framework Developers
*/

// Prevent direct access
if (!class_exists('PluginManager')) {
    exit;
}

// 1. Hook to register custom navigation tab
PluginManager::getInstance()->addFilter('theme_nav_links', function ($links) {
    $links[] = [
        'route' => 'sample_manager_dashboard',
        'label' => 'Sample Manager',
        'icon'  => 'fa-solid fa-wand-magic-sparkles',
        'permission' => 'view_dashboard' // Core generic permission
    ];
    return $links;
});

// 2. Hook to register route callback
PluginManager::getInstance()->registerRoute('sample_manager_dashboard', function () {
    // Check custom permissions if needed, or core permissions
    if (!has_permission('view_dashboard')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    // Handle settings submission inside module with secure CSRF verification
    $msg = '';
    if (isset($_POST['save_sample_settings'])) {
        // CSRF verification check!
        validate_csrf();

        $sample_token = trim($_POST['sample_api_token'] ?? '');
        set_setting('sample_manager_api_token', $sample_token);
        log_action('SAMPLE_MANAGER_SETTINGS_SAVE', ['sample_api_token' => '***']);
        $msg = "Sample Settings saved successfully (CSRF Verified)!";
    }

    $currentToken = get_setting('sample_manager_api_token', 'default_mock_api_token');
    ?>
    <div class="row">
        <div class="col-md-12">
            <h2 class="mb-4"><i class="fa-solid fa-wand-magic-sparkles text-info me-2"></i>Sample Manager Module</h2>
            <p class="text-muted">This module demonstrates custom route execution, database integration using <code>get_setting</code>, <code>set_setting</code>, and custom styling injection.</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row text-start">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="fa-solid fa-gear me-1"></i>Module Configuration & Settings
                </div>
                <div class="card-body">
                    <form method="POST">
                        <!-- Core security anti-forgery field token -->
                        <?php csrf_field(); ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Sample API Secret Token</label>
                            <input type="text" name="sample_api_token" class="form-control" value="<?= htmlspecialchars($currentToken) ?>" required>
                            <div class="form-text">Configure arbitrary setting options stored securely in the core <code>settings</code> table.</div>
                        </div>
                        <button type="submit" name="save_sample_settings" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Configuration (CSRF Protected)
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm border-info">
                <div class="card-header bg-info text-dark">
                    <i class="fa-solid fa-circle-info me-1"></i>Developer Instructions
                </div>
                <div class="card-body">
                    <h6>How to create a new module:</h6>
                    <ol class="small text-muted mb-0">
                        <li>Create a sub-directory in <code>plugins/</code> (e.g. <code>plugins/my-plugin/</code>).</li>
                        <li>Create a <code>plugin.php</code> file inside.</li>
                        <li>Add comment header blocks at the top (Plugin Name, Description, Version, Author).</li>
                        <li>Use `PluginManager::getInstance()->addFilter('theme_nav_links', ...)` to display the link in navigation headers.</li>
                        <li>Use `PluginManager::getInstance()->registerRoute('route_name', ...)` to bind page callbacks.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <?php
});

// 3. Injecting a simple welcome script into footer
PluginManager::getInstance()->addAction('theme_footer', function() {
    echo "<!-- Sample Manager Plugin Loaded Successfully -->";
});

// 4. Register a background task with Scheduler API
require_once __DIR__ . '/../../Scheduler.php';
Scheduler::getInstance()->registerTask(
    'sample_cleanup_task',
    function() {
        // Run clean-up side-effects securely
        log_action('SAMPLE_CLEANUP_CRON', ['status' => 'executed_cleanly']);
    },
    300, // run once every 5 minutes
    'sample-manager'
);

// 5. Expose inter-plugin capabilities / service API
PluginManager::getInstance()->registerService(
    'sample_fetch_system_status',
    function($arg1) {
        // Keeps execution context under active session scope. Returns custom array payload
        return [
            'status' => 'online',
            'token_configured' => get_setting('sample_manager_api_token') ? true : false,
            'argument_passed' => $arg1,
            'queried_at' => date('Y-m-d H:i:s')
        ];
    },
    'sample-manager'
);

// 6. Hook to register a dynamic, user-contextual index dashboard widget card
PluginManager::getInstance()->addAction('index_dashboard_widgets', function($userContext) {
    // Custom user contextual details displayed on Home screen dynamically
    $roles_str = implode(', ', array_map('ucfirst', $userContext['roles'] ?? []));
    ?>
    <div class="col-md-6 col-lg-4">
        <div class="card bg-gradient shadow-sm border-start border-5 border-info text-start">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-light rounded-circle p-2 text-center me-3" style="width: 45px; height: 45px;">
                        <i class="fa-solid fa-id-card-clip text-info fs-4"></i>
                    </div>
                    <div>
                        <h6 class="card-title fw-bold mb-0 text-dark">User Context Widget</h6>
                        <small class="text-muted">Registered by Sample Plugin</small>
                    </div>
                </div>
                <hr class="my-2">
                <p class="card-text small mb-1"><strong>Hello,</strong> <?= htmlspecialchars($userContext['display_name']) ?>!</p>
                <p class="card-text small mb-1"><strong>Active Privilege Roles:</strong> <code class="text-secondary"><?= htmlspecialchars($roles_str) ?></code></p>
                <p class="card-text small mb-0"><strong>Your Login ID:</strong> <code><?= htmlspecialchars($userContext['username']) ?></code></p>
            </div>
        </div>
    </div>
    <?php
});

// 7. Safe Plugin Database Isolation Demonstration
require_once __DIR__ . '/../../PluginDatabase.php';

// Safe Plugin activation event hook - creates dynamic plugin-prefixed tables
PluginManager::getInstance()->addAction('activate_plugin_sample-manager', function() {
    $pdb = new PluginDatabase('sample-manager');
    $pdb->createTable('logs', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_message VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ");
    log_action('SAMPLE_PLUGIN_ACTIVATE_DB_SUCCESS', []);
});

// Safe Plugin deactivation event hook - drops plugin-prefixed tables clean
PluginManager::getInstance()->addAction('deactivate_plugin_sample-manager', function() {
    $pdb = new PluginDatabase('sample-manager');
    $pdb->dropTable('logs');
    log_action('SAMPLE_PLUGIN_DEACTIVATE_DB_SUCCESS', []);
});
