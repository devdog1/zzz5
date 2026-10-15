<?php
// admin-email.php - Outbound Email Configuration & Detailed Delivery Tracking Logs
require_once __DIR__ . '/functions.php';

require_login();
if (!has_permission('manage_settings')) {
    die("Access Denied: You lack permissions to access system email configuration.");
}

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_email_settings') {
    csrf_verify();

    set_setting('email_from_address', trim($_POST['email_from_address'] ?? ''));
    set_setting('email_envelope_from', trim($_POST['email_envelope_from'] ?? ''));
    set_setting('email_smtp_host', trim($_POST['email_smtp_host'] ?? ''));
    set_setting('email_smtp_port', trim($_POST['email_smtp_port'] ?? '25'));
    set_setting('email_smtp_encryption', trim($_POST['email_smtp_encryption'] ?? 'none'));
    set_setting('email_smtp_auth', !empty($_POST['email_smtp_auth']) ? '1' : '0');
    set_setting('email_smtp_user', trim($_POST['email_smtp_user'] ?? ''));
    if (!empty($_POST['email_smtp_pass'])) {
        set_setting('email_smtp_pass', trim($_POST['email_smtp_pass']));
    }

    redirect('admin-email.php?msg=' . urlencode('Outbound email settings updated successfully.'));
}

// Handle Test Email Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_test_email') {
    csrf_verify();

    $test_to = trim($_POST['test_to'] ?? '');
    if (!empty($test_to)) {
        $res = send_email(
            $test_to,
            'Test Email from ' . get_setting('site_name', 'Framework Portal'),
            '<h3>Outbound Email Subsystem Verification</h3><p>This email confirms that the Framework Outbound Email Handler is properly configured and functioning correctly.</p>',
            'core_admin'
        );

        if ($res['success']) {
            redirect('admin-email.php?msg=' . urlencode("Test email dispatched successfully! SMTP Code: " . $res['smtp_code']));
        } else {
            redirect('admin-email.php?err=' . urlencode("Test email dispatch failed! SMTP Code: " . $res['smtp_code'] . " - " . $res['smtp_message']));
        }
    }
}

// Fetch Email Logs
$db = get_db_connection();
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$query = "SELECT * FROM email_logs WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (to_address LIKE ? OR from_address LIKE ? OR subject LIKE ? OR plugin_slug LIKE ?)";
    $term = "%{$search}%";
    $params = [$term, $term, $term, $term];
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY sent_at DESC LIMIT 100";

$stmt = $db->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="fw-bold text-dark mb-0"><i class="fa-solid fa-paper-plane text-primary me-2"></i>Outbound Email Handler & Logs</h2>
        <small class="text-muted">Configure SMTP Relay Settings and Monitor All Outbound Emails & SMTP DSN Codes</small>
    </div>
    <button class="btn btn-primary btn-pill" data-bs-toggle="modal" data-bs-target="#testEmailModal">
        <i class="fa-solid fa-paper-plane me-1"></i> Send Test Email
    </button>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($err) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Left Column: Global SMTP Settings Card -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-gears text-primary me-2"></i>SMTP Relay Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_email_settings">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Default From Address</label>
                        <input type="email" name="email_from_address" class="form-control" value="<?= htmlspecialchars(get_setting('email_from_address', 'noreply@example.com')) ?>" required>
                        <small class="text-muted" style="font-size: 0.75rem;">Plugins can override this on a per-email basis.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Postfix Envelope From (Return-Path)</label>
                        <input type="email" name="email_envelope_from" class="form-control" value="<?= htmlspecialchars(get_setting('email_envelope_from', 'bounces@example.com')) ?>">
                        <small class="text-muted" style="font-size: 0.75rem;">Address for bounce DSN notifications.</small>
                    </div>

                    <hr class="my-3">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">SMTP Relay Server Host</label>
                        <input type="text" name="email_smtp_host" class="form-control" value="<?= htmlspecialchars(get_setting('email_smtp_host', 'localhost')) ?>" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Port</label>
                            <input type="number" name="email_smtp_port" class="form-control" value="<?= htmlspecialchars(get_setting('email_smtp_port', '25')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Encryption</label>
                            <select name="email_smtp_encryption" class="form-select">
                                <?php $enc = get_setting('email_smtp_encryption', 'none'); ?>
                                <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>>None / Plain</option>
                                <option value="tls" <?= ($enc === 'tls' || $enc === 'starttls') ? 'selected' : '' ?>>STARTTLS</option>
                                <option value="ssl" <?= ($enc === 'ssl' || $enc === 'smtps') ? 'selected' : '' ?>>SSL/TLS</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="email_smtp_auth" class="form-check-input" id="smtpAuthCheck" <?= get_setting('email_smtp_auth', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold small" for="smtpAuthCheck">Requires SMTP Authentication</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">SMTP Username</label>
                        <input type="text" name="email_smtp_user" class="form-control" value="<?= htmlspecialchars(get_setting('email_smtp_user', '')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">SMTP Password</label>
                        <input type="password" name="email_smtp_pass" class="form-control" placeholder="Leave blank to keep unchanged">
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-semibold">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Configuration
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Outbound Email Log History -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list-check text-primary me-2"></i>Outbound Email Audit Trail</h5>
                    <span class="badge bg-secondary"><?= count($logs) ?> Entries</span>
                </div>
            </div>
            <div class="card-body">
                <!-- Search & Status Filter Toolbar -->
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-7">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search To, From, Subject, or Plugin..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent (2xx)</option>
                            <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed (4xx/5xx)</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-secondary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sent At</th>
                                <th>Plugin</th>
                                <th>From / To</th>
                                <th>Subject</th>
                                <th>Status / SMTP Code</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No outbound email log records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="text-nowrap text-secondary"><?= date('M d, H:i:s', strtotime($log['sent_at'])) ?></td>
                                        <td>
                                            <?php if ($log['plugin_slug']): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($log['plugin_slug']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($log['to_address']) ?></div>
                                            <div class="text-muted extra-small" style="font-size: 0.725rem;">From: <?= htmlspecialchars($log['from_address']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark text-truncate" style="max-width: 220px;" title="<?= htmlspecialchars($log['subject']) ?>"><?= htmlspecialchars($log['subject']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($log['status'] === 'sent'): ?>
                                                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Sent</span>
                                            <?php elseif ($log['status'] === 'failed'): ?>
                                                <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            <?php endif; ?>
                                            <div class="small fw-bold mt-1 text-secondary"><?= htmlspecialchars($log['smtp_code'] ?: 'N/A') ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Send Test Email Modal -->
<div class="modal fade" id="testEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_test_email">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-paper-plane me-2 text-primary"></i>Send Test Outbound Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Recipient Email Address</label>
                        <input type="email" name="test_to" class="form-control" placeholder="user@example.com" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i> Dispatch Test</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
