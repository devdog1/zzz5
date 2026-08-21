<?php
/**
 * Plugin Name: On-Call Schedule Manager
 * Description: Enterprise On-Call Rotation, Shift Trade Center, Manual Overrides, Metaswitch CommPortal & Zabbix Integration.
 * Version: 2.1
 * Author: DevDog
 * Permissions: view_schedule, manage_schedule, manage_trades, manage_departments, manage_telephony, manage_settings
 * Roles: manager:view_schedule,manage_schedule,manage_trades,manage_departments,manage_telephony,manage_settings; operator:view_schedule,manage_trades; viewer:view_schedule
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/../../');
}

// Load Plugin Models
require_once __DIR__ . '/models/oncall-models.php';

// Load Plugin Views
require_once __DIR__ . '/views/calendar-view.php';
require_once __DIR__ . '/views/trades-view.php';
require_once __DIR__ . '/views/overrides-view.php';
require_once __DIR__ . '/views/departments-view.php';
require_once __DIR__ . '/views/telephony-view.php';
require_once __DIR__ . '/views/generate-view.php';
require_once __DIR__ . '/views/settings-view.php';

// Load Background Tasks
require_once __DIR__ . '/tasks/commportal-sync-task.php';
require_once __DIR__ . '/tasks/zabbix-sync-task.php';
require_once __DIR__ . '/tasks/zabbix-group-assign-task.php';

/* =========================================================
 * ACTIVATION & DEACTIVATION HOOKS
 * ========================================================= */

add_action('plugin_activate_oncall-manager', 'oncall_plugin_install_tables');
function oncall_plugin_install_tables() {
    $install_sql_file = __DIR__ . '/sql/install.sql';
    if (file_exists($install_sql_file)) {
        $pdb = oncall_get_pdb();
        $db = get_db_connection();
        $sql = file_get_contents($install_sql_file);

        $sql = str_replace('{prefix}', $pdb->getPrefix(), $sql);
        $db->exec($sql);
    }
}

add_action('plugin_deactivate_oncall-manager', 'oncall_plugin_uninstall_tables');
function oncall_plugin_uninstall_tables() {
    $uninstall_sql_file = __DIR__ . '/sql/uninstall.sql';
    if (file_exists($uninstall_sql_file)) {
        $pdb = oncall_get_pdb();
        $db = get_db_connection();
        $sql = file_get_contents($uninstall_sql_file);

        $sql = str_replace('{prefix}', $pdb->getPrefix(), $sql);
        $db->exec($sql);
    }
}

/* =========================================================
 * NAVIGATION MENU LINKS
 * ========================================================= */

add_filter('theme_nav_links', function($nav) {
    if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) {
        return $nav;
    }

    $oncall_menu = [
        'label' => 'On-Call Schedule',
        'icon' => 'fa-solid fa-phone-volume',
        'route' => 'oncall_calendar',
        'children' => [
            ['label' => 'Rotation Calendar', 'icon' => 'fa-solid fa-calendar-days', 'route' => 'oncall_calendar'],
            ['label' => 'Shift Trade Center', 'icon' => 'fa-solid fa-handshake', 'route' => 'oncall_trades']
        ]
    ];

    if (has_permission('manage_schedule') || has_permission('oncall_manager_manage_schedule')) {
        $oncall_menu['children'][] = ['label' => 'Manual Overrides', 'icon' => 'fa-solid fa-calendar-minus', 'route' => 'oncall_overrides'];
        $oncall_menu['children'][] = ['label' => '365-Day Shift Generator', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'route' => 'oncall_generate'];
    }

    if (has_permission('manage_departments') || has_permission('oncall_manager_manage_departments')) {
        $oncall_menu['children'][] = ['label' => 'Department Management', 'icon' => 'fa-solid fa-building-user', 'route' => 'oncall_departments'];
    }

    if (has_permission('manage_telephony') || has_permission('oncall_manager_manage_telephony')) {
        $oncall_menu['children'][] = ['label' => 'CommPortal Telephony', 'icon' => 'fa-solid fa-headset', 'route' => 'oncall_telephony'];
    }

    if (has_permission('manage_settings') || has_permission('oncall_manager_manage_settings')) {
        $oncall_menu['children'][] = ['label' => 'Plugin Settings', 'icon' => 'fa-solid fa-gears', 'route' => 'oncall_settings'];
    }

    $nav[] = $oncall_menu;
    return $nav;
});

/* =========================================================
 * SCHEDULED TASKS REGISTRATION
 * ========================================================= */

add_action('init_scheduler', function($scheduler) {
    $scheduler->registerTask(
        'oncall_commportal_sync',
        'oncall_task_commportal_sync',
        60,
        'oncall-manager'
    );

    $scheduler->registerTask(
        'oncall_zabbix_sync',
        'oncall_task_zabbix_sync',
        3600,
        'oncall-manager'
    );

    $scheduler->registerTask(
        'oncall_zabbix_group_assign',
        'oncall_task_zabbix_group_assign',
        60,
        'oncall-manager'
    );
});

/* =========================================================
 * ROUTE HANDLERS
 * ========================================================= */

add_action('register_routes', function() {
    register_route('oncall_calendar', function() {
        if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) die('Access Denied');
        oncall_render_calendar_page();
    });

    register_route('oncall_trades', function() {
        if (!has_permission('manage_trades') && !has_permission('oncall_manager_manage_trades')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';
            $dept_id = (int)($_POST['department_id'] ?? 0);
            $current_user_id = $_SESSION['user_id'] ?? null;

            try {
                if ($action === 'propose_trade') {
                    $slot_id = (int)$_POST['offered_slot_id'];
                    oncall_propose_trade($dept_id, $slot_id, $current_user_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade proposal created successfully.'));
                } elseif ($action === 'accept_take') {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_accept_trade_take($trade_id, $current_user_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Accepted shift take request. Pending manager approval.'));
                } elseif ($action === 'proposer_agree') {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_proposer_agree_swap($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Agreed to swap. Pending manager approval.'));
                } elseif ($action === 'cancel_trade') {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_cancel_trade_request($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade request canceled.'));
                } elseif ($action === 'approve_trade' && oncall_can_manage_department($dept_id)) {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_manager_approve_trade($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade request approved and schedule updated.'));
                } elseif ($action === 'reject_trade' && oncall_can_manage_department($dept_id)) {
                    $trade_id = (int)$_POST['trade_id'];
                    oncall_manager_reject_trade($trade_id);
                    redirect(url_for('oncall_trades') . "&department_id={$dept_id}&msg=" . urlencode('Trade request rejected.'));
                }
            } catch (Exception $e) {
                redirect(url_for('oncall_trades') . "&department_id={$dept_id}&err=" . urlencode($e->getMessage()));
            }
        }

        oncall_render_trades_page();
    });

    register_route('oncall_overrides', function() {
        if (!has_permission('manage_schedule') && !has_permission('oncall_manager_manage_schedule')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'create_override') {
                $dept_id = (int)$_POST['department_id'];
                $user_id = (int)$_POST['user_id'];
                $start = $_POST['start_time'];
                $end = $_POST['end_time'];
                $desc = $_POST['description'];

                oncall_create_override($dept_id, $user_id, $start, $end, $desc);
                redirect(url_for('oncall_overrides') . '&msg=' . urlencode('Schedule override created successfully.'));
            } elseif ($action === 'delete_override') {
                $id = (int)$_POST['id'];
                oncall_delete_override($id);
                redirect(url_for('oncall_overrides') . '&msg=' . urlencode('Schedule override removed successfully.'));
            }
        }

        oncall_render_overrides_page();
    });

    register_route('oncall_departments', function() {
        if (!has_permission('manage_departments') && !has_permission('oncall_manager_manage_departments')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'create_department') {
                $name = $_POST['name'] ?? '';
                $mgr = !empty($_POST['manager_user_id']) ? (int)$_POST['manager_user_id'] : null;
                oncall_create_department($name, $mgr);
                redirect(url_for('oncall_departments') . '&msg=' . urlencode('Department created successfully.'));
            } elseif ($action === 'update_department') {
                $id = (int)$_POST['id'];
                $name = $_POST['name'] ?? '';
                $mgr = !empty($_POST['manager_user_id']) ? (int)$_POST['manager_user_id'] : null;
                $noc = !empty($_POST['noc_mode']) ? 1 : 0;
                oncall_update_department($id, $name, $mgr, $noc);

                $z_groups = [];
                if (!empty($_POST['zabbix_usrgrp_ids'])) {
                    $raw = explode(',', $_POST['zabbix_usrgrp_ids']);
                    foreach ($raw as $r) {
                        $trimmed = trim($r);
                        if (is_numeric($trimmed)) {
                            $z_groups[] = (int)$trimmed;
                        }
                    }
                }
                oncall_save_department_zabbix_groups($id, $z_groups);

                redirect(url_for('oncall_departments') . "&id={$id}&msg=" . urlencode('Department settings updated.'));
            } elseif ($action === 'delete_department') {
                $id = (int)$_POST['id'];
                oncall_delete_department($id);
                redirect(url_for('oncall_departments') . '&msg=' . urlencode('Department deleted.'));
            } elseif ($action === 'update_members') {
                $dept_id = (int)$_POST['department_id'];
                $u_ids = $_POST['user_ids'] ?? [];
                oncall_save_department_users($dept_id, $u_ids);
                redirect(url_for('oncall_departments') . "&id={$dept_id}&msg=" . urlencode('Department roster updated.'));
            }
        }

        oncall_render_departments_page();
    });

    register_route('oncall_telephony', function() {
        if (!has_permission('manage_telephony') && !has_permission('oncall_manager_manage_telephony')) die('Access Denied');

        $pdb = oncall_get_pdb();
        $tb_accounts = $pdb->getTableName('commportal_accounts');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'save_account') {
                $num = $_POST['account_number'] ?? '';
                $pass = $_POST['commportal_pass'] ?? '';
                $fwd = $_POST['forwarding_number'] ?? '';

                $pdb->query("
                    INSERT INTO {$tb_accounts} (account_number, commportal_pass, forwarding_number)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE commportal_pass = ?, forwarding_number = ?
                ", [$num, $pass, $fwd, $pass, $fwd]);

                redirect(url_for('oncall_telephony') . '&msg=' . urlencode('Telephony account saved.'));
            } elseif ($action === 'delete_account') {
                $id = (int)$_POST['id'];
                $pdb->query("DELETE FROM {$tb_accounts} WHERE id = ?", [$id]);
                redirect(url_for('oncall_telephony') . '&msg=' . urlencode('Telephony account removed.'));
            }
        }

        oncall_render_telephony_page();
    });

    register_route('oncall_generate', function() {
        if (!has_permission('manage_schedule') && !has_permission('oncall_manager_manage_schedule')) die('Access Denied');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'generate_schedule') {
                $dept_id = (int)$_POST['department_id'];
                $start_date = $_POST['start_date'];
                $user_ids = $_POST['user_ids'] ?? [];

                try {
                    oncall_generate_365_day_schedule($dept_id, $user_ids, $start_date);
                    redirect(url_for('oncall_calendar') . "&department_id={$dept_id}&msg=" . urlencode('365-day rotation schedule successfully generated.'));
                } catch (Exception $e) {
                    redirect(url_for('oncall_generate') . "&department_id={$dept_id}&err=" . urlencode($e->getMessage()));
                }
            }
        }

        oncall_render_generate_page();
    });

    register_route('oncall_settings', function() {
        if (!has_permission('manage_settings') && !has_permission('oncall_manager_manage_settings')) die('Access Denied');

        $pdb = oncall_get_pdb();
        $tb_noc = $pdb->getTableName('noc_business_hours');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_verify();
            $action = $_POST['action'] ?? '';

            if ($action === 'save_zabbix_settings') {
                $url = $_POST['zabbix_api_url'] ?? '';
                $token = $_POST['zabbix_api_token'] ?? '';
                $domain = $_POST['zabbix_sync_domain'] ?? '';

                oncall_set_setting('zabbix_api_url', $url);
                oncall_set_setting('zabbix_api_token', $token);
                oncall_set_setting('zabbix_sync_domain', $domain);

                redirect(url_for('oncall_settings') . '&msg=' . urlencode('Zabbix API integration settings saved.'));
            } elseif ($action === 'save_noc_hours') {
                $hours_input = $_POST['noc_hours'] ?? [];
                foreach ($hours_input as $dow => $times) {
                    $start = !empty($times['start']) ? $times['start'] . ':00' : '08:00:00';
                    $end = !empty($times['end']) ? $times['end'] . ':00' : '17:00:00';

                    $pdb->query("
                        INSERT INTO {$tb_noc} (day_of_week, start_time, end_time)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE start_time = ?, end_time = ?
                    ", [(int)$dow, $start, $end, $start, $end]);
                }

                redirect(url_for('oncall_settings') . '&msg=' . urlencode('NOC business hours overlay updated.'));
            }
        }

        oncall_render_settings_page();
    });

    register_route('oncall_api_events', function() {
        header('Content-Type: application/json');

        if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) {
            echo json_encode([]);
            exit;
        }

        $department_id = $_GET['department_id'] ?? null;
        if (!$department_id) {
            echo json_encode([]);
            exit;
        }

        $start_str = $_GET['start'] ?? date('Y-m-d H:i:s', strtotime('-1 month'));
        $end_str = $_GET['end'] ?? date('Y-m-d H:i:s', strtotime('+1 month'));

        $segments = oncall_get_final_schedule_for_department($department_id, $start_str, $end_str);

        $events = [];
        foreach ($segments as $seg) {
            $events[] = [
                'id' => $seg['id'],
                'title' => $seg['display_name'] . ($seg['is_override'] ? ' (' . $seg['description'] . ')' : ''),
                'start' => date('c', $seg['start']),
                'end' => date('c', $seg['end']),
                'color' => $seg['is_override'] ? '#dc3545' : '#0d6efd',
                'extendedProps' => [
                    'description' => $seg['description']
                ]
            ];
        }

        echo json_encode($events);
        exit;
    });
});

/* =========================================================
 * DASHBOARD HOME WIDGET
 * ========================================================= */

add_action('index_dashboard_widgets', function($user_context) {
    if (!has_permission('view_schedule') && !has_permission('oncall_manager_view_schedule')) {
        return;
    }

    $current_user_id = $user_context['user_id'] ?? null;
    $departments = oncall_get_all_departments();
    $upcoming_shifts = $current_user_id ? oncall_get_upcoming_user_shifts($current_user_id, 3) : [];
    ?>
    <div class="col-md-6 mb-4">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="card-title mb-0 text-primary">
                    <i class="bi bi-telephone-inbound me-2"></i>Current On-Call Contacts
                </h5>
                <a href="<?php echo url_for('oncall_calendar'); ?>" class="btn btn-sm btn-outline-primary">View Full Calendar</a>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush mb-3">
                    <?php if (empty($departments)): ?>
                        <div class="list-group-item text-muted">No departments configured.</div>
                    <?php else: ?>
                        <?php foreach ($departments as $dept): ?>
                            <?php
                            $now_oncall = oncall_get_current_on_call($dept['id'], time());
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <strong><?php echo htmlspecialchars($dept['name']); ?></strong>
                                    <?php if (!empty($dept['noc_mode'])): ?>
                                        <span class="badge bg-warning text-dark ms-1">NOC Active</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ($now_oncall): ?>
                                        <span class="badge bg-success p-2">
                                            <i class="bi bi-person-check-fill me-1"></i>
                                            <?php echo htmlspecialchars($now_oncall['display_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary p-2">Unassigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($current_user_id && !empty($upcoming_shifts)): ?>
                    <div class="border-top pt-3">
                        <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-calendar-check me-1"></i>Your Next On-Call Shifts:</h6>
                        <ul class="list-unstyled mb-0 small">
                            <?php foreach ($upcoming_shifts as $shift): ?>
                                <li class="mb-1 text-muted">
                                    <i class="bi bi-clock me-1 text-primary"></i>
                                    <strong><?php echo htmlspecialchars($shift['department_name']); ?>:</strong>
                                    <?php echo date('M d, H:i', strtotime($shift['start_time'])); ?> &rarr; <?php echo date('M d, H:i', strtotime($shift['end_time'])); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
});
