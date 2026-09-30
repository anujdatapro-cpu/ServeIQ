<?php

declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDatabaseConnection();
$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$selectedRequest = null;
$selectedDna = null;

if ($requestId !== false && $requestId !== null && $requestId > 0) {
    $requestStmt = $pdo->prepare(
        'SELECT r.id, r.title, r.description, r.created_at, r.updated_at, u.name AS customer_name
         FROM service_requests r
         INNER JOIN users u ON u.id = r.customer_id
         WHERE r.id = :request_id
         LIMIT 1'
    );
    $requestStmt->execute(['request_id' => (int)$requestId]);
    $selectedRequest = $requestStmt->fetch() ?: null;

    if ($selectedRequest) {
        $selectedDna = getServiceDnaForRequest($pdo, (int)$requestId);
    }
}

$listStmt = $pdo->query(
    'SELECT r.id, r.title, r.created_at, f.analysis_method, f.version, f.confidence_score
     FROM service_requests r
     LEFT JOIN problem_fingerprints f ON f.request_id = r.id
     ORDER BY r.created_at DESC
     LIMIT 100'
);
$requests = $listStmt->fetchAll();

function adminServiceDnaList(array $values): string
{
    return htmlspecialchars(implode(', ', array_map('strval', $values)) ?: 'None detected', ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'ServiceDNA Diagnostics | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Admin Workspace</span>
                <h1 class="mb-1">ServiceDNA Diagnostics</h1>
                <p class="text-muted mb-0">Inspect derived analysis without changing the customer's original description.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">Admin Dashboard</a>
                <form method="POST" action="../logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger">Log Out</button></form>
            </div>
        </div>

        <?php if ($requestId !== null && $requestId !== false && !$selectedRequest): ?>
            <div class="alert alert-warning">The selected request was not found.</div>
        <?php endif; ?>

        <?php if ($selectedRequest): ?>
            <section class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <span class="section-kicker">Request #<?= (int)$selectedRequest['id'] ?></span>
                        <h2 class="h4 mb-1"><?= htmlspecialchars($selectedRequest['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-muted mb-0">Customer: <?= htmlspecialchars($selectedRequest['customer_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <a href="service_dna.php" class="btn btn-outline-secondary">Clear Selection</a>
                </div>
                <hr>
                <h3 class="h6 text-uppercase text-muted">Original Description</h3>
                <p class="request-description"><?= nl2br(htmlspecialchars($selectedRequest['description'], ENT_QUOTES, 'UTF-8')) ?></p>

                <?php if ($selectedDna): ?>
                    <h3 class="h6 text-uppercase text-muted mt-4">Phase 10 · ServiceDNA Results</h3>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Category:</strong> <?= htmlspecialchars($selectedDna['category_name'] ?: 'Unclassified', ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mb-2"><strong>Problem type:</strong> <?= htmlspecialchars($selectedDna['problem_type'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mb-2"><strong>Affected entity:</strong> <?= htmlspecialchars($selectedDna['affected_entity'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mb-2"><strong>Urgency:</strong> <?= htmlspecialchars((string)$selectedDna['user_urgency'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mb-0"><strong>Confidence:</strong> <?= (int)$selectedDna['confidence_score'] ?>%
                                <?php if (!empty($selectedDna['ai_used'])): ?>
                                    <span class="text-muted small">(Det: <?= (int)($selectedDna['deterministic_confidence'] ?? $selectedDna['confidence_score']) ?>%, AI: <?= (int)($selectedDna['ai_confidence'] ?? 0) ?>%)</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Symptoms:</strong> <?= adminServiceDnaList($selectedDna['symptoms']) ?></p>
                            <p class="mb-2"><strong>Context:</strong> <?= adminServiceDnaList($selectedDna['context']) ?></p>
                            <p class="mb-2"><strong>Keywords:</strong> <?= adminServiceDnaList($selectedDna['keywords']) ?></p>
                            <p class="mb-2"><strong>Possible services:</strong> <?= adminServiceDnaList($selectedDna['possible_service_types']) ?></p>
                            <p class="mb-0"><strong>Engine:</strong> <?= htmlspecialchars((string)($selectedDna['engine_version'] ?? 'rule-based-1.0'), ENT_QUOTES, 'UTF-8') ?> &mdash; <?= htmlspecialchars((string)$selectedDna['analysis_method'], ENT_QUOTES, 'UTF-8') ?> v<?= (int)$selectedDna['version'] ?></p>
                        </div>
                    </div>

                    <!-- Phase 10: AI Diagnostics Admin Panel -->
                    <div class="mt-4 p-3 rounded-3 border <?= !empty($selectedDna['ai_used']) ? 'bg-success-subtle border-success-subtle' : 'bg-light border-secondary-subtle' ?>">
                        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                            <?php if (!empty($selectedDna['ai_used'])): ?>
                                <i class="bi bi-cpu text-success fs-5"></i>
                                <strong class="text-success">Phase 10 AI Enhancement Active</strong>
                                <span class="badge bg-success ms-1"><?= htmlspecialchars((string)($selectedDna['ai_provider'] ?? 'local'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <i class="bi bi-shield-check text-secondary fs-5"></i>
                                <strong class="text-secondary">Deterministic Baseline (Fallback)</strong>
                                <span class="badge bg-secondary ms-1"><?= htmlspecialchars((string)($selectedDna['ai_status'] ?? 'none'), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <?php if (!empty($selectedDna['disagreement_flag'])): ?>
                                <span class="badge bg-warning text-dark ms-auto">&#9888; Disagreement Resolved</span>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success ms-auto">&#10003; Consensus</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($selectedDna['follow_up_questions'])): ?>
                            <p class="small mb-1"><strong>Diagnostic Follow-up Questions Generated:</strong></p>
                            <ol class="small mb-0">
                                <?php foreach ($selectedDna['follow_up_questions'] as $q): ?>
                                    <li><?= htmlspecialchars((string)$q, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <p class="small mb-0 text-muted"><i class="bi bi-check-circle me-1"></i>No follow-up questions required — description is sufficiently detailed.</p>
                        <?php endif; ?>
                    </div>

                    <details class="mt-4">
                        <summary class="fw-medium">Extraction Evidence &amp; AI Reasoning</summary>
                        <pre class="small mt-3 bg-light p-3 rounded border" style="white-space:pre-wrap;"><?= htmlspecialchars(json_encode($selectedDna['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                    </details>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No ServiceDNA record exists for this request.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card shadow-sm border-0 rounded-4 p-4">
            <h2 class="h5">Recent Service Requests</h2>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Title</th>
                            <th>Created</th>
                            <th>Analysis method</th>
                            <th>Confidence</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr><td colspan="6" class="text-muted">No service requests found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td>#<?= (int)$request['id'] ?></td>
                                    <td><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($request['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($request['analysis_method'] ?: 'Not analyzed', ENT_QUOTES, 'UTF-8') ?><?php if ($request['version'] !== null): ?> v<?= (int)$request['version'] ?><?php endif; ?></td>
                                    <td><?= $request['confidence_score'] === null ? 'Not analyzed' : (int)$request['confidence_score'] . '%' ?></td>
                                    <td><a href="service_dna.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-outline-primary">Inspect</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
