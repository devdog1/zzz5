<?php
// header.php - Beautiful themed layout with Bootstrap 5 and Extensible Plugin Navigation Hooks
require_once __DIR__ . '/functions.php';

$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'login.php' && $current_page !== 'callback.php') {
    require_login();
}

$user_display_name = $_SESSION['user']['name'] ?? 'User';
$user_roles = isset($_SESSION['roles']) ? array_keys($_SESSION['roles']) : [];
$user_roles_str = implode(', ', array_map('ucfirst', $user_roles));
$site_name = get_setting('site_name', 'Framework Portal');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_name) ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">

    <!-- Action Hook for custom plugin styles / scripts inside <head> -->
    <?php $pluginManager->doAction('theme_head'); ?>

    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #f2f2f2;
            font-weight: 600;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="fa-solid fa-cubes me-2 text-info"></i><?= htmlspecialchars($site_name) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page === 'index.php' && !isset($_GET['route'])) ? 'active' : '' ?>" href="index.php">
                        <i class="fa-solid fa-house me-1"></i> Dashboard
                    </a>
                </li>

                <!-- Core Administration Links -->
                <?php if (has_permission('manage_settings')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page === 'admin-users.php') ? 'active' : '' ?>" href="admin-users.php">
                            <i class="fa-solid fa-users-gear me-1"></i> Users & RBAC
                        </a>
                    </li>
                <?php endif; ?>

                <!-- Filter Hook for plugins to add navigation links dynamically -->
                <?php
                $nav_links = [];
                $nav_links = $pluginManager->applyFilters('theme_nav_links', $nav_links);

                foreach ($nav_links as $link) {
                    if (isset($link['permission']) && !has_permission($link['permission'])) {
                        continue;
                    }
                    $active_class = (isset($_GET['route']) && $_GET['route'] === $link['route']) ? 'active' : '';
                    echo '<li class="nav-item">';
                    echo '<a class="nav-link ' . $active_class . '" href="index.php?route=' . urlencode($link['route']) . '">';
                    if (isset($link['icon'])) {
                        echo '<i class="' . htmlspecialchars($link['icon']) . ' me-1"></i> ';
                    }
                    echo htmlspecialchars($link['label']);
                    echo '</a>';
                    echo '</li>';
                }
                ?>
            </ul>

            <?php if (isset($_SESSION['user_id'])): ?>
                <div class="d-flex align-items-center text-white">
                    <div class="me-3 text-end">
                        <div class="fw-bold small"><?= htmlspecialchars($user_display_name) ?></div>
                        <div class="text-muted small" style="font-size: 0.75rem;"><?= htmlspecialchars($user_roles_str) ?></div>
                    </div>
                    <a href="logout.php" class="btn btn-sm btn-outline-danger">
                        <i class="fa-solid fa-right-from-bracket me-1"></i>Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container pb-5">
