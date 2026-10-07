<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDatabaseConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = (string)($_POST['action'] ?? '');
    $providerId = filter_input(INPUT_POST, 'provider_id', FILTER_VALIDATE_INT);
    $newStatus = (string)($_POST['verification_status'] ?? '');

    if ($action === 'update_verification' && $providerId && in_array($newStatus, ['approved', 'rejected', 'pending'], true)) {
        try {
            $oldStmt = $pdo->prepare('SELECT verification_status FROM provider_profiles WHERE id = :id LIMIT 1');
            $oldStmt->execute(['id' => $providerId]);
            $oldStatus = $oldStmt->fetchColumn();
            if ($oldStatus === false) {
                $error = 'The selected provider was not found.';
            } else {
                $stmt = $pdo->prepare('UPDATE provider_profiles SET verification_status = :status WHERE id = :id');
                $stmt->execute(['status' => $newStatus, 'id' => $providerId]);
                writeAuditLog($pdo, 'provider_verification_changed', 'provider_profile', $providerId, ['verification_status' => $oldStatus], ['verification_status' => $newStatus]);
                $message = 'Provider verification status updated successfully.';
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $error = 'Failed to update provider status.';
        }
    } elseif ($action === 'update_marketplace' && $providerId) {
        $newMarketplaceStatus = filter_var($_POST['marketplace_active'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($newMarketplaceStatus, [0, 1], true)) {
            $error = 'Choose a valid marketplace status.';
        } else {
            try {
                $oldStmt = $pdo->prepare('SELECT verification_status, marketplace_active FROM provider_profiles WHERE id = :id LIMIT 1');
                $oldStmt->execute(['id' => $providerId]);
                $old = $oldStmt->fetch();
                if (!$old) {
                    $error = 'The selected provider was not found.';
                } elseif ($newMarketplaceStatus === 1 && $old['verification_status'] !== 'approved') {
                    $error = 'Only approved providers can be active in the marketplace.';
                } else {
                    $stmt = $pdo->prepare('UPDATE provider_profiles SET marketplace_active = :active WHERE id = :id');
                    $stmt->execute(['active' => $newMarketplaceStatus, 'id' => $providerId]);
                    writeAuditLog($pdo, 'provider_marketplace_status_changed', 'provider_profile', $providerId, ['marketplace_active' => (int)$old['marketplace_active']], ['marketplace_active' => $newMarketplaceStatus]);
                    $message = 'Provider marketplace visibility updated.';
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $error = 'Failed to update marketplace visibility.';
            }
        }
    }
}

$marketplaceView = in_array((string)($_GET['marketplace'] ?? ''), ['active', 'historical'], true) ? (string)$_GET['marketplace'] : 'all';
$marketplaceWhere = match ($marketplaceView) {
    'active' => 'WHERE pp.marketplace_active = 1',
    'historical' => 'WHERE pp.marketplace_active = 0',
    default => '',
};
$stmt = $pdo->query(
    'SELECT pp.id, pp.business_name, pp.phone, pp.city, pp.area, pp.experience_years,
            pp.verification_status, pp.availability_status, pp.marketplace_active,
            COALESCE(rep.average_rating, 0) AS average_rating, COALESCE(rep.total_reviews, 0) AS total_reviews,
            u.name AS provider_name, u.email AS provider_email, u.created_at
     FROM provider_profiles pp
     INNER JOIN users u ON u.id = pp.user_id
     LEFT JOIN (
         SELECT provider_id, ROUND(AVG(rating), 1) AS average_rating, COUNT(*) AS total_reviews
         FROM reviews WHERE status = \'published\' GROUP BY provider_id
     ) rep ON rep.provider_id = pp.id
     ' . $marketplaceWhere . '
     ORDER BY pp.marketplace_active DESC, pp.created_at DESC'
);
$providers = $stmt->fetchAll();
$providers = array_map(static fn(array $provider): array => decryptSensitiveFields($provider, ['phone']), $providers);

$pageTitle = 'Verify Providers | ServeIQ Admin';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Admin Workspace</span>
                <h1 class="mb-1">Service Providers</h1>
                <p class="text-muted mb-0">Review provider verification, marketplace visibility, and reputation. Historical profiles remain available for existing records.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Admin Dashboard
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success rounded-4 mb-4"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger rounded-4 mb-4"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3"><h2 class="h5 mb-0">Service provider records (<?= count($providers) ?>)</h2><form method="GET" class="d-flex align-items-center gap-2"><label class="small text-muted" for="marketplaceView">Marketplace status</label><select class="form-select form-select-sm" id="marketplaceView" name="marketplace" onchange="this.form.submit()"><option value="all" <?= $marketplaceView === 'all' ? 'selected' : '' ?>>All</option><option value="active" <?= $marketplaceView === 'active' ? 'selected' : '' ?>>Active marketplace</option><option value="historical" <?= $marketplaceView === 'historical' ? 'selected' : '' ?>>Historical / inactive</option></select><noscript><button class="btn btn-sm btn-outline-primary" type="submit">Filter</button></noscript></form></div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Business Name</th>
                            <th>Contact Person</th>
                            <th>Location</th>
                            <th>Experience</th>
                            <th>Reputation</th>
                            <th>Verification</th>
                            <th>Marketplace</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($providers)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-building-slash fs-3 d-block mb-1"></i>No service providers found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($providers as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($p['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($p['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($p['provider_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($p['provider_email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($p['city'], ENT_QUOTES, 'UTF-8') ?><?= $p['area'] ? ', ' . htmlspecialchars($p['area'], ENT_QUOTES, 'UTF-8') : '' ?></td>
                                    <td><?= (int)$p['experience_years'] ?> yrs</td>
                                    <td>
                                        <?php if ((int)$p['total_reviews'] > 0): ?>
                                            <span class="text-warning"><i class="bi bi-star-fill"></i></span>
                                            <strong><?= number_format((float)$p['average_rating'], 1) ?></strong>
                                            <span class="small text-muted">(<?= (int)$p['total_reviews'] ?>)</span>
                                        <?php else: ?>
                                            <span class="small text-muted">New (No reviews)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['verification_status'] === 'approved'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="bi bi-patch-check-fill me-1"></i>Approved
                                            </span>
                                        <?php elseif ($p['verification_status'] === 'rejected'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                                <i class="bi bi-x-circle me-1"></i>Rejected
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                                <i class="bi bi-clock me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$p['marketplace_active'] === 1 ? '<span class="badge bg-success-subtle text-success border">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Historical</span>' ?></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <?php if ($p['verification_status'] !== 'approved'): ?>
                                            <form method="POST" class="d-inline">
                                                <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="update_verification">
                                                    <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
                                                    <input type="hidden" name="verification_status" value="approved">
                                                    <button type="submit" class="btn btn-sm btn-success" title="Approve Provider"><i class="bi bi-check-lg"></i> Approve</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($p['verification_status'] !== 'rejected'): ?>
                                                <form method="POST" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="update_verification">
                                                    <input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>">
                                                    <input type="hidden" name="verification_status" value="rejected">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject Provider">
                                                        <i class="bi bi-slash-circle"></i> Reject
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <form method="POST" class="mt-2" data-confirm="Change this provider's marketplace visibility?"><?= csrfField() ?><input type="hidden" name="action" value="update_marketplace"><input type="hidden" name="provider_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="marketplace_active" value="<?= (int)$p['marketplace_active'] === 1 ? '0' : '1' ?>"><button type="submit" class="btn btn-sm <?= (int)$p['marketplace_active'] === 1 ? 'btn-outline-secondary' : 'btn-outline-primary' ?>" <?= (int)$p['marketplace_active'] === 0 && $p['verification_status'] !== 'approved' ? 'disabled title="Approve the provider first"' : '' ?>><?= (int)$p['marketplace_active'] === 1 ? 'Deactivate' : 'Activate' ?></button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
