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
     * Inspects mutating SQL queries to enforce strict table isolation, preventing plugins
     * from modifying core base tables or tables belonging to other plugins.
     */
    private function validateMutationAccess($sql)
    {
        $clean_sql = trim(preg_replace('/\s+/', ' ', $sql));
        $upper_sql = strtoupper($clean_sql);

        // Identify mutating statements
        $is_mutation = false;
        foreach (['INSERT INTO', 'UPDATE', 'DELETE FROM', 'DROP TABLE', 'ALTER TABLE', 'TRUNCATE'] as $keyword) {
            if (strpos($upper_sql, $keyword) !== false) {
                $is_mutation = true;
                break;
            }
        }

        if (!$is_mutation) {
            return; // Read-only SELECT queries are allowed
        }

        // Extract target table names being mutated
        preg_match_all('/(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|TRUNCATE\s+(?:TABLE)?)\s+[`\']?([a-zA-Z0-9_]+)[`\']?/i', $clean_sql, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $target_table) {
                // Ignore SQL keywords that might be caught
                if (in_array(strtoupper($target_table), ['SET', 'SELECT', 'WHERE', 'JOIN', 'TABLE', 'IF', 'EXISTS'])) {
                    continue;
                }

                // Target table MUST start with $this->prefix
                if (strpos($target_table, $this->prefix) !== 0) {
                    throw new Exception("Security Violation: Module '{$this->plugin_slug}' attempted unauthorized modification on table '{$target_table}'. Modules can only modify tables matching their own prefix '{$this->prefix}'.");
                }
            }
        }
    }

    /**
     * Safely run a query on plugin-prefixed tables.
     * Enforces prefix rules on query keywords to prevent modification of core or sibling plugin tables.
     */
    public function query($sql, $params = [])
    {
        // Enforce strict security mutation validation
        $this->validateMutationAccess($sql);

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

        // Enforce security check before dropping
        $this->validateMutationAccess($sql);

        $this->db->exec($sql);
        return true;
    }
}
