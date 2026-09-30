<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireProvider();

$pdo = getDatabaseConnection();
$userId = (int)getUserId();
$profileStmt = $pdo->prepare('SELECT id, business_name FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
$profileStmt->execute(['user_id' => $userId]);
$providerProfile = $profileStmt->fetch();
if (!$providerProfile) {
    http_response_code(403);
    exit('Complete your provider profile before submitting assessments.');
}
$providerId = (int)$providerProfile['id'];
$errors = [];
$successMessage = match ((string)($_GET['status'] ?? '')) {
    'withdrawn' => 'Your assessment was withdrawn from the consensus calculation.',
    'saved' => 'Your assessment was saved and ADCS was recalculated.',
    default => '',
};
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $action = (string)($_POST['action'] ?? 'save');
    if (!$requestId || $requestId < 1) {
        $errors[] = 'A valid service request is required.';
    }
    $matchingResultId = $requestId ? providerIsEligibleForADCS($pdo, (int)$requestId, $providerId) : null;
    if (!$errors && $matchingResultId === null) {
        $errors[] = 'You are not eligible to assess this request.';
    }
    if (!$errors && $action === 'withdraw') {
        try {
            $pdo->beginTransaction();
            $withdrawStmt = $pdo->prepare('UPDATE provider_assessments SET status = \'withdrawn\' WHERE request_id = :request_id AND provider_id = :provider_id AND status IN (\'submitted\', \'updated\')');
            $withdrawStmt->execute(['request_id' => (int)$requestId, 'provider_id' => $providerId]);
            calculateADCSForRequest($pdo, (int)$requestId);
            $pdo->commit();
            header('Location: assessments.php?request_id=' . (int)$requestId . '&status=withdrawn');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $errors[] = 'The assessment could not be withdrawn. Please try again.';
        }
    } elseif (!$errors) {
        $problemType = trim((string)($_POST['problem_type'] ?? ''));
        $affectedEntity = trim((string)($_POST['affected_entity'] ?? ''));
        $symptoms = assessmentList((string)($_POST['symptoms'] ?? ''));
        $services = assessmentList((string)($_POST['suggested_service_types'] ?? ''));
        $urgency = (string)($_POST['urgency'] ?? 'medium');
        $severity = (string)($_POST['estimated_severity'] ?? 'medium');
        $confidence = filter_var($_POST['assessment_confidence'] ?? null, FILTER_VALIDATE_INT);
        $notes = trim((string)($_POST['assessment_notes'] ?? ''));
        if ($problemType === '' || mb_strlen($problemType) > 120) $errors[] = 'Problem type is required and must be 120 characters or fewer.';
        if (mb_strlen($affectedEntity) > 120) $errors[] = 'Affected entity must be 120 characters or fewer.';
        if ($symptoms === [] || count($symptoms) > 10) $errors[] = 'Provide between 1 and 10 symptoms.';
        if ($services === [] || count($services) > 10) $errors[] = 'Provide between 1 and 10 suggested service types.';
        if (!in_array($urgency, ['low', 'medium', 'high', 'emergency'], true)) $errors[] = 'Select a valid urgency.';
        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) $errors[] = 'Select a valid severity.';
        if ($confidence === false || $confidence < 0 || $confidence > 100) $errors[] = 'Assessment confidence must be between 0 and 100.';
        if (mb_strlen($notes) > 3000) $errors[] = 'Assessment notes must be 3000 characters or fewer.';
        if (!$errors) {
            $existingStmt = $pdo->prepare('SELECT id FROM provider_assessments WHERE request_id = :request_id AND provider_id = :provider_id LIMIT 1');
            $existingStmt->execute(['request_id' => (int)$requestId, 'provider_id' => $providerId]);
            $existing = $existingStmt->fetch();
            try {
                $pdo->beginTransaction();
                if ($existing) {
                    $saveStmt = $pdo->prepare(
                        'UPDATE provider_assessments SET matching_result_id = :matching_result_id, problem_type = :problem_type, affected_entity = :affected_entity, symptoms = :symptoms, suggested_service_types = :suggested_service_types, urgency = :urgency, estimated_severity = :estimated_severity, assessment_notes = :assessment_notes, assessment_confidence = :assessment_confidence, status = \'updated\' WHERE id = :id AND request_id = :request_id AND provider_id = :provider_id'
                    );
                    $saveParams = ['id' => (int)$existing['id'], 'request_id' => (int)$requestId, 'provider_id' => $providerId];
                } else {
                    $saveStmt = $pdo->prepare(
                        'INSERT INTO provider_assessments (request_id, provider_id, matching_result_id, problem_type, affected_entity, symptoms, suggested_service_types, urgency, estimated_severity, assessment_notes, assessment_confidence, status) VALUES (:request_id, :provider_id, :matching_result_id, :problem_type, :affected_entity, :symptoms, :suggested_service_types, :urgency, :estimated_severity, :assessment_notes, :assessment_confidence, \'submitted\')'
                    );
                    $saveParams = ['request_id' => (int)$requestId, 'provider_id' => $providerId, 'matching_result_id' => $matchingResultId];
                }
                $saveParams += ['matching_result_id' => $matchingResultId, 'problem_type' => $problemType, 'affected_entity' => $affectedEntity === '' ? null : $affectedEntity, 'symptoms' => json_encode($symptoms, JSON_THROW_ON_ERROR), 'suggested_service_types' => json_encode($services, JSON_THROW_ON_ERROR), 'urgency' => $urgency, 'estimated_severity' => $severity, 'assessment_notes' => $notes === '' ? null : $notes, 'assessment_confidence' => $confidence];
                $saveStmt->execute($saveParams);
                calculateADCSForRequest($pdo, (int)$requestId);
                $pdo->commit();
                header('Location: assessments.php?request_id=' . (int)$requestId . '&status=saved');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($exception->getMessage());
                $errors[] = 'The assessment could not be saved. Please try again.';
            }
        }
    }
}

$eligibleStmt = $pdo->prepare(
    'SELECT mr.request_id, r.title, r.description, r.city, r.area, r.created_at,
            mr.match_score, pa.status AS assessment_status
     FROM matching_results mr
     INNER JOIN service_requests r ON r.id = mr.request_id
     LEFT JOIN provider_assessments pa ON pa.request_id = mr.request_id AND pa.provider_id = :provider_id
     WHERE mr.provider_id = :provider_id_match AND mr.matching_method = \'weighted_rule_based_v1\' AND mr.version = 1
       AND r.status NOT IN (\'cancelled\', \'completed\')
     ORDER BY mr.match_score DESC, r.created_at DESC'
);
$eligibleStmt->execute(['provider_id' => $providerId, 'provider_id_match' => $providerId]);
$eligibleRequests = $eligibleStmt->fetchAll();
$selectedRequest = null;
$ownAssessment = null;
$selectedDna = null;
if ($requestId && $requestId > 0) {
    $eligibleCheck = providerIsEligibleForADCS($pdo, (int)$requestId, $providerId);
    if ($eligibleCheck === null) {
        $errors[] = 'You are not eligible to assess this request.';
    } else {
        $requestStmt = $pdo->prepare('SELECT r.*, c.category_name FROM service_requests r LEFT JOIN service_categories c ON c.id = r.category_id WHERE r.id = :request_id AND r.status NOT IN (\'cancelled\', \'completed\') LIMIT 1');
        $requestStmt->execute(['request_id' => (int)$requestId]);
        $selectedRequest = $requestStmt->fetch() ?: null;
        if ($selectedRequest) {
            $selectedDna = getServiceDnaForRequest($pdo, (int)$requestId);
            $ownStmt = $pdo->prepare('SELECT * FROM provider_assessments WHERE request_id = :request_id AND provider_id = :provider_id LIMIT 1');
            $ownStmt->execute(['request_id' => (int)$requestId, 'provider_id' => $providerId]);
            $ownAssessment = $ownStmt->fetch() ?: null;
        }
    }
}

function assessmentList(string $input): array
{
    $parts = preg_split('/[,\r\n]+/', $input) ?: [];
    $values = [];
    foreach ($parts as $part) {
        $value = trim($part);
        if ($value !== '' && !in_array($value, $values, true)) $values[] = $value;
    }
    return $values;
}

$pageTitle = 'Provider Assessments | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page"><div class="container"><div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4"><div><span class="section-kicker">Provider Workspace</span><h1 class="mb-1">Independent Assessments</h1><p class="text-muted mb-0">Submit your own preliminary assessment before seeing any consensus.</p></div><a href="dashboard.php" class="btn btn-outline-secondary">Provider Dashboard</a></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($successMessage): ?><div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($selectedRequest): ?><div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4"><h2 class="h4"><?= htmlspecialchars($selectedRequest['title'], ENT_QUOTES, 'UTF-8') ?></h2><p class="request-description"><?= nl2br(htmlspecialchars($selectedRequest['description'], ENT_QUOTES, 'UTF-8')) ?></p><?php if ($selectedDna): ?><p class="small text-muted">ServiceDNA context: <?= htmlspecialchars($selectedDna['problem_type'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(implode(', ', $selectedDna['symptoms']) ?: 'No symptoms detected', ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><p class="small text-muted">Your assessment remains independent. Other providers' assessments and aggregate results are not shown on this page.</p></div>
<div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4"><h2 class="h5">Preliminary Assessment</h2><p class="text-muted">Final diagnosis may require physical inspection.</p><form method="POST" class="row g-3"><?= csrfField() ?><input type="hidden" name="request_id" value="<?= (int)$requestId ?>"><div class="col-md-6"><label for="problem_type" class="form-label">Problem Type</label><input id="problem_type" name="problem_type" maxlength="120" class="form-control" value="<?= htmlspecialchars((string)($ownAssessment['problem_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></div><div class="col-md-6"><label for="affected_entity" class="form-label">Affected Entity</label><input id="affected_entity" name="affected_entity" maxlength="120" class="form-control" value="<?= htmlspecialchars((string)($ownAssessment['affected_entity'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div><div class="col-md-6"><label for="symptoms" class="form-label">Symptoms</label><textarea id="symptoms" name="symptoms" rows="3" class="form-control" required><?= htmlspecialchars(implode(', ', adcsDecodeList($ownAssessment['symptoms'] ?? [])), ENT_QUOTES, 'UTF-8') ?></textarea><small class="text-muted">Separate multiple symptoms with commas.</small></div><div class="col-md-6"><label for="suggested_service_types" class="form-label">Suggested Service Types</label><textarea id="suggested_service_types" name="suggested_service_types" rows="3" class="form-control" required><?= htmlspecialchars(implode(', ', adcsDecodeList($ownAssessment['suggested_service_types'] ?? [])), ENT_QUOTES, 'UTF-8') ?></textarea><small class="text-muted">Separate multiple service types with commas.</small></div><div class="col-md-4"><label for="urgency" class="form-label">Urgency</label><select id="urgency" name="urgency" class="form-select"><?php foreach (['low', 'medium', 'high', 'emergency'] as $value): ?><option value="<?= $value ?>" <?= ($ownAssessment['urgency'] ?? 'medium') === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="estimated_severity" class="form-label">Estimated Severity</label><select id="estimated_severity" name="estimated_severity" class="form-select"><?php foreach (['low', 'medium', 'high', 'critical'] as $value): ?><option value="<?= $value ?>" <?= ($ownAssessment['estimated_severity'] ?? 'medium') === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="assessment_confidence" class="form-label">Assessment Confidence (0-100)</label><input id="assessment_confidence" name="assessment_confidence" type="number" min="0" max="100" class="form-control" value="<?= (int)($ownAssessment['assessment_confidence'] ?? 50) ?>" required></div><div class="col-12"><label for="assessment_notes" class="form-label">Additional Notes</label><textarea id="assessment_notes" name="assessment_notes" maxlength="3000" rows="4" class="form-control"><?= htmlspecialchars((string)($ownAssessment['assessment_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div><div class="col-12 d-flex gap-2 justify-content-end"><button type="submit" class="btn btn-primary">Submit Independent Assessment</button><?php if ($ownAssessment && $ownAssessment['status'] !== 'withdrawn'): ?><button type="submit" name="action" value="withdraw" class="btn btn-outline-danger">Withdraw Assessment</button><?php endif; ?></div></form></div><?php elseif ($requestId): ?><div class="alert alert-warning">Select one of your eligible requests below.</div><?php endif; ?>
<div class="card shadow-sm border-0 rounded-4 p-4 p-md-5"><h2 class="h5">Eligible Service Requests</h2><?php if (!$eligibleRequests): ?><p class="text-muted mb-0">No matched service requests are available for assessment.</p><?php else: ?><div class="row g-3"><?php foreach ($eligibleRequests as $eligible): ?><div class="col-12"><div class="border rounded-3 p-3 d-flex justify-content-between align-items-center gap-3 flex-wrap"><div><h3 class="h6 mb-1"><?= htmlspecialchars($eligible['title'], ENT_QUOTES, 'UTF-8') ?></h3><p class="small text-muted mb-1"><?= htmlspecialchars($eligible['city'], ENT_QUOTES, 'UTF-8') ?> · Match <?= htmlspecialchars((string)$eligible['match_score'], ENT_QUOTES, 'UTF-8') ?>/100</p><span class="badge text-bg-secondary"><?= htmlspecialchars($eligible['assessment_status'] ? ucfirst($eligible['assessment_status']) : 'Not submitted', ENT_QUOTES, 'UTF-8') ?></span></div><a href="assessments.php?request_id=<?= (int)$eligible['request_id'] ?>" class="btn btn-outline-primary">Open Request</a></div></div><?php endforeach; ?></div><?php endif; ?></div></div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
