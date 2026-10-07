<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../config/database.php';
requireAdmin();

$pdo = getDatabaseConnection();
$actionFilter = trim((string)($_GET['action'] ?? ''));
$entityFilter = trim((string)($_GET['entity'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 50;
$offset = ($page - 1) * $pageSize;
$errors = [];
$rows = [];
$total = 0;

try {
    $where = [];
    $params = [];
    if ($actionFilter !== '') {
        $where[] = 'a.action LIKE :action';
        $params['action'] = '%' . substr($actionFilter, 0, 100) . '%';
    }
    if ($entityFilter !== '') {
        $where[] = 'a.entity_type = :entity';
        $params['entity'] = substr($entityFilter, 0, 80);
    }
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pageCount = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;

    $listStmt = $pdo->prepare(
        'SELECT a.*, u.name AS actor_name
         FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id' . $whereSql . '
         ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) $listStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
    $listStmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll();
} catch (Throwable $e) {
    error_log('ServeIQ audit log page failed: ' . $e->getMessage());
    $errors[] = 'Audit history is unavailable. Apply the security audit migration and try again.';
}

$pageTitle = 'Audit Logs | ServeIQ Admin';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>
<main class="dashboard-page">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div><span class="section-kicker">Admin Workspace</span><h1 class="mb-1">Audit Logs</h1><p class="text-muted mb-0">Recent security and business events recorded by ServeIQ.</p></div>
            <a href="dashboard.php" class="btn btn-outline-secondary">Admin Dashboard</a>
        </div>
        <?php foreach ($errors as $error): ?><div class="alert alert-warning" role="status"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <form method="GET" class="card border-0 shadow-sm rounded-4 p-3 mb-4 row g-3 align-items-end">
            <div class="col-md-5"><label for="action" class="form-label">Action</label><input id="action" name="action" class="form-control" maxlength="100" value="<?= htmlspecialchars($actionFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="Filter by action"></div>
            <div class="col-md-5"><label for="entity" class="form-label">Entity type</label><input id="entity" name="entity" class="form-control" maxlength="80" value="<?= htmlspecialchars($entityFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="Filter by entity"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
        </form>
        <?php if ($errors === []): ?>
            <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Events</h2><span class="text-muted small"><?= number_format($total) ?> total</span></div>
                <?php if ($rows === []): ?><div class="text-center py-5"><i class="bi bi-journal-check fs-1 text-muted"></i><h3 class="h5 mt-3">No audit events found</h3><p class="text-muted mb-0">Events will appear here as users and administrators perform actions.</p></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead><tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr><td class="text-nowrap"><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['actor_name'] ?? 'System / deleted user'), ENT_QUOTES, 'UTF-8') ?><small class="d-block text-muted"><?= htmlspecialchars((string)($row['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td><td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$row['action'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?= htmlspecialchars((string)($row['entity_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?><?= $row['entity_id'] !== null ? ' #' . (int)$row['entity_id'] : '' ?></td><td><?= htmlspecialchars((string)($row['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td><td><details><summary>View</summary><pre class="small text-wrap mt-2 mb-0"><?= htmlspecialchars(json_encode(['old' => $row['old_data'] ? json_decode((string)$row['old_data'], true) : null, 'new' => $row['new_data'] ? json_decode((string)$row['new_data'], true) : null, 'agent' => $row['user_agent']], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?></pre></details></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php if ($pageCount > 1): ?><nav aria-label="Audit log pages"><ul class="pagination justify-content-end mb-0"><?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="?<?= http_build_query(['action' => $actionFilter, 'entity' => $entityFilter, 'page' => $page - 1]) ?>" aria-label="Previous">Previous</a></li><?php endif; ?><?php for ($pageNumber = max(1, $page - 3); $pageNumber <= min($pageCount, $page + 3); $pageNumber++): ?><li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['action' => $actionFilter, 'entity' => $entityFilter, 'page' => $pageNumber]) ?>"><?= $pageNumber ?></a></li><?php endfor; ?><?php if ($page < $pageCount): ?><li class="page-item"><a class="page-link" href="?<?= http_build_query(['action' => $actionFilter, 'entity' => $entityFilter, 'page' => $page + 1]) ?>" aria-label="Next">Next</a></li><?php endif; ?></ul></nav><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
