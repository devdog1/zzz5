# Framework Changelog & Release History

All notable changes, version releases, database schema upgrades, and new feature additions to the Portal Framework will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.0] - 2025-02-15

### Added
- **Inter-Plugin Dependency Management (`Requires Plugins:`)**: Added support in `PluginManager.php` for parsing `Requires Plugins:` header metadata. Verification checks in `inspectPlugin()` and `activatePlugin()` enforce that required dependent plugins are installed and active before a module can be activated.
- **Hook Unbinding API (`remove_action` / `remove_filter`)**: Added `removeAction()` and `removeFilter()` methods in `PluginManager.php` and corresponding global wrapper functions in `functions.php` to allow plugins or core features to unbind previously registered callbacks.
- **Plugin Header Requirements & Compatibility Engine**: Updated `PluginManager.php` to parse `Requires PHP:` and `Requires Core:` metadata headers in plugins. Added validation checks in both the compatibility inspector (`inspectPlugin`) and activation handler (`activatePlugin`) to block incompatible module activations.
- **Automated Schema Activation & Purge Uninstallation**: Enhanced `PluginManager.php` to execute `sql/install.sql` automatically during plugin activation, and execute `sql/uninstall.sql` during deactivation when table purging is confirmed by an administrator.
- **Plugin Multi-Version SQL Migrations**: Added support in `PluginManager` for checking header `Version:`, tracking `installed_version` in `active_plugins`, and automatically executing sequential versioned migration scripts located in `/plugins/{slug}/sql/migrations/` (e.g. `1.1.0.sql`, `2.0.0.sql`) alongside the `plugin_migrate_{slug}` hook.
- **Flexible Nested Navigation Menu Registration**: Updated `header.php` so plugins can attach nested sub-menu items directly using a `parent` key (or `parent_label`/`parent_icon` / array format). If the specified parent main nav item exists, the sub-menu item is appended; if the parent menu item does not exist, it is created dynamically without requiring the plugin to register a dummy parent item.
- **Outbound SMTP Email Delivery Engine (`Mailer.php`)**: Built native socket transport supporting Plain, STARTTLS, and SSL/TLS encryption, `AUTH LOGIN` authentication, Postfix Envelope From / Return-Path bounce header configuration (`email_envelope_from`), fallback to PHP native `mail()`, global helper function `send_email()`, and DB logging with SMTP DSN status code tracking (`email_logs`).
- **Outbound Email Administration Panel (`admin-email.php`)**: Implemented SMTP settings configuration, test email modal dispatch, and real-time outbound message audit trail with status filters (Sent, Failed, Pending) and DSN codes.
- **Azure AD SSO & Microsoft Graph API Enhancements**: Upgraded Graph API integration with automatic token refresh retry logic on HTTP 401 errors (`acquireFreshToken()`), `@odata.nextLink` pagination loops for directory groups (`getAllGroups`), group member retrieval (`getGroupMembers`), and Microsoft Teams chat creation (`createChat`) and Adaptive Card messaging (`sendAdaptiveCardToChat`).
- **Enhanced Role & Permission Control (`admin-roles.php`, `admin-users.php`)**: Created dedicated RBAC Role Management UI with role enable/disable toggle (`is_active`), user display card with styled role hover popups, and responsive `modal-xl` user privilege editing dialogs.

---

## [1.0.0] - 2025-01-10

### Added
- **Baseline Framework Release**: Core WordPress-style action/filter hook engine (`PluginManager.php`).
- **Azure AD SSO Integration**: OAuth2 Single Sign-On and user auto-provisioning via Azure AD (`AzureADSSO.php`, `Auth.php`).
- **Dynamic RBAC Permissions Engine**: Granular permissions, role assignments, direct grants, and explicit denials.
- **Database Isolation Wrapper**: Isolated plugin schema execution using prefix `plug_{plugin_slug}_*` (`PluginDatabase.php`).
- **Parallel Background Task Scheduler**: Process-spawning task scheduler with concurrency locks, microtime execution tracking, and stdout capture (`Scheduler.php`, `admin-scheduler.php`).
- **Customizable Dashboard Widgets**: Interactive per-user widget reordering, resizing, and visibility customization parser on home screen (`index.php`).
- **Administrative Control Panels**: Navigation Manager (`admin-nav.php`), User & RBAC Management (`admin-users.php`), Action Audit Logs (`admin-logs.php`), Modules & Plugins Manager (`admin-plugins.php`), and System Diagnostics (`admin-diagnostics.php`).
