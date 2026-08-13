# Portal Framework

An elegant, extensible, enterprise-grade PHP/MySQL modular portal framework. Developed with an isolated, WordPress-style action/filter hook engine, Azure AD Single Sign-On, dynamic Role-Based Access Control (RBAC), database settings, and comprehensive audit trails.

---

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Installation & Setup](#installation--setup)
3. [Developing Plugins / Modules](#developing-plugins--modules)
   - [Directory Structure](#directory-structure)
   - [Plugin Headers](#plugin-headers)
   - [Hook System (Actions & Filters)](#hook-system-actions--filters)
   - [Extensible Route Handlers](#extensible-route-handlers)
   - [Database and Global Settings API](#database-and-global-settings-api)
   - [Permission and Role Checks](#permission-and-role-checks)
4. [Sample Plugin Walkthrough](#sample-plugin-walkthrough)
5. [Administrative Interfaces](#administrative-interfaces)

---

## Architecture Overview

This framework separates **Core Platform Concerns** (Authentication, User Provisioning, Access Auditing, Plugin Dispatching) from **Features** (which are fully encapsulated inside modules).

Key components:
- **`PluginManager.php`**: The orchestrator. Handles discovery, metadata extraction, plugin activation lifecycle, custom route matching, and event dispatching.
- **`Auth.php` & `AzureADSSO.php`**: Secure OAuth2 Single Sign-On (SSO) integration with Azure Active Directory. Includes standard fallbacks for local developer environments.
- **`admin-users.php`**: Built-in visual interface to manage users, assign roles, grant specific permissions, and block/deny specific user rights.
- **Hook Registry (`theme_nav_links`, `theme_head`, `theme_footer`)**: Places where plugins hook in to enrich layout nodes, CSS styles, footer analytics scripts, and main header navigation links dynamically.

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
          'permission' => 'view_dashboard' // Required permission to display
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

* **`theme_footer`**: Inject JS execution blocks or performance tracker widgets.
  ```php
  PluginManager::getInstance()->addAction('theme_footer', function() {
      echo '<script>console.log("My Awesome Module loaded successfully!");</script>';
  });
  ```

---

### Extensible Route Handlers

Match URLs dynamically and delegate drawing the page output cleanly to your plugin. Simply register custom slug routes:

```php
PluginManager::getInstance()->registerRoute('my_custom_view', function() {
    // Check permission safeguards
    if (!has_permission('view_dashboard')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    // Output raw or themed content
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

### Database and Global Settings API

Store configuration values without needing separate migrations. The global key-value store is built-in:

* **Retrieve Setting**:
  ```php
  $api_key = get_setting('my_plugin_api_key', 'default_mock_value');
  ```

* **Write / Update Setting**:
  ```php
  set_setting('my_plugin_api_key', 'new_secure_token');
  ```

* **Audit Logs**:
  Ensure changes and sensitive operations are logged securely:
  ```php
  log_action('MY_PLUGIN_UPDATE', ['updated_field' => 'api_key', 'status' => 'success']);
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

* **Role Check**:
  ```php
  if (has_role('admin')) {
      // Allow access
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

// Prevent direct execution outside the framework context
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
    <!-- Add forms and modules here... -->
    <?php
});
```

---

## Administrative Interfaces

The Portal framework provides pre-built administration panels for platform maintainers:

### 1. Core Module Lifecycles
Navigate to the main dashboard (`index.php`) to view discovered plugins. Admin users can activate and deactivate individual plugins instantly. This executes any specific background activation actions.

### 2. Users and RBAC Directory (`admin-users.php`)
Manage roles (`admin`, `manager`, `user`), manually assign direct exceptions (granting extra privileges), or block users from executing core features using denied permission overrides.
