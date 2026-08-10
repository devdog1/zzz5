<?php
// PluginManager.php - Robust WordPress-inspired Hooks, Filters, Routing & Plugin Management Engine

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
                call_user_func_array($callback, $arg);
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
                $value = call_user_func_array($callback, array_merge([$value], $arg));
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
            call_user_func($this->routes[$route]);
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

            // Trigger activation action hook if any
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

            // Trigger deactivation action hook if any
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
                require_once $pluginFile;
            }
        }
    }
}
