# Portal Framework

An elegant, extensible, enterprise-grade PHP/MySQL modular portal framework. Developed with an isolated, WordPress-style action/filter hook engine, Azure AD Single Sign-On, dynamic Role-Based Access Control (RBAC), database isolation wrappers, parallel background task scheduling, and administrative management panels.

---

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Installation & Setup](#installation--setup)
3. [Core Global Variables & Helper Reference](#core-global-variables--helper-reference)
4. [Developing Plugins / Modules](#developing-plugins--modules)
   - [Requirements vs Optional Capabilities](#requirements-vs-optional-capabilities)
   - [Folder Structure Requirements](#folder-structure-requirements)
   - [Plugin Headers](#plugin-headers)
   - [Hook System (Actions & Filters)](#hook-system-actions--filters)
   - [Extensible Route Handlers & Views](#extensible-route-handlers--views)
   - [Dynamic Navigation Menus (Top-Level & Dropdowns)](#dynamic-navigation-menus)
   - [Home Dashboard Widgets](#home-dashboard-widgets)
   - [Inter-Plugin Exposed Services](#inter-plugin-exposed-services)
   - [Dynamic Permission & Role Loading](#dynamic-permission--role-loading)
   - [Database Isolation & SQL Prefixing](#database-isolation--prefixing)
   - [Task Scheduler API](#task-scheduler-api)
   - [Verbose Plugin Compatibility Inspector](#verbose-plugin-compatibility-inspector)
5. [Sample Plugin Walkthrough](#sample-plugin-walkthrough)
6. [Administrative Interfaces](#administrative-interfaces)

---

## Architecture Overview

This framework cleanly separates **Core Platform Concerns** (Authentication, User Provisioning, Access Auditing, Plugin Dispatching, Task Scheduling) from **Features** (which are encapsulated inside plugin modules).

Key components:
- **`PluginManager.php`**: The central orchestrator. Handles discovery, header parsing, compatibility testing, dynamic permission/role provisioning, route matching, and event hook dispatching.
- **`PluginDatabase.php`**: Secure database isolation wrapper ensuring plugins operate strictly inside safe table prefixes (`plug_{plugin_slug}_*`) and preventing unauthorized SQL mutations on core or sibling tables.
- **`Scheduler.php`**: Parallel background task scheduler with process spawning, concurrency locks, execution duration tracking, and stdout output capturing.
- **`Auth.php` & `AzureADSSO.php`**: OAuth2 Single Sign-On (SSO) integration with Azure Active Directory and dynamic RBAC with permission checks, role grants, direct permissions, and explicit denials.
- **`admin-plugins.php`**: Plugin discovery, test activation compatibility linter, and enablement dashboard.
- **`admin-scheduler.php`**: Visual task overrides, frequency customization, on-demand task runner, stdout viewer, and execution logs.
- **`admin-nav.php`**: Main navigation menu reordering, custom label overrides, and visibility toggle controls.
- **`admin-users.php`**: Visual user directory and RBAC permission management.

---

## Installation & Setup

1. **Database Schema Setup**
   Import `schema.sql` into your MySQL/MariaDB database:
   ```bash
   mysql -u your_user -p base_framework < schema.sql
   ```

2. **Configuration File**
   Modify `config.php` to define your DB credentials and Azure OAuth2 Client settings:
   ```php
   return [
       'azure' => [
           'clientId'     => 'YOUR_AZURE_CLIENT_ID',
           'clientSecret' => 'YOUR_AZURE_SECRET',
           'redirectUri'  => 'https://portal.yourdomain.com/callback.php',
           'tenantId'     => 'common',
       ],
       'db' => [
           'local' => [
               'dbhost' => '127.0.0.1',
               'dbname' => 'base_framework',
               'dbuser' => 'framework_user',
               'dbpass' => 'framework_pass',
           ]
       ]
   ];
   ```

3. **Cron Job Configuration (Task Scheduler)**
   Configure a 1-minute crontab entry to execute background tasks automatically:
   ```cron
   * * * * * php /path/to/portal/cron.php >/dev/null 2>&1
   ```

---

## Core Global Variables & Helper Reference

The following global components and helper functions are available to plugins at runtime:

### Global Variables
- **`$pluginManager`**: Singleton instance of `PluginManager`.
- **`$auth`**: Singleton instance of `Auth`.
- **`$_SESSION['user_id']`**: Integer Primary Key ID of the logged-in user.
- **`$_SESSION['user']`**: Array of user profile details (`azure_oid`, `email`, `name`, `groups`).
- **`$_SESSION['roles']`**: Array of active RBAC roles.
- **`$_SESSION['permissions']`**: Array of active Granted Permissions.

### Core Helper Wrappers (`functions.php`)
- `add_action($hook, $callback, $priority = 10)`: Bind callback to an action event.
- `add_filter($hook, $callback, $priority = 10)`: Bind callback to a filter hook.
- `register_route($route_name, $callback)`: Register a custom view route handler.
- `url_for($route_name)`: Helper returning formatted route URL (`index.php?route=$route_name`).
- `redirect($url)`: Performs instant HTTP location redirect.
- `has_permission($permission_name)`: Returns `true` if current user possesses permission.
- `has_role($role_name)`: Returns `true` if current user possesses role.
- `csrf_field()`: Renders hidden HTML CSRF input field.
- `validate_csrf()` / `csrf_verify()`: Validates incoming POST CSRF token.
- `log_action($action, $details)`: Writes entry to central audit logs.

---

## Developing Plugins / Modules

Extensions in this framework are fully self-contained. The platform automatically discovers all plugins inside the `/plugins/` directory.

### Requirements vs Optional Capabilities

To maintain a clean, maintainable, and stable ecosystem, plugin development distinguishes between **Strict Requirements** and **Optional Capabilities**:

#### Strict Requirements
1. **Directory Location & Entry File**:
   - MUST reside in its own sub-folder under `/plugins/{plugin-slug}/`.
   - MUST contain a primary entry file named `plugin.php` located directly at `/plugins/{plugin-slug}/plugin.php`.
2. **Plugin Header Metadata**:
   - The top of `plugin.php` MUST begin with a PHP header comment declaring at least `Plugin Name`, `Description`, `Version`, and `Author`.
3. **Database Table Isolation**:
   - If a plugin creates or queries database tables, table names MUST use the required plugin prefix `plug_{plugin_slug}_` via the `PluginDatabase` wrapper. Direct creation of un-prefixed tables or mutation of core tables is strictly blocked.
4. **Permission & Role Namespacing**:
   - Permissions and Roles declared in headers or checked in code SHOULD be namespaced with `{plugin_slug}_` to prevent namespace collisions across modules.
5. **Security Directives**:
   - `plugin.php` MUST include direct file access protection (`if (!defined('APP_ROOT')) ...`).
   - All POST routes MUST call `csrf_verify()` or `validate_csrf()`.

#### Optional Capabilities
- **Models / Business Logic**: Splitting complex logic into separate model files (`/models/`).
- **Views**: Separating HTML rendering into distinct view files (`/views/`).
- **Background Tasks**: Registering scheduled jobs using `init_scheduler` (`/tasks/`).
- **SQL Scripts**: Providing installation/uninstall schemas (`/sql/install.sql`, `/sql/uninstall.sql`).
- **Custom Routes**: Registering URL handlers using `register_route()`.
- **Navigation Items**: Inserting top-level or dropdown menu links using `theme_nav_links`.
- **Dashboard Widgets**: Rendering user-contextual cards on the home screen using `index_dashboard_widgets`.
- **Inter-Plugin Services**: Exposing API callbacks to sibling plugins using `registerService()`.

---

### Folder Structure Requirements

To keep the codebase modular, easy to understand, and maintainable, plugins should follow the standardized directory schema:

```text
/plugins/{plugin-slug}/
  │
  ├── plugin.php                   <-- REQUIRED: Primary entry point & hook orchestrator
  │
  ├── /models/                     <-- OPTIONAL: Data access & business logic models
  │     └── {plugin-slug}-models.php
  │
  ├── /views/                      <-- OPTIONAL: Template view files per route/page
  │     ├── calendar-view.php
  │     ├── settings-view.php
  │     └── ...
  │
  ├── /tasks/                      <-- OPTIONAL: Background scheduled task handlers
  │     ├── sync-task.php
  │     └── ...
  │
  ├── /sql/                        <-- OPTIONAL: Automated DB schema scripts
  │     ├── install.sql            <-- Executed automatically on plugin activation
  │     └── uninstall.sql          <-- Executed automatically on plugin deactivation
  │
  └── /assets/                     <-- OPTIONAL: Static frontend CSS, JS, images
        ├── css/
        └── js/
```

---

### Plugin Headers

To register your plugin, place a comment block containing metadata at the top of your `plugin.php` file:

```php
<?php
/**
 * Plugin Name: On-Call Schedule Manager
 * Description: Enterprise On-Call Rotation, Shift Trade Center, and Zabbix Integration.
 * Version: 2.1
 * Author: DevDog
 * Permissions: view_schedule, manage_schedule, manage_trades, manage_settings
 * Roles: manager:view_schedule,manage_schedule,manage_trades,manage_settings; operator:view_schedule,manage_trades; viewer:view_schedule
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/../../');
}
```

---

### Hook System (Actions & Filters)

#### 1. Filters (Modify data arrays or strings)
Filters accept a variable, modify it, and return it.

* **`theme_nav_links`**: Insert navigation items (top-level or dropdowns) to the header.
  ```php
  add_filter('theme_nav_links', function ($links) {
      $links[] = [
          'label' => 'On-Call Schedule',
          'icon'  => 'fa-solid fa-phone-volume',
          'route' => 'oncall_calendar',
          'children' => [
              ['label' => 'Rotation Calendar', 'icon' => 'fa-solid fa-calendar-days', 'route' => 'oncall_calendar'],
              ['label' => 'Shift Trade Center', 'icon' => 'fa-solid fa-handshake', 'route' => 'oncall_trades']
          ]
      ];
      return $links;
  });
  ```

#### 2. Actions (Trigger output or side-effects)
* **`theme_head`**: Inject custom stylesheets, meta tags, or CDN scripts into `<head>`.
* **`theme_footer`**: Inject scripts before `</body>`.

---

### Extensible Route Handlers & Views

Match URLs dynamically and delegate page output cleanly:

```php
add_action('register_routes', function() {
    register_route('my_plugin_dashboard', function() {
        if (!has_permission('my_plugin_view_stats')) {
            die('Access Denied');
        }

        require_once __DIR__ . '/views/dashboard-view.php';
    });
});
```

Load `index.php?route=my_plugin_dashboard` to render this page inside the core theme layout.

---

### Dynamic Navigation Menus

Plugins can register simple links or nested dropdown menus in `theme_nav_links`. Administrators can rearrange positions, rename labels, or hide links via the **Navigation Manager** (`admin-nav.php`).

---

### Home Dashboard Widgets

Plugins can render contextual widget cards on the home dashboard (`index.php`). The framework passes the authenticated user's context array:

```php
add_action('index_dashboard_widgets', function($userContext) {
    ?>
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm border-start border-4 border-primary">
            <div class="card-body">
                <h6 class="fw-bold">Hello, <?= htmlspecialchars($userContext['display_name']) ?>!</h6>
                <p class="small text-muted mb-0">Role: <?= implode(', ', $userContext['roles']) ?></p>
            </div>
        </div>
    </div>
    <?php
});
```

---

### Inter-Plugin Exposed Services

Plugins can expose API capabilities to sibling modules securely. All calls run inside the active user session context:

```php
// Register service in Plugin A
PluginManager::getInstance()->registerService(
    'fetch_sales_report',
    function($month) {
        return ['month' => $month, 'sales' => 14000];
    },
    'plugin-a'
);

// Call service from Plugin B
try {
    $data = PluginManager::getInstance()->callService('fetch_sales_report', 'October');
    echo "Sales: " . $data['sales'];
} catch (Exception $e) {
    echo "Service call failed: " . $e->getMessage();
}
```

---

### Dynamic Permission & Role Loading

When a plugin is activated, `PluginManager` parses the `Permissions:` and `Roles:` headers:
- Permissions listed are automatically registered into the DB permissions directory with the `{plugin_slug}_` prefix.
- Roles declared (e.g. `Roles: manager:perm1,perm2; operator:perm1`) are created in the roles table and linked to their permissions.
- On deactivation, dynamic roles and permissions are uninstalled cleanly.

---

### Database Isolation & SQL Prefixing

Plugins MUST use `PluginDatabase.php` for isolated database access inside the `plug_{plugin_slug}_` namespace:

```php
require_once __DIR__ . '/../../PluginDatabase.php';

$pdb = new PluginDatabase('my-plugin');

// Create table plug_my_plugin_records
$pdb->createTable('records', "
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
");

// Query isolated schema
$tb = $pdb->getTableName('records');
$pdb->query("INSERT INTO {$tb} (title) VALUES (?)", ['Item 1']);
$rows = $pdb->query("SELECT * FROM {$tb}")->fetchAll();
```

---

### Task Scheduler API

Register background jobs executing at interval or fixed weekly schedules:

```php
add_action('init_scheduler', function($scheduler) {
    $scheduler->registerTask(
        'sync_data',                // Task key
        'my_plugin_sync_task',      // Callback function name
        3600,                       // Interval in seconds
        'my-plugin'                 // Plugin slug
    );
});

function my_plugin_sync_task() {
    log_action('MY_PLUGIN_SYNC_COMPLETE', []);
}
```

Administrators can configure custom intervals, set fixed days/times, or trigger tasks **On-Demand** with stdout capture in `admin-scheduler.php`.

---

### Verbose Plugin Compatibility Inspector

Administrators can click **Check Compatibility** in `admin-plugins.php` before activating a plugin. The inspector dry-runs diagnostic checks:
- PHP file syntax validation (with fallback token parser)
- Entry file and directory structure inspection
- Permission and role namespace collision detection
- SQL schema statement prefixing verification (`plug_{plugin_slug}_*`)
- Full report of expected changes (tables created, permissions registered, roles provisioned)

---

## Sample Plugin Walkthrough

See `plugins/sample-manager/plugin.php` for a complete example demonstrating routes, views, background tasks, SQL install/uninstall scripts, navigation links, and service registration.

---

## Administrative Interfaces

- **Modules & Plugins (`admin-plugins.php`)**: Plugin discovery, compatibility linter, activation/deactivation triggers.
- **Task Scheduler (`admin-scheduler.php`)**: Custom schedule overrides, enable/disable switches, on-demand task runner with stdout viewer, and execution logs.
- **Navigation Manager (`admin-nav.php`)**: Main menu link re-ordering, custom label overrides, and show/hide visibility toggles.
- **Users & RBAC (`admin-users.php`)**: User directory, role assignments, direct permission grants, and explicit permission denials.
- **Audit Trail Logs (`admin-logs.php`)**: Action audit logs with dynamic search, filters, and sorting.
- **System Diagnostics (`admin-diagnostics.php`)**: Server diagnostics, database schema inspections, and PHP environment checks.
