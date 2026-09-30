<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../config/database.php';

requireAdmin();
$pdo = getDatabaseConnection();
$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$selected = null;
if ($requestId && $requestId > 0) {
    $selectedStmt = $pdo->prepare(
        'SELECT r.id, r.title, ar.assessment_count, ar.consensus_score, ar.consensus_status,
                ar.consensus_data, ar.disagreement_data, ar.outlier_data, ar.analysis_method, ar.version, ar.updated_at
         FROM service_requests r
         LEFT JOIN adcs_results ar ON ar.request_id = r.id
         WHERE r.id = :request_id LIMIT 1'
    );
    $selectedStmt->execute(['request_id' => (int)$requestId]);
    $selected = $selectedStmt->fetch() ?: null;
    if ($selected) {
        foreach (['consensus_data', 'disagreement_data', 'outlier_data'] as $field) {
            $selected[$field] = json_decode((string)($selected[$field] ?? ''), true) ?: [];
        }
    }
}
$listStmt = $pdo->query(
    'SELECT r.id, r.title, COALESCE(ar.assessment_count, 0) AS assessment_count,
            ar.consensus_score, ar.consensus_status, ar.analysis_method, ar.updated_at
     FROM service_requests r
     LEFT JOIN adcs_results ar ON ar.request_id = r.id
     ORDER BY r.created_at DESC LIMIT 100'
);
$requests = $listStmt->fetchAll();

$pageTitle = 'ADCS Diagnostics | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>
<main class="dashboard-page"><div class="container-fluid"><div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><span class="section-kicker">Admin Workspace</span><h1 class="mb-1">ADCS Diagnostics</h1><p class="text-muted mb-0">Inspect agreement, disagreement, and outlier metrics from provider assessments.</p></div><div class="d-flex gap-2"><a href="dashboard.php" class="btn btn-outline-secondary">Admin Dashboard</a><form method="POST" action="../logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger">Log Out</button></form></div></div>
<?php if ($requestId && !$selected): ?><div class="alert alert-warning">The selected request was not found.</div><?php endif; ?>
<?php if ($selected): ?><div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4"><div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><span class="section-kicker">Request #<?= (int)$selected['id'] ?></span><h2 class="h4 mb-1"><?= htmlspecialchars($selected['title'], ENT_QUOTES, 'UTF-8') ?></h2></div><a href="adcs.php" class="btn btn-outline-secondary">Clear Selection</a></div><hr><?php if ($selected['consensus_status'] === null): ?><p class="text-muted mb-0">No ADCS result has been calculated.</p><?php else: ?><div class="row g-3"><div class="col-md-3"><strong>Assessments:</strong><br><?= (int)$selected['assessment_count'] ?></div><div class="col-md-3"><strong>Consensus score:</strong><br><?= htmlspecialchars((string)$selected['consensus_score'], ENT_QUOTES, 'UTF-8') ?>/100</div><div class="col-md-3"><strong>Status:</strong><br><?= htmlspecialchars(ucwords(str_replace('_', ' ', $selected['consensus_status'])), ENT_QUOTES, 'UTF-8') ?></div><div class="col-md-3"><strong>Method:</strong><br><?= htmlspecialchars((string)$selected['analysis_method'], ENT_QUOTES, 'UTF-8') ?> v<?= (int)$selected['version'] ?></div></div><hr><h3 class="h6">Consensus data</h3><pre class="small"><?= htmlspecialchars(json_encode($selected['consensus_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre><h3 class="h6">Disagreement data</h3><pre class="small"><?= htmlspecialchars(json_encode($selected['disagreement_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre><h3 class="h6">Outlier data</h3><pre class="small mb-0"><?= htmlspecialchars(json_encode($selected['outlier_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre><?php endif; ?></div><?php endif; ?>
<div class="card shadow-sm border-0 rounded-4 p-4"><h2 class="h5">Recent ADCS Results</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Request</th><th>Assessments</th><th>Score</th><th>Status</th><th>Method</th><th></th></tr></thead><tbody><?php if (!$requests): ?><tr><td colspan="6" class="text-muted">No service requests found.</td></tr><?php else: ?><?php foreach ($requests as $item): ?><tr><td>#<?= (int)$item['id'] ?> · <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></td><td><?= (int)$item['assessment_count'] ?></td><td><?= $item['consensus_score'] === null ? 'Not calculated' : htmlspecialchars((string)$item['consensus_score'], ENT_QUOTES, 'UTF-8') . '/100' ?></td><td><?= $item['consensus_status'] === null ? 'Not calculated' : htmlspecialchars(ucwords(str_replace('_', ' ', $item['consensus_status'])), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($item['analysis_method'] ?: 'Not calculated', ENT_QUOTES, 'UTF-8') ?></td><td><a href="adcs.php?id=<?= (int)$item['id'] ?>" class="btn btn-sm btn-outline-primary">Inspect</a></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div></div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
