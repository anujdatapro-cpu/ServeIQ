<?php
declare(strict_types=1);

const LOGIN_FAILURE_LIMIT = 5;
const LOGIN_FAILURE_WINDOW_SECONDS = 900;
const LOGIN_LOCK_SECONDS = 900;

function loginThrottleHashes(string $identifier, string $ip): array
{
    return [
        hash('sha256', strtolower(trim($identifier))),
        hash('sha256', $ip),
    ];
}

function loginIsThrottled(PDO $pdo, string $identifier, string $ip): bool
{
    [$identifierHash, $ipHash] = loginThrottleHashes($identifier, $ip);
    $stmt = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE identifier_hash = :identifier AND ip_hash = :ip LIMIT 1');
    $stmt->execute(['identifier' => $identifierHash, 'ip' => $ipHash]);
    $lockedUntil = $stmt->fetchColumn();
    return is_string($lockedUntil) && strtotime($lockedUntil) > time();
}

function recordFailedLogin(PDO $pdo, string $identifier, string $ip): void
{
    [$identifierHash, $ipHash] = loginThrottleHashes($identifier, $ip);
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (identifier_hash, ip_hash, failed_attempts, first_failed_at, last_failed_at)
         VALUES (:identifier, :ip, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           failed_attempts = IF(last_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, failed_attempts + 1),
           first_failed_at = IF(last_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), first_failed_at),
           locked_until = IF(
             IF(last_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, failed_attempts + 1) >= 5,
             DATE_ADD(NOW(), INTERVAL 15 MINUTE),
             IF(last_failed_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NULL, locked_until)
           ),
           last_failed_at = NOW()'
    );
    $stmt->execute(['identifier' => $identifierHash, 'ip' => $ipHash]);
}

function clearFailedLogins(PDO $pdo, string $identifier, string $ip): void
{
    [$identifierHash, $ipHash] = loginThrottleHashes($identifier, $ip);
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash = :identifier AND ip_hash = :ip');
    $stmt->execute(['identifier' => $identifierHash, 'ip' => $ipHash]);
}
