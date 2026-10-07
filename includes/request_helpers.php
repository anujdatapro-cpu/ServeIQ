<?php
declare(strict_types=1);

require_once __DIR__ . '/encryption.php';

function requestStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'analyzing' => 'Awaiting analysis',
        'matched' => 'Matched',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function requestStatusClass(string $status): string
{
    return match ($status) {
        'analyzing' => 'text-bg-info',
        'matched' => 'text-bg-primary',
        'in_progress' => 'text-bg-warning',
        'completed' => 'text-bg-success',
        'cancelled' => 'text-bg-danger',
        'draft', 'submitted' => 'text-bg-secondary',
        default => 'text-bg-secondary',
    };
}

function requestUrgencyLabel(string $urgency): string
{
    return match ($urgency) {
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'emergency' => 'Emergency',
        default => ucfirst($urgency),
    };
}

function requestUrgencyClass(string $urgency): string
{
    return match ($urgency) {
        'high' => 'text-bg-warning',
        'emergency' => 'text-bg-danger',
        'medium' => 'text-bg-info',
        default => 'text-bg-secondary',
    };
}

function findCustomerRequest(PDO $pdo, int $requestId, int $customerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, c.category_name
         FROM service_requests r
         LEFT JOIN service_categories c ON c.id = r.category_id
         WHERE r.id = :request_id AND r.customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute([
        'request_id' => $requestId,
        'customer_id' => $customerId,
    ]);

    $request = $stmt->fetch();
    return $request ? decryptSensitiveFields($request, ['address']) : null;
}

function requestImagePath(string $storedName): string
{
    return 'uploads/requests/' . basename($storedName);
}
