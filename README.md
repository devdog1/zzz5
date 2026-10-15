# Portal Framework

An elegant, extensible, enterprise-grade PHP/MySQL modular portal framework. Developed with an isolated, WordPress-style action/filter hook engine, Azure AD Single Sign-On (SSO) with Microsoft Graph API integrations, dynamic Role-Based Access Control (RBAC), database isolation wrappers, parallel background task scheduling, customizable home dashboard widgets, outbound SMTP email delivery engine with DSN tracking, multi-version plugin database migrations, framework schema migration management, and administrative control panels.

---

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Installation & Setup](#installation--setup)
3. [Framework Versioning, Changelog & Schema Upgrades](#framework-versioning-changelog--schema-upgrades)
4. [Core Global Variables & Helper Reference](#core-global-variables--helper-reference)
5. [Azure AD SSO & Microsoft Graph API Engine](#azure-ad-sso--microsoft-graph-api-engine)
6. [Outbound SMTP Email Handler & DSN Tracking Engine](#outbound-smtp-email-handler--dsn-tracking-engine)
7. [Dashboard Widget Customizer Engine](#dashboard-widget-customizer-engine)
8. [Developing Plugins / Modules](#developing-plugins--modules)
   - [Requirements vs Optional Capabilities](#requirements-vs-optional-capabilities)
   - [Folder Structure Requirements](#folder-structure-requirements)
   - [Plugin Headers](#plugin-headers)
   - [Plugin Versioning & SQL Migrations](#plugin-versioning--sql-migrations)
   - [Outbound Email Delivery in Plugins](#outbound-email-delivery-in-plugins)
   - [Hook System (Actions & Filters)](#hook-system-actions--filters)
   - [Extensible Route Handlers & Views](#extensible-route-handlers--views)
   - [Dynamic Navigation Menus (Top-Level & Dropdowns)](#dynamic-navigation-menus)
   - [Home Dashboard Widgets](#home-dashboard-widgets)
   - [Inter-Plugin Exposed Services](#inter-plugin-exposed-services)
   - [Dynamic Permission & Role Loading](#dynamic-permission--role-loading)
   - [Database Isolation & SQL Prefixing](#database-isolation--sql-prefixing)
   - [Plugin Settings API](#plugin-settings-api)
   - [Task Scheduler API](#task-scheduler-api)
   - [Verbose Plugin Compatibility Inspector](#verbose-plugin-compatibility-inspector)
9. [Administrative Interfaces](#administrative-interfaces)
10. [Health Check API Endpoint](#health-check-api-endpoint)

---

## Architecture Overview

This framework cleanly separates **Core Platform Concerns** (Authentication, User Provisioning, Access Auditing, Plugin Dispatching, Task Scheduling, Outbound Email Delivery, Core Schema Migrations, Site Branding) from **Features** (which are encapsulated inside plugin modules).

Key components:
- **`PluginManager.php`**: The central orchestrator. Handles discovery, header parsing, plugin versioning and multi-version database migrations (`migratePlugin`), compatibility testing, dynamic permission/role provisioning, route matching, event hook dispatching, and automated cross-plugin hook/service call audit logging (`PLUGIN_CROSS_HOOK_CALL` and `PLUGIN_CROSS_SERVICE_CALL`).
- **`PluginDatabase.php`**: Secure database isolation wrapper ensuring plugins operate strictly inside safe table prefixes (`plug_{plugin_slug}_*`) and preventing unauthorized SQL mutations on core or sibling tables.
- **`Mailer.php`**: Framework outbound email delivery engine featuring socket-level SMTP transport, STARTTLS/SSL encryption, SMTP authentication (`AUTH LOGIN`), Envelope From / Return-Path bounce header support, fallback to native PHP `mail()`, and DB logging with SMTP Delivery Status Notification (DSN) tracking.
- **`Scheduler.php`**: Parallel background task scheduler with process spawning, concurrency locks, execution duration tracking, and stdout output capturing.
- **`Auth.php` & `AzureADSSO.php`**: OAuth2 Single Sign-On (SSO) integration with Azure Active Directory and dynamic RBAC with permission checks, role grants, direct permissions, and explicit denials. Also includes Microsoft Graph API capabilities for group management, member synchronization, and Teams chat messaging.
- **`admin-plugins.php`**: Plugin discovery, test activation compatibility linter, deactivation database purge confirmation, and enablement dashboard.
- **`admin-email.php`**: Outbound SMTP configuration panel, test email dispatch modal, and delivery audit log history with real-time status filtering (Sent/Failed/Pending) and SMTP DSN codes.
- **`admin-scheduler.php`**: Visual task overrides, frequency customization, on-demand task runner, stdout viewer, and execution logs.
- **`admin-nav.php`**: Main navigation menu reordering, custom label overrides, and visibility toggle controls.
- **`admin-users.php`**: Visual user directory with real-time live search filter (`#userSearchInput`), RBAC permission management, and `modal-xl` responsive privilege dialogs.
- **`admin-roles.php`**: Dedicated administration interface to create custom RBAC roles, edit role descriptions, enable/disable active role status (`is_active`), manage role permissions, and view member counts.
- **`admin-azure-groups.php`**: Microsoft Graph API Azure AD directory group listing and dynamic local role mapping.
- **`admin-logs.php`**: Action audit logs with dynamic search filters, action sorting, and CSV export.
- **`admin-diagnostics.php`**: Server diagnostics, core framework version verification, site name branding (`site_name`), site-wide broadcast announcement banners (`broadcast_banner`), and PHP environment checks.

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

## Framework Versioning, Changelog & Schema Upgrades

### Core Framework Version Tracking
The core Portal Framework tracks its installed release version via the `framework_version` setting stored in the `settings` database table (defaulting to `1.0.0` or current installed release version). The active version is visible under **System Diagnostics** (`admin-diagnostics.php`) and returned in the `/index.php?route=health_check` endpoint.

### Core Database Schema Upgrade Process
When upgrading the Portal Framework codebase across major or minor release versions:

1. **Schema Script Versioning**: Core schema additions or structural changes are recorded sequentially. For initial setup, `schema.sql` creates baseline framework tables (`users`, `roles`, `permissions`, `settings`, `audit_logs`, `active_plugins`, `scheduled_tasks`, `scheduled_tasks_logs`, `user_widget_preferences`, and `email_logs`).
2. **Incremental Database Upgrades**: For existing deployments, incremental SQL schema alter scripts located in core system update routines add missing columns and tables safely (using `IF NOT EXISTS` constructs or conditional `ALTER TABLE` operations) without data loss.
3. **Version Synchronization**: Upon applying core schema updates, the `framework_version` setting key is bumped to match the latest codebase release:
   ```sql
   INSERT INTO settings (setting_key, setting_value)
   VALUES ('framework_version', '1.1.0')
   ON DUPLICATE KEY UPDATE setting_value = '1.1.0';
   ```

### Framework Changelog & Release History

#### Version 1.1.0
- **Plugin Multi-Version SQL Migrations**: Added support in `PluginManager` for checking header `Version:`, tracking `installed_version` in `active_plugins`, and automatically executing sequential versioned migration scripts located in `/plugins/{slug}/sql/migrations/` (e.g. `1.1.0.sql`, `2.0.0.sql`) alongside the `plugin_migrate_{slug}` hook.
- **Outbound SMTP Email Delivery Engine (`Mailer.php`)**: Built native socket transport supporting Plain, STARTTLS, and SSL/TLS encryption, `AUTH LOGIN` authentication, Postfix Envelope From / Return-Path bounce header configuration (`email_envelope_from`), fallback to PHP native `mail()`, global helper function `send_email()`, and DB logging with SMTP DSN status code tracking (`email_logs`).
- **Outbound Email Administration Panel (`admin-email.php`)**: Implemented SMTP settings configuration, test email modal dispatch, and real-time outbound message audit trail with status filters (Sent, Failed, Pending) and DSN codes.
- **Azure AD SSO & Microsoft Graph API Enhancements**: Upgraded Graph API integration with automatic token refresh retry logic on HTTP 401 errors (`acquireFreshToken()`), `@odata.nextLink` pagination loops for directory groups (`getAllGroups`), group member retrieval (`getGroupMembers`), and Microsoft Teams chat creation (`createChat`) and Adaptive Card messaging (`sendAdaptiveCardToChat`).
- **Enhanced Role & Permission Control (`admin-roles.php`, `admin-users.php`)**: Created dedicated RBAC Role Management UI with role enable/disable toggle (`is_active`), user display card with styled role hover popups, and responsive `modal-xl` user privilege editing dialogs.

#### Version 1.0.0
- **Baseline Framework Release**: Core WordPress-style action/filter engine, Azure AD SSO login, RBAC permission engine, isolated database wrapper (`PluginDatabase.php`), parallel background task scheduler (`Scheduler.php`), customizable home dashboard widgets, navigation manager (`admin-nav.php`), action audit trail (`admin-logs.php`), and system diagnostics.

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
- `current_user()`: Returns the logged-in user profile array from session, or `null`.
- `e($string)`: Safely escapes strings for HTML output to prevent XSS.
- `get_azure_access_token()`: Returns the active OAuth access token by checking standard session locations (`$_SESSION['user']['access_token']`, `$_SESSION['access_token']`, `$_SESSION['azure_access_token']`, `$_SESSION['tokens']['access_token']`).
- `has_permission($permission_name)`: Returns `true` if current user possesses permission.
- `has_role($role_name)`: Returns `true` if current user possesses role.
- `send_email($to, $subject, $message, $plugin_slug = null, $custom_from = null, array $extra_headers = [])`: Global helper to dispatch outbound emails via `Mailer.php` with DB tracking, custom `From` override, and SMTP DSN response codes.
- `add_action($hook, $callback, $priority = 10)`: Bind callback to an action event.
- `add_filter($hook, $callback, $priority = 10)`: Bind callback to a filter hook.
- `register_route($route_name, $callback)`: Register a custom view route handler.
- `url_for($route_name)`: Helper returning formatted route URL (`index.php?route=$route_name`).
- `redirect($url)`: Performs instant HTTP location redirect.
- `csrf_field()`: Renders hidden HTML CSRF input field.
- `validate_csrf()` / `csrf_verify()`: Validates incoming POST CSRF token.
- `set_flash_message($type, $message)`: Sets a flash message to display on the next page load.
- `get_flash_messages()`: Retrieves and consumes pending flash messages from session.
- `get_setting($key, $default)`: Retrieves a core setting from the database.
- `set_setting($key, $value)`: Saves or updates a core setting in the database.
- `get_plugin_setting($plugin_slug, $key, $default)`: Gets a plugin-specific setting value with key prefix isolation (`plug_{plugin_slug}_{key}`).
- `set_plugin_setting($plugin_slug, $key, $value)`: Saves a plugin-specific setting value.
- `log_action($action, $details)`: Writes entry to central audit logs.

---

## Azure AD SSO & Microsoft Graph API Engine

The framework includes robust integration with Azure AD OAuth2 Single Sign-On and Microsoft Graph API (`AzureADSSO.php`).

### Key Capabilities:
- **User Provisioning**: Prefers `userInfo['oid']` (Azure AD Object ID UUID) over `sub` (pairwise token) for consistent user matching across applications.
- **Token Synchronization**: Synchronizes acquired access tokens across 4 standard session keys during login.
- **Automatic Token Refresh & Retry**: Handles HTTP 401 (`InvalidAuthenticationToken` / expired token) errors transparently by automatically renewing tokens via OAuth `refresh_token` (or falling back to app client credentials) and retrying requests seamlessly.
- **`getUserGroups($accessToken)`**: Retrieves the full list of groups assigned to the authenticated user during SSO login, with automatic `@odata.nextLink` multi-page pagination and automatic 401 token refresh retry.
- **`getAllGroups($accessToken = null)`**: Fetches all Azure AD Directory Groups with automatic `@odata.nextLink` multi-page pagination support.
- **`getGroupMembers($groupId, $accessToken = null)`**: Retrieves member objects for a specific Azure AD group, automatically sanitizing parameter order and falling back to client credential app tokens when delegated tokens expire.
- **`createChat($topic, $memberIds = [], $accessToken = null)`**: Creates a Microsoft Teams 1:1 or group chat conversation via Graph API (`POST /chats`). Includes automatic caller inclusion, member deduplication, non-UUID filtering, and verbose JSON logging.
- **`sendMessageToChat($chatId, $content, $accessToken = null)`**: Posts text or HTML messages to a Microsoft Teams chat.
- **`sendAdaptiveCardToChat($chatId, $cardContent, $accessToken = null)`**: Posts Microsoft Teams Adaptive Cards to chat conversations. Automatically extracts `chatId` if a chat response array containing `id` or `chat_id` is supplied.
- **`addMembersToChat($chatId, $memberIds, $accessToken = null)`**: Adds members to an existing Teams chat conversation.

---

## Outbound SMTP Email Handler & DSN Tracking Engine

The framework features a dedicated outbound email handling subsystem (`Mailer.php` and `admin-email.php`) supporting direct SMTP socket connections, RFC2822 header formatting, and delivery tracking.

### Key Capabilities:
- **Socket-Level SMTP Transport**: Connects directly to configured SMTP relays with support for Plain, STARTTLS, and SSL/TLS encryption, plus standard `AUTH LOGIN` authentication. Fallback to PHP native `mail()` is automatically triggered if direct socket connection fails.
- **Envelope From / Return-Path Bounce Handling**: Configurable Postfix Envelope From (`email_envelope_from`) passed as `MAIL FROM` during SMTP handshakes (and `-f` flag in native `mail()`), ensuring bounced messages route cleanly to dedicated bounce mailboxes.
- **Delivery Status Notification (DSN) Code Tracking**: Captures and records full SMTP response text and DSN status codes (e.g., `250 2.0.0`, `550 5.1.1`) inside the `email_logs` database table.
- **Outbound Email Audit Trail**: Logs all sent, failed, or pending emails with sender, recipient, subject, body content, originating plugin slug, and timestamp.
- **Admin Control Panel (`admin-email.php`)**: Provides an interface to configure global SMTP server host, port, encryption, and credentials, dispatch test emails, and inspect the real-time outbound delivery log history with status filters.

---

## Dashboard Widget Customizer Engine

The primary dashboard (`index.php`) features an interactive, persistent per-user widget customization system.

### Features:
- **Interactive UI**: Users can toggle widget visibility, reorder widgets, and resize column widths (`col-12`, `col-lg-8`, `col-lg-6`, `col-lg-4`) via a modal configuration dialog.
- **Pure PHP HTML Parser**: Extracting top-level `<div>` widgets outputted by plugin hooks is performed using a pure PHP tag-depth tracking parser—eliminating hard system dependencies on `DOMDocument` or `php-xml`.
- **System Default Layout**: Administrators can click "Save as System Default" to establish a baseline dashboard widget layout (`default_dashboard_layout` setting) for new users.

---

## Developing Plugins / Modules

Extensions in this framework are fully self-contained. The platform automatically discovers all plugins inside the `/plugins/` directory.

### Requirements vs Optional Capabilities

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
- **SQL Scripts & Migrations**: Providing installation/uninstall schemas (`/sql/install.sql`, `/sql/uninstall.sql`) and versioned migration files (`/sql/migrations/*.sql`).
- **Custom Routes**: Registering URL handlers using `register_route()`.
- **Navigation Items**: Inserting top-level or dropdown menu links using `theme_nav_links`.
- **Dashboard Widgets**: Rendering user-contextual cards on the home screen using `index_dashboard_widgets`.
- **Inter-Plugin Services**: Exposing API callbacks to sibling plugins using `registerService()`.

---

### Folder Structure Requirements

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

### Plugin Headers

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

### Plugin Versioning & SQL Migrations

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

### Outbound Email Delivery in Plugins

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
- **Audit Logging**: Every email dispatch generates a `OUTBOUND_EMAIL_SENT` audit trail log and records full SMTP DSN response codes for troubleshooting.

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

### Inter-Plugin Exposed Services

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

### Plugin Settings API

Plugins can store and retrieve isolated settings using standard helper functions:

```php
// Save setting 'api_key' for plugin 'my-plugin'
set_plugin_setting('my-plugin', 'api_key', 'SECRET_123');

// Get setting 'api_key'
$apiKey = get_plugin_setting('my-plugin', 'api_key', 'default_value');
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

## Administrative Interfaces

- **Modules & Plugins (`admin-plugins.php`)**: Plugin discovery, compatibility linter, deactivation modal (with choice to retain or purge tables).
- **Outbound Email Handler (`admin-email.php`)**: SMTP relay configuration, default From and Return-Path envelope settings, test email modal, and outbound log table with status filtering and DSN code tracking.
- **Task Scheduler (`admin-scheduler.php`)**: Custom schedule overrides, enable/disable switches, on-demand task runner with stdout viewer, execution logs, and runtime duration metrics.
- **Navigation Manager (`admin-nav.php`)**: Main menu link re-ordering, custom label overrides, and show/hide visibility toggles.
- **Users & RBAC (`admin-users.php`)**: User directory, real-time user search filter (`#userSearchInput`), role assignments, direct permission grants, and explicit permission denials.
- **Roles & Permissions (`admin-roles.php`)**: Create custom roles, enable/disable role statuses (`is_active`), configure assigned permissions, and delete custom roles.
- **Azure AD Directory Groups (`admin-azure-groups.php`)**: Graph API group listing with `@odata.nextLink` pagination and mapping to local roles.
- **Audit Trail Logs (`admin-logs.php`)**: Action audit logs with dynamic search, filters, action sorting, CSV export, and tracking of cross-plugin hook (`PLUGIN_CROSS_HOOK_CALL`) and service (`PLUGIN_CROSS_SERVICE_CALL`) calls.
- **System Diagnostics (`admin-diagnostics.php`)**: Server diagnostics, core framework version verification, site branding (`site_name`), site-wide broadcast announcement banners (`broadcast_banner`), and PHP environment checks.

---

## Health Check API Endpoint

The framework provides an automated JSON health monitoring endpoint accessible at:
```http
GET /index.php?route=health_check
```

### Sample JSON Output:
```json
{
    "status": "healthy",
    "timestamp": "2025-02-15T12:00:00+00:00",
    "php_version": "8.2.10",
    "site_name": "Framework Portal",
    "database": {
        "status": "connected",
        "error": null
    },
    "active_plugins_count": 2,
    "active_plugins": [
        "sample-manager",
        "oncall-manager"
    ],
    "registered_tasks_count": 2
}
```
