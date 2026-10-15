# Plugin & Module Development Guide

This guide details the architecture, standards, mandatory requirements, optional capabilities, folder layout conventions, header specifications, database isolation policies, and API references for developing extension modules in the Portal Framework.

---

## Table of Contents
1. [Requirements vs Optional Capabilities](#1-requirements-vs-optional-capabilities)
   - [Mandatory Requirements](#mandatory-requirements)
   - [Optional Capabilities](#optional-capabilities)
2. [Folder Structure Requirements](#2-folder-structure-requirements)
3. [Plugin Header Metadata](#3-plugin-header-metadata)
4. [Plugin Versioning & SQL Migrations](#4-plugin-versioning--sql-migrations)
5. [Outbound Email Delivery in Plugins](#5-outbound-email-delivery-in-plugins)
6. [Hook System (Actions & Filters)](#6-hook-system-actions--filters)
7. [Extensible Route Handlers & Views](#7-extensible-route-handlers--views)
8. [Dynamic Navigation Menus (Top-Level & Nested Dropdowns)](#8-dynamic-navigation-menus)
9. [Home Dashboard Widgets](#9-home-dashboard-widgets)
10. [Inter-Plugin Exposed Services](#10-inter-plugin-exposed-services)
11. [Dynamic Permission & Role Loading](#11-dynamic-permission--role-loading)
12. [Database Isolation & SQL Prefixing](#12-database-isolation--sql-prefixing)
13. [Plugin Settings API](#13-plugin-settings-api)
14. [Task Scheduler API](#14-task-scheduler-api)
15. [Verbose Plugin Compatibility Inspector](#15-verbose-plugin-compatibility-inspector)

---

## 1. Requirements vs Optional Capabilities

Extensions in this framework are fully self-contained. The platform automatically discovers all plugins inside the `/plugins/` directory.

### Mandatory Requirements
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

### Optional Capabilities
- **Models / Business Logic**: Splitting complex logic into separate model files (`/models/`).
- **Views**: Separating HTML rendering into distinct view files (`/views/`).
- **Background Tasks**: Registering scheduled jobs using `init_scheduler` (`/tasks/`).
- **SQL Scripts & Migrations**: Providing installation/uninstall schemas (`/sql/install.sql`, `/sql/uninstall.sql`) and versioned migration files (`/sql/migrations/*.sql`).
- **Custom Routes**: Registering URL handlers using `register_route()`.
- **Navigation Items**: Inserting top-level or dropdown menu links using `theme_nav_links`.
- **Dashboard Widgets**: Rendering user-contextual cards on the home screen using `index_dashboard_widgets`.
- **Inter-Plugin Services**: Exposing API callbacks to sibling plugins using `registerService()`.

---

## 2. Folder Structure Requirements

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
  ├── /sql/                        <-- OPTIONAL: Automated DB schema & migration scripts
  │     ├── install.sql            <-- Executed automatically on plugin activation
  │     ├── uninstall.sql          <-- Executed automatically on plugin deactivation
  │     └── /migrations/           <-- Versioned SQL migration scripts (e.g., 1.1.0.sql, 2.0.0.sql)
  │
  └── /assets/                     <-- OPTIONAL: Static frontend CSS, JS, images
        ├── css/
        └── js/
```

---

## 3. Plugin Header Metadata

Every plugin must start with a header block in `plugin.php`:

```php
<?php
/**
 * Plugin Name: On-Call Schedule Manager
 * Description: Enterprise On-Call Rotation, Shift Trade Center, and Zabbix Integration.
 * Version: 2.1.0
 * Author: DevDog
 * Permissions: view_schedule, manage_schedule, manage_trades, manage_settings
 * Roles: manager:view_schedule,manage_schedule,manage_trades,manage_settings; operator:view_schedule,manage_trades; viewer:view_schedule
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/../../');
}
```

---

## 4. Plugin Versioning & SQL Migrations

Plugins declare a semantic version number in their `Version:` header (e.g. `Version: 1.2.0`). The core framework tracks installed plugin versions in the `active_plugins` table (`installed_version` column).

When a plugin is updated on disk with a higher version number, `PluginManager::boot()` automatically detects the version difference and triggers `PluginManager::migratePlugin()` during framework initialization:

1. **Sequential SQL Migration Execution**:
   - Looks for SQL files in `/plugins/{plugin-slug}/sql/migrations/` named after target version increments (e.g. `1.1.0.sql`, `1.2.0.sql`, `2.0.0.sql`).
   - Filters and sorts migration files sequentially where `migration_version > installed_version` and `<= current_disk_version`.
   - Automatically replaces `{prefix}` placeholders with the required isolated plugin table prefix (`plug_{plugin_slug}_`).
   - Executes each SQL migration step within the database and updates `installed_version` step-by-step.

2. **Custom Migration Hook**:
   - Triggers the `plugin_migrate_{plugin_slug}` action hook after SQL migrations complete, passing `($installedVersion, $currentVersion)`. Plugins can hook into this event to perform programmatic data transformations.

3. **Version Finalization**:
   - Updates `installed_version` in the `active_plugins` table to match the current disk version and logs `PLUGIN_MIGRATED_SUCCESS` to the audit trail.

---

## 5. Outbound Email Delivery in Plugins

Plugins can send transactional email notifications using the `send_email()` helper function:

```php
$result = send_email(
    'user@example.com',                           // Recipient email
    'Shift Trade Approved',                       // Subject line
    '<h3>Shift Trade Confirmation</h3><p>...</p>', // Message body (HTML or plain text)
    'oncall-manager',                             // Plugin slug (for log attribution)
    'oncall-alerts@example.com'                   // Optional custom From address
);

if ($result['success']) {
    // Email accepted by SMTP server (SMTP Code e.g. '250 2.0.0')
} else {
    // Delivery failed (SMTP Code e.g. '550 5.1.1', Error msg in $result['smtp_message'])
}
```

Key features for plugin emails:
- **Automatic Attribution**: Specifies `plugin_slug` to tag outbound emails in the `email_logs` table and adds an `X-Plugin-Source: {plugin_slug}` header.
- **Custom From Address**: Plugins can pass a custom `$custom_from` address while benefiting from core SMTP relay and bounce envelope configurations.
- **Audit Logging**: Every email dispatch generates an `OUTBOUND_EMAIL_SENT` audit trail log and records full SMTP DSN response codes for troubleshooting.

---

## 6. Hook System (Actions & Filters)

### Filters (Modify data arrays or strings)
Filters accept a variable, modify it, and return it.

```php
add_filter('theme_nav_links', function ($links) {
    // 1. Full Top-Level Menu with Children
    $links[] = [
        'label' => 'On-Call Schedule',
        'icon'  => 'fa-solid fa-phone-volume',
        'route' => 'oncall_calendar',
        'children' => [
            ['label' => 'Rotation Calendar', 'icon' => 'fa-solid fa-calendar-days', 'route' => 'oncall_calendar'],
            ['label' => 'Shift Trade Center', 'icon' => 'fa-solid fa-handshake', 'route' => 'oncall_trades']
        ]
    ];

    // 2. Direct Nested Item Attachment (No pre-existing parent required!)
    $links[] = [
        'label'        => 'Override Management',
        'icon'         => 'fa-solid fa-user-gear',
        'route'        => 'oncall_overrides',
        'parent'       => 'On-Call Schedule',      // Matches parent route or label
        'parent_icon'  => 'fa-solid fa-phone-volume' // Optional parent icon fallback
    ];

    return $links;
});
```

### Actions (Trigger output or side-effects)
* **`theme_head`**: Inject custom stylesheets, meta tags, or CDN scripts into `<head>`.
* **`theme_footer`**: Inject scripts before `</body>`.

---

## 7. Extensible Route Handlers & Views

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

---

## 8. Dynamic Navigation Menus

Plugins can register simple links, top-level menus with children, or attach individual nested sub-menu items dynamically using the `parent` attribute in `theme_nav_links`.

If the parent navigation node exists, the nested item is appended to its dropdown menu. If the parent node does not exist, the framework automatically instantiates the parent main menu item. Administrators can rearrange positions, rename labels, or hide links via the **Navigation Manager** (`admin-nav.php`).

---

## 9. Home Dashboard Widgets

Plugins can render contextual widget cards on the home dashboard (`index.php`). The framework passes the authenticated user's context array:

```php
add_action('index_dashboard_widgets', function($userContext) {
    ?>
    <div class="card shadow-sm border-start border-4 border-primary">
        <div class="card-body">
            <h6 class="fw-bold">Hello, <?= htmlspecialchars($userContext['display_name']) ?>!</h6>
            <p class="small text-muted mb-0">Role: <?= implode(', ', $userContext['roles']) ?></p>
        </div>
    </div>
    <?php
});
```

---

## 10. Inter-Plugin Exposed Services

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

## 11. Dynamic Permission & Role Loading

When a plugin is activated, `PluginManager` parses the `Permissions:` and `Roles:` headers:
- Permissions listed are automatically registered into the DB permissions directory with the `{plugin_slug}_` prefix.
- Roles declared (e.g. `Roles: manager:perm1,perm2; operator:perm1`) are created in the roles table and linked to their permissions.
- On deactivation, dynamic roles and permissions are uninstalled cleanly.

---

## 12. Database Isolation & SQL Prefixing

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

## 13. Plugin Settings API

Plugins can store and retrieve isolated settings using standard helper functions:

```php
// Save setting 'api_key' for plugin 'my-plugin'
set_plugin_setting('my-plugin', 'api_key', 'SECRET_123');

// Get setting 'api_key'
$apiKey = get_plugin_setting('my-plugin', 'api_key', 'default_value');
```

---

## 14. Task Scheduler API

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

---

## 15. Verbose Plugin Compatibility Inspector

Administrators can click **Check Compatibility** in `admin-plugins.php` before activating a plugin. The inspector dry-runs diagnostic checks:
- PHP file syntax validation (with fallback token parser)
- Entry file and directory structure inspection
- Permission and role namespace collision detection
- SQL schema statement prefixing verification (`plug_{plugin_slug}_*`)
- Full report of expected changes (tables created, permissions registered, roles provisioned)
