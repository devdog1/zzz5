<?php
// login.php - Login selection (Azure AD)
require_once __DIR__ . '/functions.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Handle real Azure AD login redirect
if (isset($_POST['azure_login'])) {
    try {
        get_auth()->login();
    } catch (Exception $e) {
        $error = "Azure Login failed to initiate: " . $e->getMessage();
    }
}

// Support mock local login for development/testing if configured
$is_dev = true; // Set to true to allow testing without live Azure tenant credentials
if ($is_dev && isset($_POST['dev_login'])) {
    $dev_user = trim($_POST['dev_user'] ?? 'admin@example.com');

    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT id, display_name FROM users WHERE username = ?");
        $stmt->execute([$dev_user]);
        $user = $stmt->fetch();

        if (!$user) {
            // Provision mock user
            $stmt = $db->prepare("
                INSERT INTO users (username, email, display_name, auto_provisioned)
                VALUES (?, ?, ?, 1)
            ");
            $name_parts = explode('@', $dev_user);
            $stmt->execute([$dev_user, $dev_user, ucfirst($name_parts[0])]);
            $userId = (int)$db->lastInsertId();

            // Assign default/admin roles
            $stmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $stmt->execute([$userId, 1]); // Admin
        } else {
            $userId = (int)$user['id'];
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['user'] = [
            'azure_oid' => 'mock_oid_' . $userId,
            'email'     => $dev_user,
            'name'      => $user ? $user['display_name'] : ucfirst(explode('@', $dev_user)[0]),
            'groups'    => ['Admins']
        ];

        $_SESSION['roles'] = get_auth()->getRoles($userId, ['Admins']);
        $_SESSION['permissions'] = get_auth()->getPermissions($userId, ['Admins']);

        header("Location: index.php");
        exit;
    } catch (Exception $e) {
        $error = "Mock Login failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Base Framework</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            max-width: 450px;
            width: 100%;
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="card login-card p-4">
    <div class="text-center mb-4">
        <div class="bg-dark text-info rounded-circle d-inline-flex p-3 mb-3">
            <i class="fa-solid fa-cubes fa-2x"></i>
        </div>
        <h3 class="fw-bold text-dark">Portal Framework</h3>
        <p class="text-muted small">Sign in to access modules & features</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger small">
            <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Real Azure Login -->
    <form method="POST" class="mb-3">
        <button type="submit" name="azure_login" class="btn btn-primary btn-lg w-100 d-flex align-items-center justify-content-center">
            <i class="fa-brands fa-microsoft me-2"></i> Sign in with Microsoft Azure
        </button>
    </form>

    <?php if ($is_dev): ?>
        <div class="border-top pt-3 mt-3">
            <p class="text-muted text-center small">Local Development Mock Login</p>
            <form method="POST">
                <div class="mb-2">
                    <input type="email" name="dev_user" class="form-control form-control-sm" value="admin@example.com" placeholder="Email Address">
                </div>
                <button type="submit" name="dev_login" class="btn btn-secondary btn-sm w-100">
                    <i class="fa-solid fa-terminal me-1"></i> Mock Developer Login
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
