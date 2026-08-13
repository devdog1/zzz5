# Portal Framework

An elegant, extensible, enterprise-grade PHP/MySQL modular portal framework. Developed with an isolated, WordPress-style action/filter hook engine, Azure AD Single Sign-On, dynamic Role-Based Access Control (RBAC), database settings, and comprehensive audit trails.

---

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Installation & Setup](#installation--setup)
3. [Core Global Variables Reference](#core-global-variables-reference)
4. [Developing Plugins / Modules](#developing-plugins--modules)
   - [Directory Structure](#directory-structure)
   - [Plugin Headers](#plugin-headers)
   - [Hook System (Actions & Filters)](#hook-system-actions--filters)
   - [Extensible Route Handlers](#extensible-route-handlers)
   - [Dynamic Home Screen Widgets](#dynamic-home-screen-widgets)
   - [Inter-Plugin Exposed Services](#inter-plugin-exposed-services)
   - [Plugin Dynamic Permission Loading](#plugin-dynamic-permission-loading)
   - [Database Isolation & Prefixing](#database-isolation--prefixing)
   - [Task Scheduler API](#task-scheduler-api)
   - [Permission and Role Checks](#permission-and-role-checks)
5. [Sample Plugin Walkthrough](#sample-plugin-walkthrough)
6. [Administrative Interfaces](#administrative-interfaces)

---

## Architecture Overview

This framework separates **Core Platform Concerns** (Authentication, User Provisioning, Access Auditing, Plugin Dispatching) from **Features** (which are fully encapsulated inside modules).

Key components:
- **`PluginManager.php`**: The orchestrator. Handles discovery, metadata extraction, plugin activation lifecycle, custom route matching, and event dispatching.
- **`PluginDatabase.php`**: Exposes secure SQL isolation wrappers ensuring plugins operate strictly inside safe prefixes (`plug_{slug}_`) to prevent collision or corruption of other modules.
- **`Scheduler.php`**: Custom task scheduler containing standard error-shield try-catch sandboxes so individual plugin cron failures do not disturb baseline site operation.
- **`Auth.php` & `AzureADSSO.php`**: Secure OAuth2 Single Sign-On (SSO) integration with Azure Active Directory. Includes standard fallbacks for local developer environments.
- **`admin-users.php`**: Built-in visual interface to manage users, assign roles, grant specific permissions, and block/deny specific user rights.

---

## Installation & Setup

1. **Database Schema Setup**
   Import the `schema.sql` file into your MySQL/MariaDB database instance:
   ```bash
   mysql -u your_user -p base_framework < schema.sql
   ```

2. **Configuration File**
   Modify `config.php` to define your DB credentials and your Azure Client IDs:
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

3. **Verify Core Routing**
   Deploy on any standard web-server (Apache, Nginx, or PHP CLI built-in server).

---

## Core Global Variables Reference

The following global components and variables are loaded and made available to plugins at runtime:

- **`$pluginManager`**: Global reference to the singleton `PluginManager` instance. Used to bind hooks, register services, and define custom pages.
- **`$auth`**: Global reference to the `Auth` instance. Used to check user session variables.
- **`$_SESSION['user_id']`**: Contains the integer Primary Key ID of the currently logged-in user session.
- **`$_SESSION['user']`**: Array containing authenticated user details (`azure_oid`, `email`, `name`, `groups`).
- **`$_SESSION['roles']`**: Array containing mapped RBAC active roles.
- **`$_SESSION['permissions']`**: Array containing active granted permissions.

---

## Developing Plugins / Modules

Creating an extension is simple. The platform will automatically discover files located inside the `plugins/` subdirectory.

### Directory Structure
Each plugin must live in its own sub-folder inside the `/plugins` directory:
```text
/plugins
  /my-awesome-module
    plugin.php       <-- Core entry file
    /css
    /js
```

### Plugin Headers
To register your plugin, place a comment block containing metadata at the top of your `plugin.php` file:
```php
<?php
/*
Plugin Name: My Awesome Module
Description: Registers custom layout segments and matches custom pages.
Version: 1.0.0
Author: John Doe
Permissions: manage_module_options, view_module_stats
*/
```

---

### Hook System (Actions & Filters)

The hook system allows plugins to subscribe to core lifecycle events or alter layout variables.

#### 1. Filters (Modify data arrays or strings)
Filters accept a variable, alter it, and return it.

* **`theme_nav_links`**: Insert custom menu items to the top header navigation bar.
  ```php
  PluginManager::getInstance()->addFilter('theme_nav_links', function ($links) {
      $links[] = [
          'route' => 'my_custom_view',
          'label' => 'My Feature Page',
          'icon'  => 'fa-solid fa-star',
          'permission' => 'my_awesome_module_view_module_stats' // Dynamic prefixed permission!
      ];
      return $links;
  });
  ```

#### 2. Actions (Trigger output or side-effects)
Actions are run at specific points to inject visual tags or run background tasks.

* **`theme_head`**: Inject custom stylesheets, meta tags, or CDN script resources.
  ```php
  PluginManager::getInstance()->addAction('theme_head', function() {
      echo '<link rel="stylesheet" href="plugins/my-awesome-module/css/style.css">';
  });
  ```

---

### Extensible Route Handlers

Match URLs dynamically and delegate drawing the page output cleanly to your plugin. Simply register custom slug routes:

```php
PluginManager::getInstance()->registerRoute('my_custom_view', function() {
    if (!has_permission('view_dashboard')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    ?>
    <div class="card p-4">
        <h3>Welcome to My Custom Extensible Route!</h3>
        <p>This layout renders directly within the core themed wrapper.</p>
    </div>
    <?php
});
```

To visit this view, load `index.php?route=my_custom_view` in your browser.

---

### Dynamic Home Screen Widgets

Plugins can render highly stylized, dynamic home screen dashboard widget cards. The framework automatically passes a current authenticated user's metadata context array to the hook:

```php
PluginManager::getInstance()->addAction('index_dashboard_widgets', function($userContext) {
    // Renders per-user customized contextual statistics or welcomes
    ?>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-5 border-primary">
            <div class="card-body">
                <h6 class="fw-bold">Welcome back, <?= htmlspecialchars($userContext['display_name']) ?>!</h6>
                <p class="small text-muted mb-0">Your privilege level: <?= implode(', ', $userContext['roles']) ?></p>
            </div>
        </div>
    </div>
    <?php
});
```

---

### Inter-Plugin Exposed Services

Plugins can expose internal helper functions or processed data models to sibling modules dynamically. The framework securely matches call contexts, ensuring that **all inter-plugin service calls run safely inside the active user session context**.

* **Register a Service Capability (from Plugin A)**:
  ```php
  PluginManager::getInstance()->registerService(
      'fetch_sales_report', // Service function key identifier
      function($month) {
          // Callback execution safely sandboxed
          return ['month' => $month, 'sales' => 14000];
      },
      'plugin-a' // Plugin slug
  );
  ```

* **Call a Sibling Plugin Service (from Plugin B)**:
  ```php
  try {
      $data = PluginManager::getInstance()->callService('fetch_sales_report', 'October');
      echo "October sales: " . $data['sales'];
  } catch (Exception $e) {
      // Handles cases where the sibling plugin is inactive or throws exceptions
      echo "Failed to invoke service: " . $e->getMessage();
  }
  ```

---

### Plugin Dynamic Permission Loading

This framework manages dynamic permission provisioning. When a plugin is enabled / activated, any permissions listed under the `Permissions:` metadata key inside the header comments are registered dynamically in the system.

- **Collision Protection**: Plugins cannot share or conflict with an existing permission name.
- **Auto-Prefixing**: System automatically prefixes the permission with the plugin's slug name (e.g. `Permissions: manage_options` becomes `my_plugin_manage_options` in the system database).
- **Graceful Cleanup**: When deactivating a plugin, its associated permissions are safely uninstalled from the `permissions` table, cascading cleanups to user mappings.

---

### Database Isolation & Prefixing

To ensure plugins can use the Database for their specific needs without colliding with or breaking the core, the framework exposes `PluginDatabase.php`.

Each plugin is sandboxed into tables starting with `plug_{plugin_slug}_`.

```php
require_once __DIR__ . '/../../PluginDatabase.php';

// Initialize the isolated Database Wrapper for your plugin
$pdb = new PluginDatabase('my-awesome-module');

// 1. Safe dynamic table creation (creates plug_my_awesome_module_records table)
$pdb->createTable('records', "
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
");

// 2. Querying inside your isolated schema
$tableName = $pdb->getTableName('records');
$pdb->query("INSERT INTO {$tableName} (title) VALUES (?)", ['Awesome Item']);

$results = $pdb->query("SELECT * FROM {$tableName}")->fetchAll();
```

---

### Task Scheduler API

The framework contains a resilient Task Scheduler. Plugins can register background cron scripts that execute at custom intervals. The runner is heavily sandboxed so exceptions thrown in a plugin task will not affect sibling scripts.

```php
require_once __DIR__ . '/../../Scheduler.php';

// Register background cron callback (e.g. running once every 3600 seconds)
Scheduler::getInstance()->registerTask(
    'sync_external_feed', // Task identifier key
    'my_plugin_sync_callback', // Callable
    3600, // Interval in seconds
    'my-awesome-module' // Plugin slug
);

function my_plugin_sync_callback() {
    // Perform isolated long running script safely
    log_action('CRON_SYNC_TRIGGERED', ['plugin' => 'my-awesome-module']);
}
```

---

### Permission and Role Checks

Protect sensitive views, endpoints, or forms using the RBAC Permission system:

* **Permission Check**:
  ```php
  if (has_permission('manage_settings')) {
      // Allow execution
  }
  ```

---

## Sample Plugin Walkthrough

Below is a complete, working example of a custom plugin that extends navigation, defines a dynamic view page, and stores custom options:

```php
<?php
/*
Plugin Name: Sample Manager
Description: A sample plugin showing how to register custom settings, custom route, navigation tab, and hooks inside the Base Framework.
Version: 1.1.0
Author: Framework Developers
*/

if (!class_exists('PluginManager')) {
    exit;
}

// 1. Hook into navigation filter
PluginManager::getInstance()->addFilter('theme_nav_links', function ($links) {
    $links[] = [
        'route' => 'sample_manager_dashboard',
        'label' => 'Sample Manager',
        'icon'  => 'fa-solid fa-wand-magic-sparkles',
        'permission' => 'view_dashboard'
    ];
    return $links;
});

// 2. Register callback route handler
PluginManager::getInstance()->registerRoute('sample_manager_dashboard', function () {
    if (!has_permission('view_dashboard')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $msg = '';
    if (isset($_POST['save_sample_settings'])) {
        $sample_token = trim($_POST['sample_api_token'] ?? '');
        set_setting('sample_manager_api_token', $sample_token);
        log_action('SAMPLE_MANAGER_SETTINGS_SAVE', ['sample_api_token' => '***']);
        $msg = "Settings updated!";
    }

    $currentToken = get_setting('sample_manager_api_token', 'default_mock_api_token');
    ?>
    <div class="row">
        <div class="col-md-12">
            <h2>Sample Manager Module</h2>
            <p>Demonstrates setting storage and custom navigation routes.</p>
        </div>
    </div>
    <?php
});
```

---

## Administrative Interfaces

The Portal framework provides pre-built administration panels for platform maintainers:

### 1. Core Module Lifecycles
Navigate to the main dashboard (`index.php`) to view discovered plugins. Admin users can activate and deactivate individual plugins instantly.

### 2. Sandboxed Cron States
Admin users can review background script run times, statuses, next execution targets, or failures directly from the dashboard view.

### 3. Users and RBAC Directory (`admin-users.php`)
Manage roles (`admin`, `manager`, `user`), manually assign direct exceptions (granting extra privileges), or block users from executing core features using denied permission overrides.
