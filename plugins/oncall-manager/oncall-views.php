<?php
// oncall-views.php - Front-End UI grids, calendars, forms, trades, and complete 365-day custom shift rotation generator
require_once __DIR__ . '/oncall-models.php';

$pm = PluginManager::getInstance();

/* =========================================================
 * VIEW 1: CALENDAR VIEW (oncall_calendar)
 * ========================================================= */
$pm->registerRoute('oncall_calendar', function () {
    if (!has_permission('oncall_manager_view_schedules')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $departments = oncall_get_all_departments();
    $current_dept_id = (int)($_GET['department_id'] ?? ($departments[0]['id'] ?? 0));

    // Dynamic JSON feed callback for FullCalendar AJAX calls
    if (isset($_GET['ajax_events'])) {
        header('Content-Type: application/json');
        $start = $_GET['start'] ?? date('Y-m-d H:i:s', time() - 30 * 86400);
        $end = $_GET['end'] ?? date('Y-m-d H:i:s', time() + 30 * 86400);

        $segments = oncall_get_final_schedule_for_department($current_dept_id, $start, $end);
        $fc_events = [];

        foreach ($segments as $seg) {
            $fc_events[] = [
                'title' => $seg['display_name'],
                'start' => date('c', $seg['start']),
                'end' => date('c', $seg['end']),
                'color' => $seg['is_override'] ? '#ffc107' : '#28a745',
                'textColor' => $seg['is_override'] ? '#212529' : '#ffffff',
                'extendedProps' => [
                    'description' => $seg['description'],
                    'username' => $seg['username']
                ]
            ];
        }
        echo json_encode($fc_events);
        exit;
    }
    ?>
    <div class="row mb-4 text-start">
        <div class="col-md-8">
            <h2><i class="fa-solid fa-calendar-days text-primary me-2"></i>On-Call Calendar Rotation</h2>
            <p class="text-muted">Interactive roster schedules. Overrides are highlighted in yellow, while normal rotations are green.</p>
        </div>
        <div class="col-md-4 text-md-end align-self-center">
            <form method="GET" class="d-inline-block">
                <input type="hidden" name="route" value="oncall_calendar">
                <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $dept['id'] == $current_dept_id ? 'selected' : '' ?>><?= htmlspecialchars($dept['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- FullCalendar container wrapper -->
    <div class="card shadow-sm text-start">
        <div class="card-body">
            <div id="fc_oncall_calendar" style="min-height: 550px;"></div>
        </div>
    </div>

    <!-- FullCalendar Script Initialization -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('fc_oncall_calendar');
        if (calendarEl) {
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: 'index.php?route=oncall_calendar&department_id=<?= $current_dept_id ?>&ajax_events=1',
                eventClick: function(info) {
                    alert('On-Call: ' + info.event.title + '\nType: ' + info.event.extendedProps.description);
                }
            });
            calendar.render();
        }
    });
    </script>
    <?php
});


/* =========================================================
 * VIEW 2: SHIFT TRADES CENTER (oncall_trades)
 * ========================================================= */
$pm->registerRoute('oncall_trades', function () {
    if (!has_permission('oncall_manager_view_schedules')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $msg = '';
    $err = '';
    $current_user_id = $_SESSION['user_id'];

    // Handle trade proposals
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'propose') {
                $dept_id = (int)$_POST['department_id'];
                $slot_id = (int)$_POST['slot_id'];
                oncall_propose_trade($dept_id, $slot_id, $current_user_id);
                $msg = "Shift trade proposed successfully!";
            } elseif ($action === 'accept_take') {
                $trade_id = (int)$_POST['trade_id'];
                oncall_accept_trade_take($trade_id, $current_user_id);
                $msg = "You have agreed to take this shift! Waiting for manager approval.";
            } elseif ($action === 'approve') {
                $trade_id = (int)$_POST['trade_id'];
                oncall_manager_approve_trade($trade_id);
                $msg = "Trade approved successfully!";
            } elseif ($action === 'reject') {
                $trade_id = (int)$_POST['trade_id'];
                oncall_manager_reject_trade($trade_id);
                $msg = "Trade rejected by manager.";
            } elseif ($action === 'cancel') {
                $trade_id = (int)$_POST['trade_id'];
                oncall_cancel_trade_request($trade_id);
                $msg = "Trade request cancelled.";
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }

    $trades = oncall_get_trade_requests_by_department();
    $departments = oncall_get_all_departments();
    ?>
    <div class="row mb-4 text-start">
        <div class="col-md-12">
            <h2><i class="fa-solid fa-right-left text-success me-2"></i>Shift Trade Center</h2>
            <p class="text-muted">Propose, swap, or take shifts securely. Manager approval is required to finalize trades.</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row text-start">
        <!-- Live trades list -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-list me-1"></i>Active Trade Requests</div>
                <div class="card-body p-0">
                    <?php if (empty($trades)): ?>
                        <p class="text-muted p-4 mb-0">No active trade requests found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Department</th>
                                        <th>Proposer</th>
                                        <th>Shift Offered</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trades as $t):
                                        $can_approve = oncall_can_manage_department($t['department_id']);
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($t['department_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($t['proposer_name']) ?></td>
                                            <td><code><?= date('M d, H:i', strtotime($t['offered_start'])) ?></code></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= ucfirst(htmlspecialchars($t['status'])) ?></span>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" class="d-inline-block">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="trade_id" value="<?= $t['id'] ?>">

                                                    <?php if ($t['status'] === 'open' && $t['proposing_user_id'] != $current_user_id): ?>
                                                        <input type="hidden" name="action" value="accept_take">
                                                        <button type="submit" class="btn btn-sm btn-outline-success">Take Shift</button>
                                                    <?php elseif ($t['status'] === 'agreed' && $can_approve): ?>
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn btn-sm btn-success me-1">Approve</button>
                                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                                                    <?php elseif ($t['proposing_user_id'] == $current_user_id && $t['status'] !== 'approved'): ?>
                                                        <input type="hidden" name="action" value="cancel">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                                    <?php else: ?>
                                                        <span class="text-muted small">None</span>
                                                    <?php endif; ?>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Propose dynamic form -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><i class="fa-solid fa-plus me-1"></i>Propose New Trade</div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="propose">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Department</label>
                            <select name="department_id" class="form-select form-select-sm" required id="trade_dept_select" onchange="loadUserSlots(this.value)">
                                <option value="">-- Choose Dept --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Your Shift</label>
                            <select name="slot_id" class="form-select form-select-sm" required id="trade_slot_select">
                                <option value="">-- Select Dept First --</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-paper-plane me-1"></i>Submit Trade Post</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    function loadUserSlots(deptId) {
        if (!deptId) return;
        var select = document.getElementById('trade_slot_select');
        select.innerHTML = '<option>Loading shifts...</option>';

        fetch('index.php?route=oncall_get_user_slots&department_id=' + deptId)
            .then(res => res.json())
            .then(data => {
                select.innerHTML = '';
                if (data.length === 0) {
                    select.innerHTML = '<option value="">No upcoming shifts found</option>';
                    return;
                }
                data.forEach(slot => {
                    var option = document.createElement('option');
                    option.value = slot.id;
                    option.textContent = slot.start_time + ' to ' + slot.end_time;
                    select.appendChild(option);
                });
            });
    }
    </script>
    <?php
});

// Ajax hook callback routing used by Shift Trade UI
$pm->registerRoute('oncall_get_user_slots', function () {
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    $user_id = $_SESSION['user_id'] ?? 0;

    if (!$dept_id || !$user_id) {
        echo json_encode([]);
        exit;
    }

    $slots = oncall_get_user_schedule_slots($user_id, $dept_id);
    echo json_encode($slots);
    exit;
});


/* =========================================================
 * VIEW 3: OVERRIDES LIST VIEW (oncall_overrides)
 * ========================================================= */
$pm->registerRoute('oncall_overrides', function () {
    if (!has_permission('oncall_manager_view_schedules')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $msg = '';
    $err = '';

    // Handle Override Creation/Deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $dept_id = (int)$_POST['department_id'];
            $user_id = (int)$_POST['user_id'];
            $start = $_POST['start_time'];
            $end = $_POST['end_time'];
            $desc = $_POST['description'] ?? '';

            if (oncall_can_manage_department($dept_id)) {
                oncall_create_override($dept_id, $user_id, $start, $end, $desc);
                $msg = "Manual override schedule recorded successfully!";
            } else {
                $err = "You do not have permission to manage overrides for this department.";
            }
        } elseif ($action === 'delete') {
            $id = (int)$_POST['override_id'];
            $dept_id = (int)$_POST['department_id'];
            if (oncall_can_manage_department($dept_id)) {
                oncall_delete_override($id);
                $msg = "Manual override removed.";
            } else {
                $err = "Permission denied.";
            }
        }
    }

    $overrides = oncall_get_overrides();
    $departments = oncall_get_all_departments();
    $users = get_all_users();
    ?>
    <div class="row mb-4 text-start">
        <div class="col-md-12">
            <h2><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Manual Overrides Manager</h2>
            <p class="text-muted">Create calendar schedule overlaps to temporarily assign standard user rotation shifts.</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row text-start">
        <!-- List card -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-table me-1"></i>Active Overrides</div>
                <div class="card-body p-0">
                    <?php if (empty($overrides)): ?>
                        <p class="text-muted p-4 mb-0">No overrides are currently scheduled.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Department</th>
                                        <th>Cover User</th>
                                        <th>Starts</th>
                                        <th>Ends</th>
                                        <th>Reason</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overrides as $ov):
                                        $can_del = oncall_can_manage_department($ov['department_id']);
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($ov['department_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($ov['display_name'] ?? $ov['username']) ?></td>
                                            <td><code><?= date('M d, H:i', strtotime($ov['start_time'])) ?></code></td>
                                            <td><code><?= date('M d, H:i', strtotime($ov['end_time'])) ?></code></td>
                                            <td><small class="text-muted"><?= htmlspecialchars($ov['description']) ?></small></td>
                                            <td class="text-end">
                                                <?php if ($can_del): ?>
                                                    <form method="POST" class="m-0" onsubmit="return confirm('Are you sure you want to delete this override?');">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="override_id" value="<?= $ov['id'] ?>">
                                                        <input type="hidden" name="department_id" value="<?= $ov['department_id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Create card -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark"><i class="fa-solid fa-plus me-1"></i>Schedule Override</div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create">

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Department</label>
                            <select name="department_id" class="form-select form-select-sm" required>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Assigned User</label>
                            <select name="user_id" class="form-select form-select-sm" required>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['display_name'] ?? $u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Start Time</label>
                            <input type="datetime-local" name="start_time" class="form-control form-control-sm" required>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">End Time</label>
                            <input type="datetime-local" name="end_time" class="form-control form-control-sm" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Reason / Note</label>
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="e.g. sick leave cover" required>
                        </div>

                        <button type="submit" class="btn btn-sm btn-warning text-dark w-100"><i class="fa-solid fa-plus me-1"></i>Publish Override</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
});


/* =========================================================
 * VIEW 4: DEPARTMENTS MANAGER (oncall_departments)
 * ========================================================= */
$pm->registerRoute('oncall_departments', function () {
    if (!has_permission('oncall_manager_manage_schedules')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $msg = '';
    $err = '';

    // Handle Department Actions (Create, Delete, update_members)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create') {
                $name = trim($_POST['name'] ?? '');
                $manager_id = (int)$_POST['manager_user_id'];
                oncall_create_department($name, $manager_id);
                $msg = "Department created successfully!";
            } elseif ($action === 'update_members') {
                $dept_id = (int)$_POST['department_id'];
                $selected_users = $_POST['members'] ?? [];
                oncall_save_department_users($dept_id, $selected_users);
                $msg = "Department active membership roster updated!";
            } elseif ($action === 'delete') {
                $dept_id = (int)$_POST['department_id'];
                oncall_delete_department($dept_id);
                $msg = "Department removed from system directory.";
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }

    $departments = oncall_get_all_departments();
    $users = get_all_users();
    ?>
    <div class="row mb-4 text-start">
        <div class="col-md-12">
            <h2><i class="fa-solid fa-sitemap text-primary me-2"></i>Configure Departments</h2>
            <p class="text-muted">Create support departments, toggle NOC modes, and manage rotational member roster pools.</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row text-start">
        <!-- List Departments -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-table-list me-1"></i>Configured Departments</div>
                <div class="card-body p-0">
                    <?php if (empty($departments)): ?>
                        <p class="text-muted p-4 mb-0">No departments created yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Dept Name</th>
                                        <th>Manager</th>
                                        <th>Roster Pool</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departments as $dept):
                                        $members = oncall_get_department_users($dept['id']);
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($dept['name']) ?></strong>
                                                <?php if ($dept['noc_mode']): ?>
                                                    <span class="badge bg-info text-dark small ms-1">NOC Overlays Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($dept['manager_name'] ?? 'None Assigned') ?></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= count($members) ?> Users Joined</span>
                                            </td>
                                            <td class="text-end">
                                                <!-- Edit Members Modal trigger -->
                                                <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#membersModal<?= $dept['id'] ?>">Roster</button>
                                                <!-- Delete button -->
                                                <form method="POST" class="d-inline-block" onsubmit="return confirm('Are you sure you want to delete this department?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>

                                        <!-- MODAL: Manage Members roster list -->
                                        <div class="modal fade" id="membersModal<?= $dept['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Roster pool for <?= htmlspecialchars($dept['name']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="update_members">
                                                        <input type="hidden" name="department_id" value="<?= $dept['id'] ?>">
                                                        <div class="modal-body text-start">
                                                            <p class="small text-muted">Select all user credentials to join into this department's rotational pool:</p>
                                                            <div class="row">
                                                                <?php foreach ($users as $u):
                                                                    $joined = false;
                                                                    foreach ($members as $m) {
                                                                        if ($m['id'] == $u['id']) {
                                                                            $joined = true;
                                                                            break;
                                                                        }
                                                                    }
                                                                ?>
                                                                    <div class="col-md-6 mb-2">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" name="members[]" value="<?= $u['id'] ?>" id="mem_<?= $dept['id'] ?>_<?= $u['id'] ?>" <?= $joined ? 'checked' : '' ?>>
                                                                            <label class="form-check-label" for="mem_<?= $dept['id'] ?>_<?= $u['id'] ?>">
                                                                                <?= htmlspecialchars($u['display_name'] ?? $u['username']) ?>
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                            <button type="submit" class="btn btn-primary">Save Roster Pool</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add Department card -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><i class="fa-solid fa-folder-plus me-1"></i>Create Department</div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create">

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Department Name</label>
                            <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. NOC Infrastructure" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Manager</label>
                            <select name="manager_user_id" class="form-select form-select-sm">
                                <option value="">-- No Manager --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['display_name'] ?? $u['username']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-circle-plus me-1"></i>Create Department</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
});


/* =========================================================
 * VIEW 5: TELEPHONY INTEGRATION VIEW (oncall_telephony)
 * ========================================================= */
$pm->registerRoute('oncall_telephony', function () {
    if (!has_permission('oncall_manager_manage_telephony')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $msg = '';
    $err = '';
    $pdb = oncall_get_pdb();
    $tb_comm = $pdb->getTableName('commportal_accounts');

    // Handle submissions (Create, Delete)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_line') {
                $dept_id = (int)$_POST['department_id'];
                $phone = trim($_POST['phone_number']);
                $pass = trim($_POST['password']);
                $ext = trim($_POST['ext'] ?? '');

                $sql = "INSERT INTO {$tb_comm} (department_id, phone_number, password, ext) VALUES (?, ?, ?, ?)";
                $pdb->query($sql, [$dept_id, $phone, $pass, $ext ?: null]);
                $msg = "CommPortal line connection registered successfully!";
            } elseif ($action === 'delete_line') {
                $id = (int)$_POST['line_id'];
                $pdb->query("DELETE FROM {$tb_comm} WHERE id = ?", [$id]);
                $msg = "CommPortal account removed.";
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }

    $lines = $pdb->query("SELECT c.*, d.name AS department_name FROM {$tb_comm} c JOIN " . $pdb->getTableName('departments') . " d ON c.department_id = d.id")->fetchAll();
    $departments = oncall_get_all_departments();
    ?>
    <div class="row mb-4 text-start">
        <div class="col-md-12">
            <h2><i class="fa-solid fa-phone-volume text-success me-2"></i>CommPortal Telephony Forwarding</h2>
            <p class="text-muted">Configure Metaswitch CommPortal directory lines to dynamically forward support calls to the active on-call user's phone.</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success alert-dismissible fade show text-start"><i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="alert alert-danger alert-dismissible fade show text-start"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= $err ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row text-start">
        <!-- Monitored lines list -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white"><i class="fa-solid fa-tty me-1"></i>Registered CommPortal Connections</div>
                <div class="card-body p-0">
                    <?php if (empty($lines)): ?>
                        <p class="text-muted p-4 mb-0">No active CommPortal lines registered.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Department</th>
                                        <th>Directory Line</th>
                                        <th>Extension</th>
                                        <th>Last Forwarded State</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lines as $line): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($line['department_name']) ?></strong></td>
                                            <td><code><?= htmlspecialchars($line['phone_number']) ?></code></td>
                                            <td><?= htmlspecialchars($line['ext'] ?? 'None') ?></td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($line['last_forwarded_phone'] ?: 'No Forward Logged') ?></span></td>
                                            <td class="text-end">
                                                <form method="POST" class="m-0" onsubmit="return confirm('Are you sure?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_line">
                                                    <input type="hidden" name="line_id" value="<?= $line['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add CommPortal account card -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white"><i class="fa-solid fa-tty me-1"></i>Register CommPortal Line</div>
                <div class="card-body">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_line">

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Department Link</label>
                            <select name="department_id" class="form-select form-select-sm" required>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Directory Number</label>
                            <input type="text" name="phone_number" class="form-control form-control-sm" placeholder="e.g. +1-555-0100" required>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold">Metaswitch Access Password</label>
                            <input type="password" name="password" class="form-control form-control-sm" placeholder="CommPortal PIN" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Internal Ext (Optional)</label>
                            <input type="text" name="ext" class="form-control form-control-sm" placeholder="e.g. 504">
                        </div>

                        <button type="submit" class="btn btn-sm btn-success w-100"><i class="fa-solid fa-tty me-1"></i>Register CommPortal Line</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
});


/* =========================================================
 * VIEW 6: COMPLETE 365-DAY ROTATION GENERATOR (oncall_generate)
 * ========================================================= */
$pm->registerRoute('oncall_generate', function () {
    if (!has_permission('oncall_manager_manage_schedules')) {
        echo '<div class="alert alert-danger">Access Denied.</div>';
        return;
    }

    $message = '';
    $error = '';
    $departments = oncall_get_all_departments();

    // Handle selection of department
    $dept_id = isset($_REQUEST['department_id']) ? (int)$_REQUEST['department_id'] : null;
    $dept_users = [];
    $is_authorized = false;

    if ($dept_id) {
        if (oncall_can_manage_department($dept_id)) {
            $is_authorized = true;
            $dept_users = oncall_get_department_users($dept_id);
        } else {
            $error = 'Unauthorized: Only the designated Manager for this department or a Global Administrator can generate its schedule.';
        }
    }

    // Handle generation form submission with secure CSRF verification
    if (isset($_POST['generate'])) {
        validate_csrf();
        $start_date = $_POST['start_date'] ?? '';
        $included_users = $_POST['include_users'] ?? [];
        $user_orders = $_POST['user_order'] ?? [];

        if (!$is_authorized) {
            $error = 'Unauthorized action.';
        } elseif (empty($start_date)) {
            $error = 'Please select a rotation start date.';
        } elseif (empty($included_users)) {
            $error = 'Please select at least one member for the rotation.';
        } else {
            // Build sorted array of users based on order
            $ordered_members = [];
            foreach ($included_users as $u_id) {
                $order = isset($user_orders[$u_id]) ? (int)$user_orders[$u_id] : 0;
                $ordered_members[] = [
                    'user_id' => (int)$u_id,
                    'order' => $order
                ];
            }

            // Sort by order
            usort($ordered_members, function($a, $b) {
                return $a['order'] <=> $b['order'];
            });

            $final_user_ids = array_column($ordered_members, 'user_id');

            // Parse custom shifts template from input with all the original day options
            $shifts_template = [];
            $raw_shifts = $_POST['shift'] ?? [];
            foreach ($raw_shifts as $s) {
                if (!empty($s['start_time']) && !empty($s['end_time'])) {
                    $shifts_template[] = [
                        'start_day' => (int)$s['start_day'],
                        'start_time' => $s['start_time'],
                        'end_day' => (int)$s['end_day'],
                        'end_time' => $s['end_time'],
                        'rotation_order' => isset($s['rotation_order']) ? (int)$s['rotation_order'] : 1
                    ];
                }
            }

            try {
                oncall_generate_365_day_schedule($dept_id, $final_user_ids, $start_date, $shifts_template);
                $message = "365-day on-call schedule generated successfully! 52 weeks of custom rotation shifts have been created starting from the week of the selected start date.";
            } catch (Exception $e) {
                $error = "Failed to generate schedule: " . $e->getMessage();
            }
        }
    }
    ?>
    <div class="row mb-4 text-start">
        <div class="col-md-12">
            <h1 class="h2"><i class="fa-solid fa-arrows-spin text-primary me-2"></i>Generate On-Call Rotation</h1>
            <p class="text-muted">Generate a complete, customized 365-day / 52-week 24/7 on-call schedule rotation.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show text-start" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
            <div class="mt-2">
                <a href="index.php?route=oncall_calendar&department_id=<?= $dept_id ?>" class="btn btn-sm btn-success"><i class="fa-solid fa-calendar me-1"></i> View Calendar</a>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show text-start" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row text-start">
        <!-- Step 1: Select Department -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fa-solid fa-sitemap me-2 text-primary"></i>1. Select Department
                </div>
                <div class="card-body">
                    <form method="GET" action="index.php" id="deptForm">
                        <input type="hidden" name="route" value="oncall_generate">
                        <div class="mb-3">
                            <label for="department_id" class="form-label fw-semibold">Target Department</label>
                            <select name="department_id" id="department_id" class="form-select form-select-sm" onchange="document.getElementById('deptForm').submit();" required>
                                <option value="">-- Choose Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= ($dept_id == $dept['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Step 2: Configure Rotation Details -->
        <?php if ($dept_id && $is_authorized): ?>
            <div class="col-lg-8">
                <div class="card border-primary shadow-sm">
                    <div class="card-header bg-primary text-white fw-bold">
                        <i class="fa-solid fa-gears me-2"></i>2. Configure Rotation & Generate
                    </div>
                    <div class="card-body">
                        <?php if (empty($dept_users)): ?>
                            <div class="text-center p-4">
                                <h5 class="text-danger">No users assigned to this department!</h5>
                                <p class="text-muted small">You cannot generate a schedule because there are no users in this department.</p>
                                <a href="index.php?route=oncall_departments" class="btn btn-sm btn-outline-danger mt-2">
                                    <i class="fa-solid fa-users me-1"></i> Assign Users to Department
                                </a>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="department_id" value="<?= $dept_id ?>">

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="start_date" class="form-label fw-semibold small">Rotation Start Week (Monday)</label>
                                        <input type="date" class="form-control form-control-sm" name="start_date" id="start_date" value="<?= date('Y-m-d') ?>" required>
                                        <div class="form-text text-muted small">
                                            The 52-week schedule will align and start from the Monday of the selected date's week.
                                        </div>
                                    </div>
                                </div>

                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <label class="form-label fw-semibold text-primary small"><i class="fa-solid fa-clock me-1"></i>Weekly Shifts Template</label>
                                        <div class="form-text text-muted small mb-3">Define 1 or more custom shifts per week. Specify which 'Rotation Order' (Offset) each shift template utilizes to allow different or same members to be assigned dynamically across the shifts. Rows with blank times will be ignored.</div>

                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered align-middle small">
                                                <thead class="table-light text-muted small">
                                                    <tr>
                                                        <th style="width: 22%;">Start Day</th>
                                                        <th style="width: 20%;">Start Time</th>
                                                        <th style="width: 4%;"></th>
                                                        <th style="width: 22%;">End Day</th>
                                                        <th style="width: 20%;">End Time</th>
                                                        <th style="width: 12%;">Rotation Order</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php for ($s = 0; $s < 4; $s++): ?>
                                                        <tr>
                                                            <td>
                                                                <select name="shift[<?= $s ?>][start_day]" class="form-select form-select-sm">
                                                                    <option value="1" <?= $s == 0 ? 'selected' : '' ?>>Monday</option>
                                                                    <option value="2">Tuesday</option>
                                                                    <option value="3">Wednesday</option>
                                                                    <option value="4">Thursday</option>
                                                                    <option value="5">Friday</option>
                                                                    <option value="6">Saturday</option>
                                                                    <option value="7">Sunday</option>
                                                                </select>
                                                            </td>
                                                            <td>
                                                                <input type="time" name="shift[<?= $s ?>][start_time]" class="form-control form-control-sm" value="<?= $s == 0 ? '17:00' : '' ?>" style="min-width: 110px;">
                                                            </td>
                                                            <td class="text-center small text-muted">to</td>
                                                            <td>
                                                                <select name="shift[<?= $s ?>][end_day]" class="form-select form-select-sm">
                                                                    <option value="1" <?= $s == 0 ? 'selected' : '' ?>>Monday</option>
                                                                    <option value="2">Tuesday</option>
                                                                    <option value="3">Wednesday</option>
                                                                    <option value="4">Thursday</option>
                                                                    <option value="5">Friday</option>
                                                                    <option value="6">Saturday</option>
                                                                    <option value="7">Sunday</option>
                                                                </select>
                                                            </td>
                                                            <td>
                                                                <input type="time" name="shift[<?= $s ?>][end_time]" class="form-control form-control-sm" value="<?= $s == 0 ? '17:00' : '' ?>" style="min-width: 110px;">
                                                            </td>
                                                            <td>
                                                                <select name="shift[<?= $s ?>][rotation_order]" class="form-select form-select-sm">
                                                                    <option value="1" <?= $s == 0 ? 'selected' : '' ?>>Order 1</option>
                                                                    <option value="2" <?= $s == 1 ? 'selected' : '' ?>>Order 2</option>
                                                                    <option value="3" <?= $s == 2 ? 'selected' : '' ?>>Order 3</option>
                                                                    <option value="4" <?= $s == 3 ? 'selected' : '' ?>>Order 4</option>
                                                                    <option value="5">Order 5</option>
                                                                </select>
                                                            </td>
                                                        </tr>
                                                    <?php endfor; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <h5 class="h6 fw-bold mb-3">Select Members and Set Rotation Order</h5>
                                <p class="text-muted small mb-3">
                                    Check the box for each member that should be part of the on-call pool, and specify their order number (e.g. 1, 2, 3...) to determine who goes first, second, etc.
                                </p>

                                <div class="table-responsive mb-4">
                                    <table class="table table-bordered table-hover align-middle small">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 10%;">Include?</th>
                                                <th>Member Name</th>
                                                <th>Username</th>
                                                <th style="width: 25%;">Rotation Order</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $idx = 1;
                                            foreach ($dept_users as $user):
                                            ?>
                                                <tr>
                                                    <td class="text-center">
                                                        <input class="form-check-input" type="checkbox" name="include_users[]" value="<?= $user['id'] ?>" id="include_<?= $user['id'] ?>" checked>
                                                    </td>
                                                    <td>
                                                        <label class="form-check-label fw-semibold" for="include_<?= $user['id'] ?>">
                                                            <?= htmlspecialchars($user['display_name'] ?? $user['username']) ?>
                                                        </label>
                                                    </td>
                                                    <td>
                                                        <code>@<?= htmlspecialchars($user['username']) ?></code>
                                                    </td>
                                                    <td>
                                                        <input type="number" class="form-control form-control-sm" name="user_order[<?= $user['id'] ?>]" value="<?= $idx++ ?>" min="1" step="1" required>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="alert alert-warning small">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                                    <strong>Warning:</strong> Generating a new schedule will overwrite any existing base 365-day schedule slots for this department. Any manual overrides you've defined will still be preserved and applied on top of the new rotation!
                                </div>

                                <button type="submit" name="generate" class="btn btn-success btn-sm w-100">
                                    <i class="fa-solid fa-arrows-spin me-2"></i>Generate 365-Day Schedule
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="col-lg-8">
                <div class="alert alert-light border text-center p-5">
                    <i class="fa-solid fa-circle-info text-muted mb-3" style="font-size: 2.5rem;"></i>
                    <h5>Select an Authorized Department</h5>
                    <p class="text-muted">Please select a department from the panel on the left where you are the designated on-call group manager or an administrator.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
});
