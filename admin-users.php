<?php
// admin-users.php - Admin User Directory and Permissions Management Interface
require_once __DIR__ . '/functions.php';

// Ensure user has admin rights
if (!has_permission('manage_settings')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to manage users.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$db = get_db_connection();
$msg = '';
$err = '';

// Handle actions (Grant Role, Revoke Role, Grant Permission, Deny Permission, Create User)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $display_name = trim($_POST['display_name'] ?? '');

        if (!empty($username) && !empty($email)) {
            try {
                $stmt = $db->prepare("INSERT INTO users (username, email, display_name) VALUES (?, ?, ?)");
                $stmt->execute([$username, $email, $display_name ?: $username]);
                $userId = $db->lastInsertId();

                // Assign standard user role
                $stmtRole = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, 3)");
                $stmtRole->execute([$userId]);

                $msg = "User <strong>" . htmlspecialchars($username) . "</strong> created successfully!";
                log_action('ADMIN_CREATE_USER', ['username' => $username, 'email' => $email]);
            } catch (Exception $e) {
                $err = "Error creating user: " . $e->getMessage();
            }
        } else {
            $err = "Username and Email are required fields.";
        }
    } elseif ($action === 'update_roles_permissions') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $roles_to_assign = $_POST['roles'] ?? []; // Array of role IDs
        $direct_permissions = $_POST['direct_permissions'] ?? []; // Array of permission IDs
        $denied_permissions = $_POST['denied_permissions'] ?? []; // Array of permission IDs

        if ($target_user_id > 0) {
            $db->beginTransaction();
            try {
                // Update User Roles
                $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ?");
                $stmt->execute([$target_user_id]);

                if (!empty($roles_to_assign)) {
                    $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roles_to_assign as $role_id) {
                        $stmt->execute([$target_user_id, (int)$role_id]);
                    }
                }

                // Update Direct Permissions
                $stmt = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $stmt->execute([$target_user_id]);

                if (!empty($direct_permissions)) {
                    $stmt = $db->prepare("INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)");
                    foreach ($direct_permissions as $perm_id) {
                        $stmt->execute([$target_user_id, (int)$perm_id]);
                    }
                }

                // Update Denied Permissions
                $stmt = $db->prepare("DELETE FROM denied_permissions WHERE user_id = ?");
                $stmt->execute([$target_user_id]);

                if (!empty($denied_permissions)) {
                    $stmt = $db->prepare("INSERT INTO denied_permissions (user_id, permission_id) VALUES (?, ?)");
                    foreach ($denied_permissions as $perm_id) {
                        $stmt->execute([$target_user_id, (int)$perm_id]);
                    }
                }

                $db->commit();
                $msg = "User privileges updated successfully!";
                log_action('ADMIN_UPDATE_USER_PRIVILEGES', ['user_id' => $target_user_id]);
            } catch (Exception $e) {
                $db->rollBack();
                $err = "Failed to update user privileges: " . $e->getMessage();
            }
        }
    }
}

// Fetch lists
$users = get_all_users();
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$permissions = $db->query("SELECT * FROM permissions ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h1 class="h2"><i class="fa-solid fa-users-gear text-primary me-2"></i>User & Permissions Management</h1>
        <p class="text-muted">Control global RBAC roles, assign specific direct permissions, deny permissions, and invite new users.</p>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i> <?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $err ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Users List & Permissions Configuration -->
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white">
                <i class="fa-solid fa-list-check me-2"></i>Portal User Directory & Privileges
            </div>
            <div class="card-body p-0">
                <?php if (empty($users)): ?>
                    <p class="text-muted p-4 mb-0">No users found in database.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User Information</th>
                                    <th>Roles</th>
                                    <th class="text-end">Manage Privileges</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user):
                                    // Fetch user roles
                                    $stmt = $db->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
                                    $stmt->execute([$user['id']]);
                                    $user_role_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                    // Fetch user direct permissions
                                    $stmt = $db->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ?");
                                    $stmt->execute([$user['id']]);
                                    $user_direct_perm_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                    // Fetch user denied permissions
                                    $stmt = $db->prepare("SELECT permission_id FROM denied_permissions WHERE user_id = ?");
                                    $stmt->execute([$user['id']]);
                                    $user_denied_perm_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                    // Roles string representation
                                    $user_roles_str = [];
                                    foreach ($roles as $r) {
                                        if (in_array($r['id'], $user_role_ids)) {
                                            $user_roles_str[] = $r['role_name'];
                                        }
                                    }
                                    $roles_badge = !empty($user_roles_str) ? implode(', ', array_map('ucfirst', $user_roles_str)) : 'None';
                                ?>
                                    <tr>
                                        <td>
                                            <h6 class="mb-0 fw-bold"><?= htmlspecialchars($user['display_name'] ?? 'User') ?></h6>
                                            <small class="text-muted"><i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($user['email']) ?></small>
                                            <br>
                                            <small class="text-muted">Username: <code><?= htmlspecialchars($user['username']) ?></code></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($roles_badge) ?></span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $user['id'] ?>">
                                                <i class="fa-solid fa-user-shield me-1"></i>Edit RBAC
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Modal for each user editing -->
                                    <div class="modal fade" id="editModal<?= $user['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title"><i class="fa-solid fa-user-shield me-2 text-primary"></i>Edit Privileges for <?= htmlspecialchars($user['display_name']) ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <?php csrf_field(); ?>
                                                    <div class="modal-body text-start">
                                                        <input type="hidden" name="action" value="update_roles_permissions">
                                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                                                        <!-- Roles section -->
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-tags me-1"></i>Assign Roles</h6>
                                                        <div class="row mb-4">
                                                            <?php foreach ($roles as $r): ?>
                                                                <div class="col-md-4">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $r['id'] ?>" id="role_<?= $user['id'] ?>_<?= $r['id'] ?>" <?= in_array($r['id'], $user_role_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label" for="role_<?= $user['id'] ?>_<?= $r['id'] ?>">
                                                                            <strong><?= ucfirst(htmlspecialchars($r['role_name'])) ?></strong>
                                                                            <div class="text-muted small"><?= htmlspecialchars($r['description']) ?></div>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>

                                                        <!-- Direct Permissions Section -->
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-plus-circle me-1 text-success"></i>Directly Grant Extra Permissions</h6>
                                                        <div class="row mb-4">
                                                            <?php foreach ($permissions as $p): ?>
                                                                <div class="col-md-4">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" name="direct_permissions[]" value="<?= $p['id'] ?>" id="perm_g_<?= $user['id'] ?>_<?= $p['id'] ?>" <?= in_array($p['id'], $user_direct_perm_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label" for="perm_g_<?= $user['id'] ?>_<?= $p['id'] ?>">
                                                                            <code><?= htmlspecialchars($p['permission_name']) ?></code>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>

                                                        <!-- Denied Permissions Section -->
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="fa-solid fa-minus-circle me-1 text-danger"></i>Explicitly Deny Permissions (Highest Precedence)</h6>
                                                        <div class="row">
                                                            <?php foreach ($permissions as $p): ?>
                                                                <div class="col-md-4">
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" name="denied_permissions[]" value="<?= $p['id'] ?>" id="perm_d_<?= $user['id'] ?>_<?= $p['id'] ?>" <?= in_array($p['id'], $user_denied_perm_ids) ? 'checked' : '' ?>>
                                                                        <label class="form-check-label" for="perm_d_<?= $user['id'] ?>_<?= $p['id'] ?>">
                                                                            <code><?= htmlspecialchars($p['permission_name']) ?></code>
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>Save Changes</button>
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

    <!-- Create User Form -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <i class="fa-solid fa-user-plus me-1"></i>Add New Local User
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="create_user">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Username / Login ID</label>
                        <input type="text" name="username" class="form-control form-control-sm" placeholder="e.g. jdoe@example.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="e.g. jdoe@example.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Display Name</label>
                        <input type="text" name="display_name" class="form-control form-control-sm" placeholder="e.g. John Doe">
                    </div>

                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="fa-solid fa-circle-plus me-1"></i>Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
