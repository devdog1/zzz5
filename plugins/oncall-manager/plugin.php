<?php
/*
Plugin Name: On-Call Schedule Manager
Description: Complete dynamic rotation calendars, manual overrides overrides, shift trades center, metaswitch unconditional call forwarding, and automated zabbix synchronization.
Version: 1.0.0
Author: On-Call Developers
Permissions: view_schedules, manage_schedules, manage_telephony
*/

// Prevent direct access
if (!class_exists('PluginManager')) {
    exit;
}

// Load underlying models / operations
require_once __DIR__ . '/oncall-models.php';

// 1. Register navigation menu items dynamically with dynamic permission constraints
PluginManager::getInstance()->addFilter('theme_nav_links', function ($links) {
    $links[] = [
        'route' => 'oncall_calendar',
        'label' => 'On-Call Calendar',
        'icon'  => 'fa-solid fa-calendar-days',
        'permission' => 'oncall_manager_view_schedules'
    ];
    $links[] = [
        'route' => 'oncall_trades',
        'label' => 'Shift Trades',
        'icon'  => 'fa-solid fa-right-left',
        'permission' => 'oncall_manager_view_schedules'
    ];
    $links[] = [
        'route' => 'oncall_overrides',
        'label' => 'Overrides List',
        'icon'  => 'fa-solid fa-clock-rotate-left',
        'permission' => 'oncall_manager_view_schedules'
    ];
    $links[] = [
        'route' => 'oncall_departments',
        'label' => 'Manage Depts',
        'icon'  => 'fa-solid fa-sitemap',
        'permission' => 'oncall_manager_manage_schedules'
    ];
    $links[] = [
        'route' => 'oncall_telephony',
        'label' => 'Telephony Forwarding',
        'icon'  => 'fa-solid fa-phone-volume',
        'permission' => 'oncall_manager_manage_telephony'
    ];
    return $links;
});

// 2. Register home screen active coverage widgets
PluginManager::getInstance()->addAction('index_dashboard_widgets', function ($userContext) {
    $now = time();
    $departments = oncall_get_all_departments();

    if (empty($departments)) {
        return;
    }

    foreach ($departments as $dept) {
        $current = oncall_get_current_on_call($dept['id'], $now);
        $is_override = $current && $current['is_override'];
        $border_class = $current ? ($is_override ? 'border-warning' : 'border-success') : 'border-danger';
        ?>
        <div class="col-md-6 col-lg-4 mb-3">
            <div class="card shadow-sm border-start border-5 <?= $border_class ?> text-start">
                <div class="card-body">
                    <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-primary"><?= htmlspecialchars($dept['name']) ?></h6>
                        <?php if ($current): ?>
                            <?php if ($is_override): ?>
                                <span class="badge bg-warning text-dark small">Override Active</span>
                            <?php else: ?>
                                <span class="badge bg-success small">Active</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-danger small">No Coverage</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($current): ?>
                        <p class="small mb-1"><strong>On-Call:</strong> <?= htmlspecialchars($current['display_name'] ?? $current['username']) ?></p>
                        <p class="text-muted small fs-7 mb-0"><i class="fa-solid fa-clock me-1"></i> Ends: <?= date('M d, H:i', $current['end']) ?></p>
                    <?php else: ?>
                        <p class="small text-danger mb-0"><i class="fa-solid fa-triangle-exclamation me-1"></i> No active rotations found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
});

// 3. Register background Cron Task syncs (Zabbix sync and Telephone Forward sync running once per hour)
require_once __DIR__ . '/../../Scheduler.php';

// Zabbix Group sync callback task
Scheduler::getInstance()->registerTask(
    'zabbix_roster_sync',
    'oncall_sync_zabbix_background',
    3600, // hourly
    'oncall-manager'
);

// Telephony forward sync callback task
Scheduler::getInstance()->registerTask(
    'commportal_telephony_sync',
    'oncall_sync_commportal_background',
    3600, // hourly
    'oncall-manager'
);

// 4. Register Activation Event Hook (creates tables and seeds defaults)
PluginManager::getInstance()->addAction('activate_plugin_oncall-manager', function () {
    $pdb = new PluginDatabase('oncall-manager');

    // 1. Create Departments table
    $pdb->createTable('departments', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        manager_user_id INT DEFAULT NULL,
        noc_mode TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ");

    // 2. Create Department Users table
    $pdb->createTable('department_users', "
        department_id INT NOT NULL,
        user_id INT NOT NULL,
        PRIMARY KEY (department_id, user_id)
    ");

    // 3. Create Schedule Slots table
    $pdb->createTable('schedule_slots', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        user_id INT NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dept_time (department_id, start_time, end_time)
    ");

    // 4. Create Overrides table
    $pdb->createTable('overrides', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        user_id INT NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_override_dept_time (department_id, start_time, end_time)
    ");

    // 5. Create Trade Requests table
    $pdb->createTable('trade_requests', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        proposing_user_id INT NOT NULL,
        accepting_user_id INT DEFAULT NULL,
        offered_slot_id INT NOT NULL,
        counter_slot_id INT DEFAULT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ");

    // 6. Create NOC Business Hours table
    $pdb->createTable('noc_business_hours', "
        day_of_week INT NOT NULL PRIMARY KEY,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL
    ");

    // Seed Business Hours
    $db = get_db_connection();
    $tb_noc = $pdb->getTableName('noc_business_hours');
    for ($i = 1; $i <= 7; $i++) {
        $db->exec("INSERT IGNORE INTO {$tb_noc} (day_of_week, start_time, end_time) VALUES ({$i}, '08:00:00', '18:00:00')");
    }

    // 7. Create Department Zabbix Groups table
    $pdb->createTable('department_zabbix_groups', "
        department_id INT NOT NULL,
        zabbix_usrgrp_id BIGINT NOT NULL,
        last_oncall_userid BIGINT DEFAULT NULL,
        PRIMARY KEY (department_id, zabbix_usrgrp_id)
    ");

    // 8. Create CommPortal accounts table
    $pdb->createTable('commportal_accounts', "
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        phone_number VARCHAR(50) NOT NULL,
        password VARCHAR(100) NOT NULL,
        ext VARCHAR(20) DEFAULT NULL,
        last_forwarded_phone VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ");

    // Create a mock Zabbix users table for test synchronizations if not exists
    $db->exec("CREATE DATABASE IF NOT EXISTS zabbix;");
    $db->exec("USE zabbix;");
    $db->exec("CREATE TABLE IF NOT EXISTS users (userid BIGINT PRIMARY KEY, username VARCHAR(100), name VARCHAR(100), surname VARCHAR(100));");
    $db->exec("CREATE TABLE IF NOT EXISTS media (mediaid BIGINT AUTO_INCREMENT PRIMARY KEY, userid BIGINT, mediatypeid BIGINT, sendto VARCHAR(100));");
    $db->exec("INSERT IGNORE INTO users VALUES (1, 'alice', 'Alice', 'Smith'), (2, 'bob', 'Bob', 'Jones');");
    $db->exec("INSERT IGNORE INTO media (userid, mediatypeid, sendto) VALUES (1, 4, '+1-555-0101'), (2, 4, '+1-555-0102');");

    // Switch back to framework database
    $db->exec("USE base_framework;");

    log_action('ON_CALL_MANAGER_ACTIVATION_SUCCESS', []);
});

// 5. Register Deactivation Hook (Drops dynamic tables safely)
PluginManager::getInstance()->addAction('deactivate_plugin_oncall-manager', function () {
    $pdb = new PluginDatabase('oncall-manager');
    $pdb->dropTable('commportal_accounts');
    $pdb->dropTable('department_zabbix_groups');
    $pdb->dropTable('noc_business_hours');
    $pdb->dropTable('trade_requests');
    $pdb->dropTable('overrides');
    $pdb->dropTable('schedule_slots');
    $pdb->dropTable('department_users');
    $pdb->dropTable('departments');

    log_action('ON_CALL_MANAGER_DEACTIVATION_SUCCESS', []);
});

// 6. Register Views and Page Routes (Adapt original zzz4 Views)
require_once __DIR__ . '/oncall-views.php';
