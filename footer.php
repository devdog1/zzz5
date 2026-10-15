</div> <!-- /container -->

<footer class="footer mt-auto py-3 bg-dark text-white-50 border-top border-secondary">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center">
        <div class="mb-2 mb-md-0 small">
            <span class="fw-bold text-light me-2"><?= htmlspecialchars(get_setting('site_name', 'Framework Portal')) ?></span>
            <span>&copy; <?= date('Y') ?> Enterprise Pluggable Portal. All rights reserved.</span>
        </div>
        <div class="small d-flex align-items-center gap-3">
            <span>Framework Core <span class="badge bg-primary text-white font-monospace">v<?= get_framework_version() ?></span></span>
            <?php if (function_exists('has_permission') && (has_permission('manage_settings') || has_permission('manage_plugins'))): ?>
                <a href="admin-changelog.php" class="text-warning text-decoration-none small fw-semibold">
                    <i class="fa-solid fa-scroll me-1"></i>Changelog
                </a>
            <?php endif; ?>
        </div>
    </div>
</footer>

<!-- Bootstrap 5 Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Action Hook for custom plugin footer scripts -->
<?php $pluginManager->doAction('theme_footer'); ?>
<?php $pluginManager->renderEnqueuedScripts(true); ?>

</body>
</html>
