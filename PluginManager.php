<?php
// PluginManager.php - Robust WordPress-inspired Hooks, Filters, Routing & Plugin Management Engine
require_once __DIR__ . '/db.php';

class PluginManager
{
    private static $instance = null;
    private $actions = [];
    private $filters = [];
    private $routes = [];
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
                // SANDBOX SHIELD: Wrap each plugin hook execution in try/catch to avoid one bad plugin crashing others or breaking core platform.
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
                // SANDBOX SHIELD: Wrap each filter hook execution in try/catch so if a plugin filter throws, we fall back to returning the current state instead of crashing.
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
            // Silence if DB is not set up yet
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
            'author' => 'Anonymous'
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

        return $meta;
    }

    public function isPluginActive($slug)
    {
        return in_array($slug, $this->activePlugins);
    }

    public function activatePlugin($slug)
    {
        if ($this->isPluginActive($slug)) return true;

        try {
            $db = get_db_connection();
            $stmt = $db->prepare("INSERT IGNORE INTO active_plugins (plugin_slug) VALUES (?)");
            $stmt->execute([$slug]);

            $this->activePlugins[] = $slug;

            // Trigger activation action hook safely
            $this->doAction("activate_plugin_{$slug}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function deactivatePlugin($slug)
    {
        if (!$this->isPluginActive($slug)) return true;

        try {
            $db = get_db_connection();
            $stmt = $db->prepare("DELETE FROM active_plugins WHERE plugin_slug = ?");
            $stmt->execute([$slug]);

            if (($key = array_search($slug, $this->activePlugins)) !== false) {
                unset($this->activePlugins[$key]);
            }

            // Trigger deactivation action hook safely
            $this->doAction("deactivate_plugin_{$slug}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function boot()
    {
        $pluginsDir = __DIR__ . '/plugins';
        foreach ($this->activePlugins as $slug) {
            $pluginFile = $pluginsDir . '/' . $slug . '/plugin.php';
            if (file_exists($pluginFile)) {
                // Wrap plugin booting / inclusion so parsing / run runtime failures do not break index page
                try {
                    require_once $pluginFile;
                } catch (Throwable $t) {
                    error_log("Error booting plugin [{$slug}]: " . $t->getMessage());
                }
            }
        }
    }
}
