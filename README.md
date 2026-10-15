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
For complete release history, detailed feature additions, and version changes, see [CHANGELOG.md](CHANGELOG.md).

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

For full plugin development guidelines, mandatory header requirements, optional capabilities, folder architecture conventions, database isolation wrappers, multi-version SQL migrations, and API reference, please refer to the dedicated guide:

👉 **[PLUGIN_DEVELOPMENT.md](PLUGIN_DEVELOPMENT.md)**

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
