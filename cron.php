<?php
// cron.php - Command-line (CLI) and Web-safe entry point to execute scheduled tasks and manage plugins
// Usage in crontab: * * * * * php /path/to/cron.php >/dev/null 2>&1

// Ensure database connections and active plugins boot cleanly
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';
require_once __DIR__ . '/PluginManager.php';

// Check if running from CLI or authorized request
$is_cli = (php_sapi_name() === 'cli');
$is_authorized_web = isset($_GET['key']) && $_GET['key'] === get_csrf_token();

if (!$is_cli && !$is_authorized_web) {
    http_response_code(403);
    die("Access Denied: Scheduled tasks cron can only be triggered via server CLI or authorized web hooks.");
}

$pm = PluginManager::getInstance();

// Parse CLI CLI arguments
if ($is_cli) {
    foreach ($argv as $arg) {
        if ($arg === '--version') {
            $ver = get_setting('framework_version', '1.1.0');
            echo "Portal Framework Version: {$ver} (PHP " . PHP_VERSION . ")\n";
            exit(0);
        }

        if ($arg === '--plugin-list') {
            echo "Discovered Plugins:\n";
            $plugins = $pm->discoverPlugins();
            foreach ($plugins as $slug => $meta) {
                $status = $meta['active'] ? 'ACTIVE' : 'INACTIVE';
                echo "  - [{$status}] {$slug} (v{$meta['version']}): {$meta['name']} - {$meta['description']}\n";
            }
            exit(0);
        }

        if (strpos($arg, '--plugin-activate=') === 0) {
            $slug = trim(substr($arg, 18));
            echo "Activating plugin [{$slug}]...\n";
            $res = $pm->activatePlugin($slug);
            if ($res) {
                echo "Success: Plugin [{$slug}] activated successfully.\n";
                exit(0);
            } else {
                global $err;
                echo "Error: Failed to activate plugin [{$slug}]. " . ($err ?? '') . "\n";
                exit(1);
            }
        }

        if (strpos($arg, '--plugin-deactivate=') === 0) {
            $slug = trim(substr($arg, 20));
            echo "Deactivating plugin [{$slug}]...\n";
            $res = $pm->deactivatePlugin($slug, false);
            if ($res) {
                echo "Success: Plugin [{$slug}] deactivated successfully.\n";
                exit(0);
            } else {
                echo "Error: Failed to deactivate plugin [{$slug}].\n";
                exit(1);
            }
        }

        if (strpos($arg, '--plugin-inspect=') === 0) {
            $slug = trim(substr($arg, 17));
            echo "Inspecting plugin [{$slug}]...\n";
            $report = $pm->inspectPlugin($slug);
            echo "Compatibility Status: " . ($report['compatible'] ? 'COMPATIBLE' : 'INCOMPATIBLE') . "\n";
            echo "Checks Summary:\n";
            foreach ($report['checks'] as $c) {
                $statusTag = strtoupper($c['status']);
                echo "  [{$statusTag}] {$c['title']}: {$c['message']}\n";
            }
            exit(0);
        }
    }
}

// Parse single task execution argument
$single_task_key = null;
if ($is_cli) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--task=') === 0) {
            $single_task_key = substr($arg, 7);
        }
    }
} else {
    $single_task_key = $_GET['task'] ?? null;
}

try {
    $scheduler = Scheduler::getInstance();

    if (!empty($single_task_key)) {
        // Execute ONLY this specific task directly in this process
        $scheduler->executeSingleTask($single_task_key);
        if (!$is_cli) {
            echo "<h1>Execution of task [" . htmlspecialchars($single_task_key) . "] complete.</h1>";
        }
    } else {
        // Evaluate all due tasks and spawn them asynchronously in parallel background processes
        $scheduler->runPendingTasks();
        if (!$is_cli) {
            echo "<h1>Task Scheduler parallel triggers initialized.</h1>";
        }
    }
} catch (Throwable $t) {
    error_log("Task Scheduler Cron Failure: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
    if (!$is_cli) {
        echo "<h1>Scheduler Error Occurred</h1><p>" . htmlspecialchars($t->getMessage()) . "</p>";
    }
}
