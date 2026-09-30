<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDatabaseConnection();
$search = trim((string)($_GET['search'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));

$sql = 'SELECT id, name, email, role, profile_image, created_at FROM users WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (name LIKE :search OR email LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($roleFilter !== '' && in_array($roleFilter, ['customer', 'provider', 'admin'], true)) {
    $sql .= ' AND role = :role';
    $params['role'] = $roleFilter;
}

$sql .= ' ORDER BY created_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users | ServeIQ Admin';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Admin Workspace</span>
                <h1 class="mb-1">Platform Users</h1>
                <p class="text-muted mb-0">View registered customers, service providers, and administrators.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Admin Dashboard
                </a>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="card border-0 shadow-sm rounded-4 p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name or email..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <option value="customer" <?= $roleFilter === 'customer' ? 'selected' : '' ?>>Customer</option>
                        <option value="provider" <?= $roleFilter === 'provider' ? 'selected' : '' ?>>Service Provider</option>
                        <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrator</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
                <?php if ($search !== '' || $roleFilter !== ''): ?>
                    <div class="col-md-2">
                        <a href="users.php" class="btn btn-outline-secondary w-100">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Users Table Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Users (<?= count($users) ?>)</h2>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Registered On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="bi bi-person-x fs-3 d-block mb-1"></i>No users found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>#<?= (int)$u['id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </td>
                                    <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($u['role'] === 'admin'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Admin</span>
                                        <?php elseif ($u['role'] === 'provider'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Provider</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border">Customer</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($u['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
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
