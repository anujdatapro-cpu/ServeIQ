<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();
$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Query all customer requests to support direct navigation from workspace dropdown
$reqListStmt = $pdo->prepare('SELECT id, title, status, created_at FROM service_requests WHERE customer_id = :customer_id ORDER BY created_at DESC');
$reqListStmt->execute(['customer_id' => $customerId]);
$customerRequests = $reqListStmt->fetchAll();

if (!$requestId || $requestId < 1) {
    if (!empty($customerRequests)) {
        $requestId = (int)$customerRequests[0]['id'];
    }
}

$request = $requestId ? findCustomerRequest($pdo, (int)$requestId, $customerId) : null;

$message = (string)($_GET['status'] ?? '') === 'reanalyzed'
    ? 'ServiceDNA was analyzed again. Matching was refreshed and prior assessments were invalidated.'
    : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestId && $request) {
    requireValidCsrfToken();
    try {
        $pdo->beginTransaction();
        analyzeAndStoreServiceDna($pdo, (int)$requestId);
        refreshMatchingResultsForRequest($pdo, (int)$requestId);
        invalidateADCSAssessmentsForRequest($pdo, (int)$requestId);
        calculateADCSForRequest($pdo, (int)$requestId);
        $pdo->commit();
        header('Location: service_dna.php?id=' . (int)$requestId . '&status=reanalyzed');
        exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        $error = 'ServiceDNA could not be generated right now.';
    }
}
$dna = $requestId ? getServiceDnaForRequest($pdo, (int)$requestId) : null;
$pageTitle = 'Service Analysis | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>
<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Customer Workspace</span>
                <h1 class="mb-1">ServiceDNA Summary</h1>
                <p class="text-muted mb-0">A transparent hybrid interpretation of your original problem description.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($requestId): ?>
                    <a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Request
                    </a>
                <?php endif; ?>
                <a href="my_requests.php" class="btn btn-outline-secondary">My Requests</a>
            </div>
        </div>

        <?php if (!empty($customerRequests) && count($customerRequests) > 1): ?>
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-light">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <label for="requestSwitcher" class="form-label mb-0 small fw-bold text-nowrap"><i class="bi bi-arrow-left-right me-1"></i>Select Request:</label>
                    <select id="requestSwitcher" class="form-select form-select-sm" style="max-width: 400px;" onchange="window.location.href='service_dna.php?id=' + this.value">
                        <?php foreach ($customerRequests as $cr): ?>
                            <option value="<?= (int)$cr['id'] ?>" <?= (int)$cr['id'] === $requestId ? 'selected' : '' ?>>
                                #<?= (int)$cr['id'] ?> - <?= htmlspecialchars(mb_substr($cr['title'], 0, 45), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(requestStatusLabel($cr['status']), ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$request): ?>
            <div class="empty-state-card text-center p-5 card border-0 shadow-sm rounded-4">
                <i class="bi bi-fingerprint fs-1 text-primary mb-3"></i>
                <h2 class="h4">No Service Requests Found</h2>
                <p class="text-muted mb-3">You have not submitted any service requests yet. ServiceDNA is automatically generated when you submit a problem request.</p>
                <a href="create_request.php" class="btn btn-primary rounded-pill px-4">Describe Your Problem</a>
            </div>
        <?php else: ?>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- Original Problem Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <h2 class="h5">Original Problem Description</h2>
            <p class="request-description mb-0"><?= nl2br(htmlspecialchars($request['description'], ENT_QUOTES, 'UTF-8')) ?></p>
        </div>

        <?php if ($dna): ?>
            <!-- AI Diagnostic Summary Card -->
            <div class="card service-dna-panel shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <span class="section-kicker">Phase 10 · Intelligence Layer</span>
                        <h2 class="h4 mb-0">Diagnostic Interpretation</h2>
                    </div>
                    <div>
                        <?php if (!empty($dna['ai_used'])): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 fs-6">
                                <i class="bi bi-cpu me-1"></i>Enhancement layer active (<?= htmlspecialchars((string)($dna['ai_provider'] ?? 'local'), ENT_QUOTES, 'UTF-8') ?>)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border px-3 py-2 fs-6">
                                <i class="bi bi-shield-check me-1"></i>Deterministic Baseline Fallback
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($dna['disagreement_flag'])): ?>
                    <div class="alert alert-warning mb-4">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i>
                            <strong>Analysis signals differ</strong>
                        </div>
                        <p class="mb-0 small">
                            The enhancement layer and deterministic baseline produced different attributes. ServeIQ retained the deterministic result as the primary classification; review the original description and provider assessments when deciding what to do next.
                        </p>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-md-6">
                        <p class="mb-2"><strong>Detected Category:</strong> <?= htmlspecialchars($dna['category_name'] ?: 'Unclassified', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-2"><strong>Problem Type:</strong> <?= htmlspecialchars($dna['problem_type'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-2"><strong>Affected Entity:</strong> <?= htmlspecialchars($dna['affected_entity'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-2"><strong>Urgency Level:</strong> <?= htmlspecialchars(requestUrgencyLabel((string)$dna['user_urgency']), ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($dna['detected_urgency'])): ?>
                                <span class="text-muted">(text signal: <?= htmlspecialchars(requestUrgencyLabel((string)$dna['detected_urgency']), ENT_QUOTES, 'UTF-8') ?>)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-2"><strong>Symptoms Detected:</strong> <?= htmlspecialchars(implode(', ', $dna['symptoms']) ?: 'None detected', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-2"><strong>Operating Context:</strong> <?= htmlspecialchars(implode(', ', $dna['context']) ?: 'None detected', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-2"><strong>Suggested Service Types:</strong> <?= htmlspecialchars(implode(', ', $dna['possible_service_types']) ?: 'No specific suggestion', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-2">
                            <strong>Diagnostic Confidence:</strong> <span class="fs-5 fw-bold text-primary"><?= (int)$dna['confidence_score'] ?>%</span>
                            <?php if (!empty($dna['ai_used'])): ?>
                                <span class="small text-muted">(Deterministic: <?= (int)($dna['deterministic_confidence'] ?? $dna['confidence_score']) ?>%, AI: <?= (int)($dna['ai_confidence'] ?? 0) ?>%)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Follow-up Diagnostic Questions -->
                <div class="mb-4">
                    <h3 class="h6 text-uppercase text-muted mb-2">Helpful Diagnostic Questions for Provider</h3>
                    <?php if (!empty($dna['follow_up_questions'])): ?>
                        <div class="p-3 bg-light rounded-3 border">
                            <p class="small text-muted mb-2">
                                Providing answers to these questions when communicating with your service provider helps them prepare the right tools and replacement parts:
                            </p>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($dna['follow_up_questions'] as $q): ?>
                                    <li class="mb-1 text-dark"><strong><?= htmlspecialchars((string)$q, ENT_QUOTES, 'UTF-8') ?></strong></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <p class="small text-muted mb-0">
                            <i class="bi bi-info-circle me-1"></i>No follow-up questions were generated for this description.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Explainability / Evidence Accordion -->
                <?php if (!empty($dna['evidence'])): ?>
                    <details class="mb-3">
                        <summary class="text-primary fw-medium cursor-pointer">
                            <i class="bi bi-info-circle me-1"></i>View Diagnostic Evidence & Extraction Reasoning
                        </summary>
                        <div class="bg-light p-3 rounded-3 border mt-2">
                            <pre class="small mb-0 text-secondary" style="white-space: pre-wrap;"><?= htmlspecialchars(json_encode($dna['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                        </div>
                    </details>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4 pt-3 border-top">
                    <p class="small text-muted mb-0">
                        <strong>Engine:</strong> <?= htmlspecialchars((string)($dna['engine_version'] ?? 'rule-based-1.0'), ENT_QUOTES, 'UTF-8') ?>
                        (<?= htmlspecialchars((string)$dna['analysis_method'], ENT_QUOTES, 'UTF-8') ?> v<?= (int)$dna['version'] ?>).
                        Analysis is advisory and does not alter your original request text.
                    </p>
                    <form method="POST">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Re-analyze ServiceDNA
                        </button>
                        <?= csrfField() ?>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No ServiceDNA is available yet.</div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
