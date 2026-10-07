<?php
declare(strict_types=1);

/** Write a security/business event without recording secrets or raw request bodies. */
function writeAuditLog(
    PDO $pdo,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $oldData = null,
    ?array $newData = null,
    ?int $userId = null,
    ?string $role = null
): void {
    $userId ??= isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $role ??= isset($_SESSION['user_role']) ? (string)$_SESSION['user_role'] : null;
    $encode = static fn (?array $data): ?string => $data === null
        ? null
        : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    try {
        $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, role, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent)
         VALUES (:user_id, :role, :action, :entity_type, :entity_id, :old_data, :new_data, :ip, :agent)'
        );
        $stmt->execute([
        ':user_id' => $userId,
        ':role' => $role,
        ':action' => substr($action, 0, 100),
        ':entity_type' => $entityType === null ? null : substr($entityType, 0, 80),
        ':entity_id' => $entityId,
        ':old_data' => $encode($oldData),
        ':new_data' => $encode($newData),
        ':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ':agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    } catch (Throwable $e) {
        // Keep technical details in the server log, never in a user-facing response.
        error_log('ServeIQ audit logging failed: ' . $e->getMessage());
    }
}
