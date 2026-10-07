<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/service_dna.php';
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['error' => 'Use POST to request a ServiceDNA preview.']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 12000) {
    $respond(413, ['error' => 'The description is too large to analyze.']);
}

$rawInput = (string)file_get_contents('php://input');
if (strlen($rawInput) > 12000) {
    $respond(413, ['error' => 'The description is too large to analyze.']);
}
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $respond(400, ['error' => 'The preview request could not be read.']);
}

$submittedToken = (string)($input['csrf_token'] ?? '');
if (!csrfTokensMatch($submittedToken, (string)($_SESSION['csrf_token'] ?? ''))) {
    $respond(403, ['error' => 'Your session could not be verified. Refresh the page and try again.']);
}

$now = time();
$previewWindowStart = (int)($_SESSION['service_dna_preview_window'] ?? 0);
if ($previewWindowStart < 1 || $now - $previewWindowStart >= 60) {
    $_SESSION['service_dna_preview_window'] = $now;
    $_SESSION['service_dna_preview_count'] = 0;
}
if ((int)($_SESSION['service_dna_preview_count'] ?? 0) >= 10) {
    $respond(429, ['error' => 'You have reached the preview limit for this minute. Please wait and try again.']);
}
$_SESSION['service_dna_preview_count'] = (int)($_SESSION['service_dna_preview_count'] ?? 0) + 1;

$description = trim((string)($input['description'] ?? ''));
if (!validateProblemDescription($description)) {
    $respond(422, ['error' => 'Describe the problem in at least 20 characters and no more than 5,000 characters.']);
}

try {
    $dna = analyzeServiceDna(getDatabaseConnection(), $description, null, 'medium');
    $respond(200, [
        'analysis_method' => (string)$dna['analysis_method'],
        'category' => $dna['category_name'],
        'problem_type' => $dna['problem_type'],
        'affected_entity' => $dna['affected_entity'],
        'symptoms' => $dna['symptoms'],
        'context' => $dna['context'],
        'urgency' => $dna['detected_urgency'] ?? $dna['user_urgency'],
        'possible_service_types' => $dna['possible_service_types'],
        'confidence_score' => (int)$dna['confidence_score'],
    ]);
} catch (Throwable $e) {
    error_log('ServeIQ ServiceDNA preview failed: ' . $e->getMessage());
    $respond(503, ['error' => 'ServiceDNA preview is unavailable right now. You can still continue to the service request form.']);
}
