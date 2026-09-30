<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    http_response_code(404);
    exit('Request not found.');
}

$pdo = getDatabaseConnection();
$request = findCustomerRequest($pdo, (int)$requestId, (int)getUserId());
if (!$request) {
    http_response_code(404);
    exit('Request not found.');
}

$adcs = getADCSForRequest($pdo, (int)$requestId, (int)getUserId());
if (!$adcs) {
    $adcs = calculateADCSForRequest($pdo, (int)$requestId);
}

function adcsDisplayValues(array $field): string
{
    $values = $field['consensus_values'] ?? (($field['consensus_value'] ?? null) !== null ? [$field['consensus_value']] : []);
    return htmlspecialchars(implode(', ', $values) ?: 'No common value', ENT_QUOTES, 'UTF-8');
}

function adcsFieldLabel(string $field): string
{
    return match ($field) {
        'problem_type' => 'Problem Type',
        'affected_entity' => 'Affected Entity',
        'suggested_service_types' => 'Suggested Service Types',
        'estimated_severity' => 'Estimated Severity',
        default => ucfirst(str_replace('_', ' ', $field)),
    };
}

$pageTitle = 'Adaptive Diagnostic Consensus | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom">
            <div>
                <span class="section-kicker">Phase 7 · Adaptive Diagnostic Consensus System (ADCS)</span>
                <h1 class="mb-1">Multi-Provider Consensus Analysis</h1>
                <p class="text-muted mb-0">Empirical aggregation of independent preliminary provider assessments.</p>
            </div>
            <a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Request
            </a>
        </div>

        <!-- Request Reference Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-light">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span class="text-muted small text-uppercase fw-bold">Service Request Target</span>
                    <h2 class="h5 mb-0"><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <div class="small text-muted">
                    Assessments: <strong><?= (int)$adcs['assessment_count'] ?></strong> &bull; Method: <strong><?= htmlspecialchars((string)$adcs['analysis_method'], ENT_QUOTES, 'UTF-8') ?> v<?= (int)$adcs['version'] ?></strong>
                </div>
            </div>
        </div>

        <!-- PART I: 3-STAGE VISUAL CONSENSUS ARCHITECTURE -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <span class="section-kicker mb-2">Consensus Architecture</span>
            <div class="row g-3 text-center">
                <div class="col-md-4">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="badge bg-primary text-white mb-2">Stage 1</div>
                        <h3 class="h6 mb-1">Independent Assessments</h3>
                        <p class="small text-muted mb-0"><?= (int)$adcs['assessment_count'] ?> independent provider submissions received without mutual visibility.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="badge bg-primary text-white mb-2">Stage 2</div>
                        <h3 class="h6 mb-1">Empirical Consensus</h3>
                        <p class="small text-muted mb-0">Statistical agreement scoring across problem type, severity, and suggested services.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="badge bg-success text-white mb-2">Stage 3</div>
                        <h3 class="h6 mb-1">Consensus Decision</h3>
                        <p class="small text-muted mb-0"><?= (int)$adcs['consensus_score'] ?>/100 score &bull; <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$adcs['consensus_status'])), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ((int)$adcs['assessment_count'] === 0): ?>
            <div class="empty-state-saas mb-4">
                <div class="empty-state-icon"><i class="bi bi-hourglass-split"></i></div>
                <h3 class="empty-state-title">Awaiting Provider Assessments</h3>
                <p class="empty-state-desc">Independent provider diagnostic evaluations have not yet been submitted for this request. Once providers inspect your problem description, consensus metrics will compile automatically.</p>
            </div>
        <?php elseif ((int)$adcs['assessment_count'] === 1): ?>
            <div class="alert alert-warning rounded-4 mb-4 border-0 shadow-sm">
                <i class="bi bi-info-circle-fill me-2"></i>
                Only 1 provider assessment is currently available. A single assessment provides helpful preliminary guidance, but requires at least 2 independent assessments to establish multi-provider statistical consensus.
            </div>
        <?php endif; ?>

        <!-- Consensus Metrics Breakdown -->
        <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <span class="section-kicker">Consensus Synthesis</span>
                    <h2 class="h4 mb-0"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$adcs['consensus_status'])), ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <div class="text-end">
                    <span class="fs-3 fw-bold text-primary"><?= htmlspecialchars((string)$adcs['consensus_score'], ENT_QUOTES, 'UTF-8') ?></span><span class="fs-5 text-muted">/100</span>
                    <div class="small text-muted">Consensus Agreement Score</div>
                </div>
            </div>
            
            <div class="confidence-meter mb-4">
                <div class="confidence-meter-fill <?= (int)$adcs['consensus_score'] >= 75 ? 'high' : ((int)$adcs['consensus_score'] >= 45 ? 'medium' : 'low') ?>" style="width: <?= min(100, (int)$adcs['consensus_score']) ?>%;"></div>
            </div>

            <hr class="my-4">

            <!-- Field-Level Consensus -->
            <h3 class="h6 text-uppercase text-muted mb-3">Diagnostic Dimension Analysis</h3>
            <div class="row g-3">
                <?php foreach ($adcs['consensus_data'] as $fieldName => $field): ?>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                <strong><?= htmlspecialchars(adcsFieldLabel($fieldName), ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($field['status'] === 'consensus'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-check2-circle me-1"></i>Consensus
                                    </span>
                                <?php elseif ($field['status'] === 'disagreement'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                        <i class="bi bi-exclamation-circle me-1"></i>Divergence
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                        <i class="bi bi-dash-circle me-1"></i>Partial Agreement
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2 text-dark fw-medium">
                                <?= adcsDisplayValues($field) ?>
                            </div>
                            <?php if (!empty($field['values'])): ?>
                                <div class="small text-muted">
                                    <?php foreach ($field['values'] as $value): ?>
                                        <span class="d-inline-block me-2">
                                            &bull; <?= htmlspecialchars((string)$value['value'], ENT_QUOTES, 'UTF-8') ?>: <?= (int)$value['count'] ?>/<?= (int)$adcs['assessment_count'] ?> (<?= (int)round((float)$value['ratio'] * 100) ?>%)
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Neutral Divergence & Outlier Analysis -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                    <span class="section-kicker text-muted">Field Divergence Analysis</span>
                    <h3 class="h5 mb-2">Divergent Perspectives</h3>
                    <?php if ((int)$adcs['disagreement_data']['count'] === 0): ?>
                        <p class="text-muted small mb-0"><i class="bi bi-check-circle text-success me-1"></i>All evaluated dimensions showed strong provider convergence.</p>
                    <?php else: ?>
                        <p class="small text-muted mb-2">Providers offered distinct diagnostic interpretations on the following attributes:</p>
                        <ul class="small mb-0 ps-3">
                            <?php foreach ($adcs['disagreement_data']['fields'] as $fieldName => $field): ?>
                                <li class="text-secondary mb-1"><strong><?= htmlspecialchars(adcsFieldLabel($fieldName), ENT_QUOTES, 'UTF-8') ?></strong> presents differing preliminary views.</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                    <span class="section-kicker text-muted">Assessment Outliers</span>
                    <h3 class="h5 mb-2">Statistical Divergence</h3>
                    <?php if ((int)$adcs['outlier_data']['count'] === 0): ?>
                        <p class="text-muted small mb-0"><i class="bi bi-check-circle text-success me-1"></i>No statistical outliers detected across submitted assessments.</p>
                    <?php else: ?>
                        <p class="small text-muted mb-2">The following provider opinions diverged from the majority cluster (describes statistical variance, not accuracy):</p>
                        <ul class="small mb-0 ps-3">
                            <?php foreach ($adcs['outlier_data']['providers'] as $outlier): ?>
                                <li class="text-secondary mb-1">
                                    <strong><?= htmlspecialchars($outlier['provider_name'], ENT_QUOTES, 'UTF-8') ?></strong> diverged on: <?= htmlspecialchars(implode(', ', $outlier['differences']), ENT_QUOTES, 'UTF-8') ?>.
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Individual Provider Assessment Cards -->
        <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <span class="section-kicker">Transparent Evidence</span>
                    <h3 class="h5 mb-0">Individual Provider Submissions</h3>
                </div>
                <span class="small text-muted">Independent Records</span>
            </div>

            <?php if (empty($adcs['assessments'])): ?>
                <p class="text-muted small mb-0">No provider assessments have been recorded yet.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($adcs['assessments'] as $assessment): ?>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 bg-light h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h4 class="h6 mb-0"><?= htmlspecialchars($assessment['business_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                        <?= (int)$assessment['assessment_confidence'] ?>% Confidence
                                    </span>
                                </div>
                                <div class="small mb-1"><strong>Inferred Problem:</strong> <?= htmlspecialchars($assessment['problem_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small mb-1"><strong>Identified Symptoms:</strong> <?= htmlspecialchars(implode(', ', $assessment['symptoms']) ?: 'None', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small mb-1"><strong>Suggested Services:</strong> <?= htmlspecialchars(implode(', ', $assessment['suggested_service_types']) ?: 'None', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-muted">
                                    Severity: <strong><?= htmlspecialchars(ucfirst($assessment['estimated_severity']), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
