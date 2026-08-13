<?php
// PluginManager.php - Robust WordPress-inspired Hooks, Filters, Routing & Plugin Management Engine
require_once __DIR__ . '/db.php';

class PluginManager
{
    private static $instance = null;
    private $actions = [];
    private $filters = [];
    private $routes = [];
    private $services = []; // Inter-Plugin Shared Service Registry
    private $activePlugins = [];
    private $pluginMeta = [];

    private function __construct()
    {
        $this->loadActivePlugins();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* =========================================================
     * HOOKS: ACTIONS (Side-effects, outputting HTML, events)
     * ========================================================= */
    public function addAction($tag, $callback, $priority = 10)
    {
        if (!isset($this->actions[$tag])) {
            $this->actions[$tag] = [];
        }
        $this->actions[$tag][$priority][] = $callback;
    }

    public function doAction($tag, ...$arg)
    {
        if (!isset($this->actions[$tag])) {
            return;
        }

        $priorities = $this->actions[$tag];
        ksort($priorities);

        foreach ($priorities as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                // SANDBOX SHIELD: Wrap each plugin hook execution in try/catch
                try {
                    call_user_func_array($callback, $arg);
                } catch (Throwable $t) {
                    $error_msg = "Error executing action hook [{$tag}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                    error_log($error_msg);
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_HOOK_CRASH', ['tag' => $tag, 'error' => $error_msg]);
                    }
                }
            }
        }
    }

    /* =========================================================
     * HOOKS: FILTERS (Modifying variables or HTML structures)
     * ========================================================= */
    public function addFilter($tag, $callback, $priority = 10)
    {
        if (!isset($this->filters[$tag])) {
            $this->filters[$tag] = [];
        }
        $this->filters[$tag][$priority][] = $callback;
    }

    public function applyFilters($tag, $value, ...$arg)
    {
        if (!isset($this->filters[$tag])) {
            return $value;
        }

        $priorities = $this->filters[$tag];
        ksort($priorities);

        foreach ($priorities as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                // SANDBOX SHIELD: Wrap each filter hook execution in try/catch
                try {
                    $value = call_user_func_array($callback, array_merge([$value], $arg));
                } catch (Throwable $t) {
                    $error_msg = "Error executing filter hook [{$tag}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                    error_log($error_msg);
                    if (function_exists('log_action')) {
                        log_action('PLUGIN_FILTER_CRASH', ['tag' => $tag, 'error' => $error_msg]);
                    }
                }
            }
        }

        return $value;
    }

    /* =========================================================
     * INTER-PLUGIN EXPOSED SERVICES & FUNCTIONS
     * ========================================================= */

    /**
     * Expose a function or complete service capability to other plugins securely,
     * maintaining active user session/context verification.
     */
    public function registerService($service_name, $callback, $plugin_slug)
    {
        $this->services[$service_name] = [
            'callback' => $callback,
            'plugin_slug' => $plugin_slug
        ];
    }

    /**
     * Call an exposed function or service registered by another plugin.
     * Guarantees active user authentication context during cross-plugin communication.
     */
    public function callService($service_name, ...$args)
    {
        if (!isset($this->services[$service_name])) {
            throw new Exception("Service/Function '{$service_name}' is not registered or available.");
        }

        $service = $this->services[$service_name];

        // Ensure the owner plugin is active before running
        if (!$this->isPluginActive($service['plugin_slug'])) {
            throw new Exception("The module '{$service['plugin_slug']}' registering service '{$service_name}' is currently deactivated.");
        }

        // Context check: Enforce that user session context is active during execution
        if (session_status() === PHP_SESSION_NONE || !isset($_SESSION['user_id'])) {
            throw new Exception("Security context violation: An active user session is required to invoke cross-plugin services.");
        }

        // Execute inside try/catch sandbox
        try {
            return call_user_func_array($service['callback'], $args);
        } catch (Throwable $t) {
            $error_msg = "Error during cross-plugin service execution [{$service_name}]: " . $t->getMessage();
            error_log($error_msg);
            throw new Exception($error_msg);
        }
    }

    /* =========================================================
     * EXTENSIBLE ROUTING
     * ========================================================= */
    public function registerRoute($route, $callback)
    {
        $this->routes[$route] = $callback;
    }

    public function handleRoute($route)
    {
        if (isset($this->routes[$route])) {
            // SANDBOX SHIELD: Wrap plugin route handlers in try/catch block
            try {
                call_user_func($this->routes[$route]);
            } catch (Throwable $t) {
                echo '<div class="alert alert-danger"><i class="fa-solid fa-bug me-1"></i> <strong>Critical Plugin Error:</strong> An uncaught exception occurred in this module view. Please check system audit trail.</div>';
                $error_msg = "Uncaught route exception in [{$route}]: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine();
                error_log($error_msg);
                if (function_exists('log_action')) {
                    log_action('PLUGIN_ROUTE_CRASH', ['route' => $route, 'error' => $error_msg]);
                }
            }
            return true;
        }
        return false;
    }

    public function getRoutes()
    {
        return $this->routes;
    }

    /* =========================================================
     * PLUGIN DISCOVERY AND LIFE-CYCLE
     * ========================================================= */
    private function loadActivePlugins()
    {
        try {
            $db = get_db_connection();
            $stmt = $db->query("SELECT plugin_slug FROM active_plugins");
            $this->activePlugins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $this->activePlugins = [];
        }
    }

    public function getActivePlugins()
    {
        return $this->activePlugins;
    }

    public function discoverPlugins()
    {
        $pluginsDir = __DIR__ . '/plugins';
        if (!is_dir($pluginsDir)) {
            mkdir($pluginsDir, 0755, true);
        }

        $discovered = [];
        $items = scandir($pluginsDir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $pluginFile = $pluginsDir . '/' . $item . '/plugin.php';
            if (file_exists($pluginFile)) {
                $meta = $this->parsePluginHeader($pluginFile);
                $meta['slug'] = $item;
                $meta['active'] = in_array($item, $this->activePlugins);
                $discovered[$item] = $meta;
            }
        }

        $this->pluginMeta = $discovered;
        return $discovered;
    }

    private function parsePluginHeader($file)
    {
        $content = file_get_contents($file);
        $meta = [
            'name' => basename(dirname($file)),
            'description' => 'No description provided.',
            'version' => '1.0.0',
            'author' => 'Anonymous',
            'permissions' => ''
        ];

        if (preg_match('/Plugin Name:\s*(.*)$/mi', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        }
        if (preg_match('/Description:\s*(.*)$/mi', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        if (preg_match('/Version:\s*(.*)$/mi', $content, $matches)) {
            $meta['version'] = trim($matches[1]);
        }
        if (preg_match('/Author:\s*(.*)$/mi', $content, $matches)) {
            $meta['author'] = trim($matches[1]);
        }
        // Extract optional comma-separated list of custom permissions from headers
        if (preg_match('/Permissions:\s*(.*)$/mi', $content, $matches)) {
            $meta['permissions'] = trim($matches[1]);
        }

        return $meta;
    }

    public function isPluginActive($slug)
    {
        return in_array($slug, $this->activePlugins);
    }

    public function activatePlugin($slug)
    {
        if ($this->isPluginActive($slug)) return true;

        $db = get_db_connection();
        $db->beginTransaction();

        try {
            // Find and parse plugin metadata headers
            $pluginsDir = __DIR__ . '/plugins';
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (!file_exists($pluginFile)) {
                throw new Exception("Plugin file not found: " . $pluginFile);
            }

            $meta = $this->parsePluginHeader($pluginFile);

            // Dynamically load, validate, prefix, and register permissions to DB
            if (!empty($meta['permissions'])) {
                $perm_list = array_map('trim', explode(',', $meta['permissions']));

                // Enforce that permissions must be uniquely prefixed with the plugin slug
                $clean_slug = preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
                $required_prefix = $clean_slug . '_';

                foreach ($perm_list as $perm_raw) {
                    $clean_perm = preg_replace('/[^a-zA-Z0-9_]/', '', $perm_raw);

                    // Enforce prefixing permission names with the plugin name
                    if (strpos($clean_perm, $required_prefix) !== 0) {
                        $clean_perm = $required_prefix . $clean_perm;
                    }

                    // Enforce that active plugins CANNOT share/conflict a permission name
                    $check_stmt = $db->prepare("SELECT id FROM permissions WHERE permission_name = ?");
                    $check_stmt->execute([$clean_perm]);
                    $exists = $check_stmt->fetch();

                    if ($exists) {
                        // Conflict found! Abort enablement
                        throw new Exception("Security Conflict: The permission name '{$clean_perm}' is already registered in the system.");
                    }

                    // Insert custom permission dynamically to database
                    $insert_stmt = $db->prepare("INSERT INTO permissions (permission_name, description) VALUES (?, ?)");
                    $insert_stmt->execute([$clean_perm, "Dynamic permission registered by plugin: " . $meta['name']]);
                }
            }

            // Save active state to DB
            $stmt = $db->prepare("INSERT IGNORE INTO active_plugins (plugin_slug) VALUES (?)");
            $stmt->execute([$slug]);

            $db->commit();
            $this->activePlugins[] = $slug;

            // Trigger activation action hook safely
            $this->doAction("activate_plugin_{$slug}");
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to activate plugin {$slug}: " . $e->getMessage());
            global $err;
            $err = $e->getMessage();
            return false;
        }
    }

    public function deactivatePlugin($slug)
    {
        if (!$this->isPluginActive($slug)) return true;

        $db = get_db_connection();
        $db->beginTransaction();

        try {
            // Find and parse plugin metadata headers
            $pluginsDir = __DIR__ . '/plugins';
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (file_exists($pluginFile)) {
                $meta = $this->parsePluginHeader($pluginFile);

                // Dynamically clean up / remove permissions on deactivation
                if (!empty($meta['permissions'])) {
                    $perm_list = array_map('trim', explode(',', $meta['permissions']));
                    $clean_slug = preg_replace('/[^a-zA-Z0-9_]/', '_', $slug);
                    $required_prefix = $clean_slug . '_';

                    foreach ($perm_list as $perm_raw) {
                        $clean_perm = preg_replace('/[^a-zA-Z0-9_]/', '', $perm_raw);
                        if (strpos($clean_perm, $required_prefix) !== 0) {
                            $clean_perm = $required_prefix . $clean_perm;
                        }

                        // Remove permission safely from permissions table (will cascade drop links to user_permissions)
                        $delete_stmt = $db->prepare("DELETE FROM permissions WHERE permission_name = ?");
                        $delete_stmt->execute([$clean_perm]);
                    }
                }
            }

            // Remove active state
            $stmt = $db->prepare("DELETE FROM active_plugins WHERE plugin_slug = ?");
            $stmt->execute([$slug]);

            $db->commit();

            if (($key = array_search($slug, $this->activePlugins)) !== false) {
                unset($this->activePlugins[$key]);
            }

            // Trigger deactivation action hook safely
            $this->doAction("deactivate_plugin_{$slug}");
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Failed to deactivate plugin {$slug}: " . $e->getMessage());
            return false;
        }
    }

    public function boot()
    {
        $pluginsDir = __DIR__ . '/plugins';
        foreach ($this->activePlugins as $slug) {
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (file_exists($pluginFile)) {
                // Wrap plugin booting to ensure faulty plugins don't break loading
                try {
                    require_once $pluginFile;
                } catch (Throwable $t) {
                    error_log("Error booting plugin [{$slug}]: " . $t->getMessage());
                }
            }
        }
    }
}
