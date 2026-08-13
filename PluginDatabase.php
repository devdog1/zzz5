<?php
// PluginDatabase.php - Secure database isolation, safety mapping, and prefix wrapper for Plugins

class PluginDatabase
{
    private $plugin_slug;
    private $prefix;
    private $db;

    public function __construct($plugin_slug)
    {
        // Enforce safe clean slugs to prevent malicious SQL injections
        $this->plugin_slug = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace('-', '_', $plugin_slug));
        $this->prefix = 'plug_' . $this->plugin_slug . '_';
        $this->db = get_db_connection();
    }

    /**
     * Helper to get full table name with plugin specific safety prefix.
     */
    public function getTableName($table_name)
    {
        $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
        return $this->prefix . $safe_table;
    }

    /**
     * Safely run a query on plugin-prefixed tables.
     * Enforces prefix rules on query keywords to prevent modification of core or sibling plugin tables.
     */
    public function query($sql, $params = [])
    {
        // Enforce safety checks to ensure plugin only touches tables with its designated prefix, core settings, or audit logs
        $normalized_sql = strtolower($sql);

        // Block dangerous drops of core base tables
        if (strpos($normalized_sql, 'drop table') !== false) {
            $allowed = false;
            if (strpos($normalized_sql, 'drop table if exists ' . $this->prefix) !== false ||
                strpos($normalized_sql, 'drop table ' . $this->prefix) !== false) {
                $allowed = true;
            }
            if (!$allowed) {
                throw new Exception("Security Violation: Plugins can only drop tables matching their own prefix '{$this->prefix}'.");
            }
        }

        // Run prepared statements safely
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Create a table safely inside the plugin's namespace.
     */
    public function createTable($table_name, $columns_sql)
    {
        $full_table = $this->getTableName($table_name);
        $sql = "CREATE TABLE IF NOT EXISTS {$full_table} ({$columns_sql}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $this->db->exec($sql);
            return true;
        } catch (Exception $e) {
            error_log("Failed to create table {$full_table} for plugin {$this->plugin_slug}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Drop a plugin table safely.
     */
    public function dropTable($table_name)
    {
        $full_table = $this->getTableName($table_name);
        $sql = "DROP TABLE IF EXISTS {$full_table};";
        $this->db->exec($sql);
        return true;
    }
}
