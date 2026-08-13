<?php
// Scheduler.php - Robust, Sandboxed Task Scheduler for Extensions
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/PluginManager.php';

class Scheduler
{
    private static $instance = null;
    private $tasks = [];

    private function __construct() {}

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a background task from a plugin safely.
     *
     * @param string $task_key Unique identifier for the task
     * @param callable $callback The code to execute
     * @param int $interval_seconds How frequently to run (e.g., 3600 for hourly)
     * @param string $plugin_slug Owner plugin to allow clean tracking
     */
    public function registerTask($task_key, $callback, $interval_seconds = 3600, $plugin_slug = 'core')
    {
        // Safe registration preventing duplicate keys across different plugins
        $scoped_key = $plugin_slug . '_' . $task_key;
        $this->tasks[$scoped_key] = [
            'key' => $task_key,
            'scoped_key' => $scoped_key,
            'callback' => $callback,
            'interval' => (int)$interval_seconds,
            'plugin' => $plugin_slug
        ];
    }

    /**
     * Run all pending tasks, wrapped in try-catch sandboxes so individual plugin failures
     * do not crash the core or disrupt other plugins.
     */
    public function runPendingTasks()
    {
        $db = get_db_connection();
        $pm = PluginManager::getInstance();

        foreach ($this->tasks as $scoped_key => $task) {
            // Check if plugin is active before executing tasks
            if ($task['plugin'] !== 'core' && !$pm->isPluginActive($task['plugin'])) {
                continue;
            }

            // Fetch last run state
            try {
                $stmt = $db->prepare("SELECT last_run, next_run FROM scheduled_tasks WHERE task_key = ?");
                $stmt->execute([$scoped_key]);
                $state = $stmt->fetch();

                $now = time();
                if ($state) {
                    $next_run = strtotime($state['next_run']);
                    if ($now < $next_run) {
                        // Not time to run yet
                        continue;
                    }
                }

                // Initialize or update tracking record to "Running" status
                $stmt = $db->prepare("
                    INSERT INTO scheduled_tasks (task_key, plugin_slug, interval_seconds, last_run, next_run, status, error_message)
                    VALUES (?, ?, ?, NOW(), FROM_UNIXTIME(?), 'running', NULL)
                    ON DUPLICATE KEY UPDATE
                        last_run = NOW(),
                        next_run = FROM_UNIXTIME(?),
                        status = 'running',
                        error_message = NULL
                ");
                $next_run_timestamp = $now + $task['interval'];
                $stmt->execute([$scoped_key, $task['plugin'], $task['interval'], $next_run_timestamp, $next_run_timestamp]);

                // Sandbox execution of plugin callback
                $success = true;
                $error_msg = null;

                try {
                    // Execute the callback safely
                    call_user_func($task['callback']);
                } catch (Throwable $t) {
                    $success = false;
                    $error_msg = $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();

                    // Log to standard error log
                    error_log("Scheduler Error in task [{$scoped_key}]: " . $error_msg);

                    // Log to global audit trail
                    log_action('SCHEDULER_TASK_CRASH', [
                        'task_key' => $task['key'],
                        'plugin_slug' => $task['plugin'],
                        'error' => $error_msg
                    ]);
                }

                // Update task state with final status
                $status = $success ? 'success' : 'failed';
                $stmt = $db->prepare("
                    UPDATE scheduled_tasks
                    SET status = ?, error_message = ?
                    WHERE task_key = ?
                ");
                $stmt->execute([$status, $error_msg, $scoped_key]);

            } catch (Exception $e) {
                error_log("Database tracking failure in scheduler loop: " . $e->getMessage());
            }
        }
    }

    public function getRegisteredTasks()
    {
        return $this->tasks;
    }

    public function getTaskExecutionStates()
    {
        try {
            $db = get_db_connection();
            return $db->query("SELECT * FROM scheduled_tasks ORDER BY last_run DESC")->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}
