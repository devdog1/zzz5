<?php
// functions.php - Global Utility Helpers & Framework Wrappers
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/PluginManager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global Auth Reference
$auth = null;

function get_auth() {
    global $auth;
    if ($auth === null) {
        $config = require __DIR__ . '/config.php';
        $auth = new Auth($config);
    }
    return $auth;
}

// Global Plugin Manager Reference
$pluginManager = PluginManager::getInstance();

/* =========================================================
 * CORE HELPERS & PERMISSIONS
 * ========================================================= */

function has_permission($permission) {
    return get_auth()->hasPermission($permission);
}

function has_role($role) {
    return get_auth()->hasRole($role);
}

function require_login() {
    get_auth()->requireLogin();
}

/* =========================================================
 * SECURITY: CSRF (Anti-Forgery Tokens)
 * ========================================================= */

/**
 * Generate a cryptographically secure CSRF token and store it in session.
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden HTML input containing the active CSRF token.
 */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(get_csrf_token()) . '">';
}

/**
 * Validate that the incoming request contains a valid anti-forgery token.
 */
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';
        if (empty($token) || empty($session_token) || !hash_equals($session_token, $token)) {
            // Log security warning
            log_action('SECURITY_CSRF_VIOLATION', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);

            // Render basic warning screen and exit
            http_response_code(403);
            die("<h1>403 Forbidden: Security CSRF Verification Failed.</h1><p>Please reload the page and try again.</p>");
        }
    }
}

/* =========================================================
 * SYSTEM SETTINGS API
 * ========================================================= */

function get_setting($key, $default = null) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function set_setting($key, $value) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $value]);
        log_action('SET_SETTING', ['key' => $key, 'value' => $value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* =========================================================
 * AUDIT LOGGING API
 * ========================================================= */

function log_action($action, $details) {
    try {
        $db = get_db_connection();
        $user_id = $_SESSION['user_id'] ?? null;
        $username = null;

        if ($user_id) {
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $res = $stmt->fetch();
            $username = $res ? $res['username'] : null;
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (is_array($details) || is_object($details)) {
            $details = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, username, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $username, $action, $details, $ip_address]);
    } catch (Exception $e) {
        error_log("Failed to write audit log: " . $e->getMessage());
    }
}

function get_audit_logs($limit = 100) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT al.*, u.display_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.timestamp DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/* =========================================================
 * USER DIRECTORY MANAGEMENT API
 * ========================================================= */

function get_all_users() {
    try {
        $db = get_db_connection();
        $stmt = $db->query("SELECT * FROM users ORDER BY display_name ASC");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function get_user_by_id($id) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/* =========================================================
 * BOOTSTRAP HOOKS / PLUGINS SYSTEM
 * ========================================================= */

// Boot active plugins on system inclusion
$pluginManager->boot();
