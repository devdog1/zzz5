<?php
// Scheduler.php - Robust, Sandboxed Task Scheduler with Dynamic Schedule Overrides, Concurrency Lock, and Execution Logs
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
        $scoped_key = $plugin_slug . '_' . $task_key;
        $this->tasks[$scoped_key] = [
            'key' => $task_key,
            'scoped_key' => $scoped_key,
            'callback' => $callback,
            'interval' => (int)$interval_seconds,
            'plugin' => $plugin_slug
        ];

        // Self-register/initialize task tracking row inside database upon loading
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("
                INSERT IGNORE INTO scheduled_tasks (task_key, plugin_slug, interval_seconds, is_enabled)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([$scoped_key, $plugin_slug, (int)$interval_seconds]);
        } catch (Exception $e) {
            // Silence if database is undergoing migrations
        }
    }

    /**
     * Run all pending tasks, wrapped in try-catch sandboxes.
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

            try {
                // Fetch scheduler state, overrides, and enable status from database
                $stmt = $db->prepare("SELECT * FROM scheduled_tasks WHERE task_key = ?");
                $stmt->execute([$scoped_key]);
                $state = $stmt->fetch();

                if (!$state) {
                    continue;
                }

                // If disabled by administrator, completely skip
                if (isset($state['is_enabled']) && (int)$state['is_enabled'] === 0) {
                    continue;
                }

                // CONCURRENCY LOCK: If task is already running, verify if it is a stale lock
                if (isset($state['status']) && $state['status'] === 'running') {
                    $last_update = strtotime($state['updated_at']);
                    $ten_minutes_ago = time() - 600;

                    if ($last_update > $ten_minutes_ago) {
                        // Skip: Task is currently executing in another process/concurrency lock active!
                        error_log("Task [{$scoped_key}] is currently running. Skipping duplicate parallel execution.");
                        continue;
                    } else {
                        // Recover stale lock: Task has been running for > 10 mins. Assume crashed/stale.
                        error_log("Stale lock detected for Task [{$scoped_key}]. Recovering task execution.");
                    }
                }

                // Parse custom overrides if defined
                $interval = !empty($state['custom_interval_seconds']) ? (int)$state['custom_interval_seconds'] : $task['interval'];
                $fixed_day = !empty($state['fixed_day_of_week']) ? (int)$state['fixed_day_of_week'] : null;
                $fixed_time = !empty($state['fixed_time_of_day']) ? $state['fixed_time_of_day'] : null;

                $now = time();
                $should_run = false;

                if ($fixed_day !== null || $fixed_time !== null) {
                    // Fixed Day/Time Overrides Logic
                    $current_day_of_week = (int)date('N'); // 1 (Mon) - 7 (Sun)
                    $current_time = date('H:i:00'); // Truncate seconds for perfect minute interval comparison

                    if ($fixed_day !== null && $fixed_time !== null) {
                        // Must match both day and time
                        $target_time = date('H:i:00', strtotime($fixed_time));
                        if ($current_day_of_week === $fixed_day && $current_time === $target_time) {
                            $should_run = true;
                        }
                    } elseif ($fixed_day !== null) {
                        // Match day of week. Run on first run check of that day
                        if ($current_day_of_week === $fixed_day) {
                            $last_run_day = $state['last_run'] ? date('Y-m-d', strtotime($state['last_run'])) : '';
                            if ($last_run_day !== date('Y-m-d')) {
                                $should_run = true;
                            }
                        }
                    } elseif ($fixed_time !== null) {
                        // Match time of day every day
                        $target_time = date('H:i:00', strtotime($fixed_time));
                        if ($current_time === $target_time) {
                            $should_run = true;
                        }
                    }
                } else {
                    // Interval-based scheduling
                    if ($state['next_run']) {
                        $next_run = strtotime($state['next_run']);
                        if ($now >= $next_run) {
                            $should_run = true;
                        }
                    } else {
                        // No last run tracked yet. Run immediately
                        $should_run = true;
                    }
                }

                if (!$should_run) {
                    continue;
                }

                // Update state to "Running"
                $next_run_timestamp = $now + $interval;

                // If using fixed scheduling, map next_run accordingly
                if ($fixed_day !== null || $fixed_time !== null) {
                    $next_run_timestamp = $now + 60; // Check again in next minute loops
                }

                $stmt = $db->prepare("
                    UPDATE scheduled_tasks
                    SET last_run = NOW(),
                        next_run = FROM_UNIXTIME(?),
                        status = 'running',
                        error_message = NULL
                    WHERE task_key = ?
                ");
                $stmt->execute([$next_run_timestamp, $scoped_key]);

                // Track microtime start
                $start_time = microtime(true);

                // Insert into historical logs with 'running' status
                $stmt_log = $db->prepare("
                    INSERT INTO scheduled_tasks_logs (task_key, run_started, status)
                    VALUES (?, NOW(), 'running')
                ");
                $stmt_log->execute([$scoped_key]);
                $log_id = $db->lastInsertId();

                // Sandbox execution of callback
                $success = true;
                $error_msg = null;

                try {
                    call_user_func($task['callback']);
                } catch (Throwable $t) {
                    $success = false;
                    $error_msg = $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                    error_log("Scheduler Error in task [{$scoped_key}]: " . $error_msg);

                    if (function_exists('log_action')) {
                        log_action('SCHEDULER_TASK_CRASH', [
                            'task_key' => $task['key'],
                            'plugin_slug' => $task['plugin'],
                            'error' => $error_msg
                        ]);
                    }
                }

                // Calculate duration seconds
                $duration = round(microtime(true) - $start_time, 4);

                // Update final state status
                $status = $success ? 'success' : 'failed';
                $stmt = $db->prepare("
                    UPDATE scheduled_tasks
                    SET status = ?, error_message = ?
                    WHERE task_key = ?
                ");
                $stmt->execute([$status, $error_msg, $scoped_key]);

                // Update historical logs row with final metrics
                $stmt_log_update = $db->prepare("
                    UPDATE scheduled_tasks_logs
                    SET run_ended = NOW(),
                        status = ?,
                        duration_seconds = ?,
                        error_message = ?
                    WHERE id = ?
                ");
                $stmt_log_update->execute([$status, $duration, $error_msg, $log_id]);

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
            return $db->query("SELECT * FROM scheduled_tasks ORDER BY task_key ASC")->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    public function getRecentExecutionLogs($limit = 30)
    {
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("
                SELECT * FROM scheduled_tasks_logs
                ORDER BY run_started DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}
