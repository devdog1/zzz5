<?php
/*
Plugin Name: Sample Manager
Description: A sample plugin showing how to register custom settings, custom route, navigation tab, background tasks, and hooks inside the Base Framework.
Version: 1.2.0
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

    // Handle settings submission inside module
    $msg = '';
    if (isset($_POST['save_sample_settings'])) {
        $sample_token = trim($_POST['sample_api_token'] ?? '');
        set_setting('sample_manager_api_token', $sample_token);
        log_action('SAMPLE_MANAGER_SETTINGS_SAVE', ['sample_api_token' => '***']);
        $msg = "Sample Settings saved successfully!";
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

    <div class="row">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="fa-solid fa-gear me-1"></i>Module Configuration & Settings
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Sample API Secret Token</label>
                            <input type="text" name="sample_api_token" class="form-control" value="<?= htmlspecialchars($currentToken) ?>" required>
                            <div class="form-text">Configure arbitrary setting options stored securely in the core <code>settings</code> table.</div>
                        </div>
                        <button type="submit" name="save_sample_settings" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Configuration
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
