<?php
// cron.php - Command-line (CLI) and Web-safe entry point to execute scheduled tasks every minute via crontab
// Usage in crontab: * * * * * php /path/to/cron.php >/dev/null 2>&1

// Ensure database connections and active plugins boot cleanly
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Scheduler.php';

// Check if running from CLI or authorized request
$is_cli = (php_sapi_name() === 'cli');
$is_authorized_web = isset($_GET['key']) && $_GET['key'] === get_csrf_token(); // Support secure web triggers as fallbacks

if (!$is_cli && !$is_authorized_web) {
    http_response_code(403);
    die("Access Denied: Scheduled tasks cron can only be triggered via server CLI or authorized web hooks.");
}

// Log execution trigger
error_log("Task Scheduler cron execution initiated at " . date('Y-m-d H:i:s'));

try {
    $scheduler = Scheduler::getInstance();
    $scheduler->runPendingTasks();

    if (!$is_cli) {
        echo "<h1>Task Scheduler Execution Complete.</h1>";
    }
} catch (Throwable $t) {
    error_log("Task Scheduler Cron Failure: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
    if (!$is_cli) {
        echo "<h1>Scheduler Error Occurred</h1><p>" . htmlspecialchars($t->getMessage()) . "</p>";
    }
}
