<?php
// db.php - Database connection helpers

function get_db_connection() {
    $config = require __DIR__ . '/config.php';
    $host = $config['db']['local']['dbhost'] ?? '127.0.0.1';
    $dbname = $config['db']['local']['dbname'] ?? 'base_framework';
    $user = $config['db']['local']['dbuser'] ?? 'framework_user';
    $pass = $config['db']['local']['dbpass'] ?? 'framework_pass';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);

        // Auto-migration check: Ensure roles table contains is_active column
        static $roles_schema_checked = false;
        if (!$roles_schema_checked) {
            $roles_schema_checked = true;
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM roles LIKE 'is_active'")->fetchAll();
                if (empty($cols)) {
                    $pdo->exec("ALTER TABLE roles ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER description");
                }
            } catch (Exception $e) {
                // Ignore schema migration exceptions if table does not yet exist
            }
        }

        return $pdo;
    } catch (\PDOException $e) {
        // Fallback to local SQLite database if MySQL server is unreachable
        try {
            static $sqlite_pdo = null;
            if ($sqlite_pdo !== null) {
                return $sqlite_pdo;
            }
            $sqlite_file = sys_get_temp_dir() . '/base_framework_fallback.sqlite';
            $sqlite_pdo = new PDO("sqlite:" . $sqlite_file, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            init_sqlite_fallback_tables($sqlite_pdo);
            return $sqlite_pdo;
        } catch (\Throwable $sqe) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }
}

function init_sqlite_fallback_tables($pdo) {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $queries = [
        "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT, display_name TEXT, is_active INTEGER DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT UNIQUE, description TEXT, is_active INTEGER DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, permission_name TEXT UNIQUE, description TEXT)",
        "CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER, role_id INTEGER, PRIMARY KEY (user_id, role_id))",
        "CREATE TABLE IF NOT EXISTS role_permissions (role_id INTEGER, permission_id INTEGER, PRIMARY KEY (role_id, permission_id))",
        "CREATE TABLE IF NOT EXISTS user_permissions (user_id INTEGER, permission_id INTEGER, PRIMARY KEY (user_id, permission_id))",
        "CREATE TABLE IF NOT EXISTS denied_permissions (user_id INTEGER, permission_id INTEGER, PRIMARY KEY (user_id, permission_id))",
        "CREATE TABLE IF NOT EXISTS settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, created_at TEXT, updated_at TEXT)",
        "CREATE TABLE IF NOT EXISTS audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, timestamp TEXT, action TEXT, details TEXT, ip_address TEXT)",
        "CREATE TABLE IF NOT EXISTS active_plugins (id INTEGER PRIMARY KEY AUTOINCREMENT, plugin_slug TEXT UNIQUE, installed_version TEXT DEFAULT '1.0.0')",
        "CREATE TABLE IF NOT EXISTS scheduled_tasks (task_key TEXT PRIMARY KEY, plugin_slug TEXT, interval_seconds INTEGER, is_enabled INTEGER DEFAULT 1, status TEXT, last_run TEXT, next_run TEXT, error_message TEXT)",
        "CREATE TABLE IF NOT EXISTS scheduled_tasks_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, task_key TEXT, run_started TEXT, run_ended TEXT, status TEXT, duration_seconds REAL, error_message TEXT)",
        "CREATE TABLE IF NOT EXISTS event_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT, payload TEXT, status TEXT DEFAULT 'pending', error_message TEXT, created_at TEXT, processed_at TEXT)",
        "INSERT OR IGNORE INTO roles (id, role_name, description) VALUES (1, 'admin', 'Global Administrator'), (2, 'manager', 'Manager'), (3, 'user', 'Standard user')",
        "INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES ('site_name', 'Framework Portal')",
        "INSERT OR IGNORE INTO active_plugins (plugin_slug, installed_version) VALUES ('sample-manager', '1.6.0')"
    ];

    foreach ($queries as $q) {
        try {
            $pdo->exec($q);
        } catch (\Throwable $ex) {
            // ignore
        }
    }
}
