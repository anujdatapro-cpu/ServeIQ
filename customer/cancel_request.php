<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../config/database.php';

requireCustomer();
requireValidCsrfToken();

$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = getDatabaseConnection();
try {
    $pdo->beginTransaction();
    $oldStatusStmt = $pdo->prepare('SELECT status FROM service_requests WHERE id = :request_id AND customer_id = :customer_id FOR UPDATE');
    $oldStatusStmt->execute(['request_id' => $requestId, 'customer_id' => (int)getUserId()]);
    $oldStatus = $oldStatusStmt->fetchColumn();
    $stmt = $pdo->prepare("UPDATE service_requests SET status = 'cancelled' WHERE id = :request_id AND customer_id = :customer_id AND status NOT IN ('completed', 'cancelled')");
    $stmt->execute(['request_id' => $requestId, 'customer_id' => (int)getUserId()]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        header('Location: request_details.php?id=' . $requestId . '&cancel_error=1');
        exit;
    }
    persistMatchingResults($pdo, $requestId, []);
    invalidateADCSAssessmentsForRequest($pdo, $requestId);
    calculateADCSForRequest($pdo, $requestId);
    $pdo->commit();
    writeAuditLog($pdo, 'request_cancelled', 'service_request', (int)$requestId, ['status' => $oldStatus], ['status' => 'cancelled']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($exception->getMessage());
    header('Location: request_details.php?id=' . $requestId . '&cancel_error=1');
    exit;
}

header('Location: request_details.php?id=' . $requestId . '&cancelled=1');
exit;
