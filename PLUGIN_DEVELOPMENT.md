# Plugin & Module Development Guide

This guide details the architecture, standards, mandatory requirements, optional capabilities, folder layout conventions, header specifications, database isolation policies, and API references for developing extension modules in the Portal Framework.

---

## Table of Contents
1. [Requirements vs Optional Capabilities](#1-requirements-vs-optional-capabilities)
   - [Mandatory Requirements](#mandatory-requirements)
   - [Optional Capabilities](#optional-capabilities)
2. [Folder Structure Requirements](#2-folder-structure-requirements)
3. [Plugin Header Metadata](#3-plugin-header-metadata)
4. [Automated Schema Setup & Uninstall (`install.sql` / `uninstall.sql`)](#4-automated-schema-setup--uninstall-installsql--uninstallsql)
5. [Plugin Versioning & SQL Migrations](#5-plugin-versioning--sql-migrations)
6. [Outbound Email Delivery in Plugins](#6-outbound-email-delivery-in-plugins)
7. [Asset Queueing API (`enqueue_script`, `enqueue_style`)](#7-asset-queueing-api-enqueue_script-enqueue_style)
8. [Translation & i18n API (`__($text, $domain)`)](#8-translation--i18n-api-__text-domain)
9. [REST API Router (`register_api_route`)](#9-rest-api-router-register_api_route)
10. [Asynchronous Event Bus (`dispatch_event`, `add_event_listener`)](#10-asynchronous-event-bus-dispatch_event-add_event_listener)
11. [CLI Plugin Management Interface](#11-cli-plugin-management-interface)
12. [Hook System (Actions, Filters & Hook Unbinding)](#12-hook-system-actions-filters--hook-unbinding)
13. [Extensible Route Handlers & Views](#13-extensible-route-handlers--views)
14. [Dynamic Navigation Menus (Top-Level & Nested Dropdowns)](#14-dynamic-navigation-menus)
15. [Home Dashboard Widgets](#15-home-dashboard-widgets)
16. [Inter-Plugin Exposed Services](#16-inter-plugin-exposed-services)
17. [Dynamic Permission & Role Loading](#17-dynamic-permission--role-loading)
18. [Database Isolation & SQL Prefixing](#18-database-isolation--sql-prefixing)
19. [Plugin Settings API](#19-plugin-settings-api)
20. [Task Scheduler API](#20-task-scheduler-api)
21. [Verbose Plugin Compatibility Inspector](#21-verbose-plugin-compatibility-inspector)

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
- **Dependencies & Version Requirements**: Declaring `Requires PHP:`, `Requires Core:`, and `Requires Plugins:` (comma-separated dependent plugin slugs) in headers.
- **Assets Enqueuing**: Registering frontend CSS/JS files using `enqueue_style()` and `enqueue_script()`.
- **Translations & i18n**: Storing language JSON files in `/plugins/{slug}/languages/` and translating strings via `__($text, $domain)`.
- **REST API Endpoints**: Exposing JSON API endpoints via `register_api_route()`.
- **Event Bus Queueing**: Subscribing to or queuing events via `add_event_listener()` and `dispatch_event($event, $payload, $async)`.
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
  ├── /languages/                  <-- OPTIONAL: JSON i18n translation dictionaries
  │     ├── {plugin-slug}-en_US.json
  │     └── ...
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
  │     ├── uninstall.sql          <-- Executed automatically on plugin deactivation (when purge selected)
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
 * Requires PHP: 8.1
 * Requires Core: 1.1.0
 * Requires Plugins: zabbix-connector, user-notifier
 * Permissions: view_schedule, manage_schedule, manage_trades, manage_settings
 * Roles: manager:view_schedule,manage_schedule,manage_trades,manage_settings; operator:view_schedule,manage_trades; viewer:view_schedule
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/../../');
}
```

---

## 4. Automated Schema Setup & Uninstall (`install.sql` / `uninstall.sql`)

Plugins can provide automated database table creation and cleanup scripts inside `/sql/`:

* **`install.sql`**: Executed automatically by `PluginManager::activatePlugin()` during plugin activation. Uses `{prefix}` as a placeholder for `plug_{plugin_slug}_`.
* **`uninstall.sql`**: Executed automatically by `PluginManager::deactivatePlugin()` when the administrator selects "Purge Database Tables" upon deactivation.

---

## 5. Plugin Versioning & SQL Migrations

Plugins declare a semantic version number in their `Version:` header (e.g. `Version: 1.2.0`). The core framework tracks installed plugin versions in the `active_plugins` table (`installed_version` column).

When a plugin is updated on disk with a higher version number, `PluginManager::boot()` automatically detects the version difference and triggers `PluginManager::migratePlugin()` during framework initialization to sequentially execute `/sql/migrations/*.sql` scripts.

---

## 6. Outbound Email Delivery in Plugins

Plugins can send transactional email notifications using the `send_email()` helper function:

```php
$result = send_email(
    'user@example.com',                           // Recipient email
    'Shift Trade Approved',                       // Subject line
    '<h3>Shift Trade Confirmation</h3><p>...</p>', // Message body (HTML or plain text)
    'oncall-manager',                             // Plugin slug (for log attribution)
    'oncall-alerts@example.com'                   // Optional custom From address
);
```

---

## 7. Asset Queueing API (`enqueue_script`, `enqueue_style`)

Plugins can safely enqueue CSS stylesheets and JS scripts without manually injecting HTML or duplicate script tags:

```php
// Enqueue a stylesheet into <head>
enqueue_style('chartjs-css', 'https://cdn.jsdelivr.net/npm/chart.js/dist/chart.min.css', [], '4.4.0');

// Enqueue a JavaScript file before </body>
enqueue_script('chartjs-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true);
```

---

## 8. Translation & i18n API (`__($text, $domain)`)

Plugins can translate strings using language JSON files located in `/plugins/{slug}/languages/{slug}-{lang}.json`:

```php
// Translate string with 'oncall-manager' text domain
echo __('Rotation Calendar', 'oncall-manager');
```

---

## 9. REST API Router (`register_api_route`)

Plugins can register dedicated REST API endpoints returning JSON responses with HTTP status codes and permission checks:

```php
register_api_route('api/v1/oncall/shifts', 'GET', function($params) {
    return [
        'status' => 'success',
        'shifts' => [ ['id' => 101, 'user' => 'jdoe'] ]
    ];
}, 'oncall_view_schedule');
```

---

## 10. Asynchronous Event Bus (`dispatch_event`, `add_event_listener`)

Plugins can subscribe to or dispatch synchronous or asynchronous events:

```php
// Subscribe to event
add_event_listener('shift_assigned', function($payload) {
    log_action('SHIFT_ASSIGNED_EVENT', $payload);
});

// Dispatch event asynchronously (queued in `event_queue` table)
dispatch_event('shift_assigned', ['user_id' => 42, 'shift_id' => 101], true);
```

---

## 11. CLI Plugin Management Interface

Administrators and developers can manage plugins directly from the command line using `cron.php`:

```bash
# List all discovered plugins and their active state
php cron.php --plugin-list

# Run compatibility inspection on a plugin
php cron.php --plugin-inspect=oncall-manager

# Activate a plugin
php cron.php --plugin-activate=oncall-manager

# Deactivate a plugin
php cron.php --plugin-deactivate=oncall-manager

# Print Portal Framework version
php cron.php --version
```

---

## 12. Hook System (Actions, Filters & Hook Unbinding)

### Filters
```php
add_filter('theme_nav_links', function ($links) { ... });
```

### Hook Unbinding
```php
remove_action('theme_footer', 'my_custom_footer_callback', 10);
remove_filter('theme_nav_links', 'my_custom_nav_filter', 10);
```

---

## 13. Extensible Route Handlers & Views

```php
add_action('register_routes', function() {
    register_route('my_plugin_dashboard', function() {
        require_once __DIR__ . '/views/dashboard-view.php';
    });
});
```

---

## 14. Dynamic Navigation Menus

Plugins can register simple links, top-level menus with children, or attach individual nested sub-menu items dynamically using the `parent` attribute in `theme_nav_links`.

---

## 15. Home Dashboard Widgets

Plugins can render contextual widget cards on the home dashboard (`index.php`).

---

## 16. Inter-Plugin Exposed Services

```php
PluginManager::getInstance()->registerService('fetch_sales_report', function($month) { ... }, 'plugin-a');
$data = PluginManager::getInstance()->callService('fetch_sales_report', 'October');
```

---

## 17. Dynamic Permission & Role Loading

When a plugin is activated, `PluginManager` parses the `Permissions:` and `Roles:` headers and provisions DB records safely.

---

## 18. Database Isolation & SQL Prefixing

Plugins MUST use `PluginDatabase.php` for isolated database access inside the `plug_{plugin_slug}_` namespace.

---

## 19. Plugin Settings API

```php
set_plugin_setting('my-plugin', 'api_key', 'SECRET_123');
$apiKey = get_plugin_setting('my-plugin', 'api_key', 'default_value');
```

---

## 20. Task Scheduler API

```php
add_action('init_scheduler', function($scheduler) {
    $scheduler->registerTask('sync_data', 'my_plugin_sync_task', 3600, 'my-plugin');
});
```

---

## 21. Verbose Plugin Compatibility Inspector

Administrators can click **Check Compatibility** in `admin-plugins.php` before activating a plugin.
