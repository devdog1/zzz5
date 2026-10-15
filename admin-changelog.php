<?php
// admin-changelog.php - Core Framework Changelog & Release History Control Panel
require_once __DIR__ . '/functions.php';

// Enforce permission check
if (!has_permission('manage_settings') && !has_permission('manage_plugins')) {
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Access Denied. You do not have permission to access administrative changelog.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$changelog_file = __DIR__ . '/CHANGELOG.md';
$changelog_exists = file_exists($changelog_file);
$raw_changelog = $changelog_exists ? file_get_contents($changelog_file) : '';
$html_changelog = $changelog_exists ? PluginManager::getInstance()->parseMarkdownToHtml($raw_changelog) : '';

require_once __DIR__ . '/header.php';
?>

<div class="row mb-4 text-start">
    <div class="col-md-12">
        <h1 class="h2"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Framework Release History & Changelog</h1>
        <p class="text-muted">Review core framework version history, feature additions, database upgrades, and security improvements.</p>
    </div>
</div>

<div class="card shadow-sm border-primary mb-4 text-start">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
        <span class="fw-bold fs-5"><i class="fa-solid fa-scroll me-2"></i>Core Framework Changelog (<code>CHANGELOG.md</code>)</span>
        <span class="badge bg-light text-primary fs-6 font-monospace"><i class="fa-solid fa-code-branch me-1"></i>v<?= get_framework_version() ?></span>
    </div>
    <div class="card-body p-4">
        <?php if ($changelog_exists): ?>
            <div class="changelog-container">
                <?= $html_changelog ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Core framework <code>CHANGELOG.md</code> file not found at project root.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
