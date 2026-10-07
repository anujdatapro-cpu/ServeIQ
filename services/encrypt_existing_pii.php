<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/encryption.php';

$pdo = getDatabaseConnection();
$counts = ['provider_fields' => 0, 'request_addresses' => 0];
$lastProviderId = 0;
do {
    $stmt = $pdo->prepare(
        "SELECT id, phone, address FROM provider_profiles WHERE id > :id
         AND (phone NOT LIKE 'enc:v1:%' OR address NOT LIKE 'enc:v1:%') ORDER BY id LIMIT 200"
    );
    $stmt->execute(['id' => $lastProviderId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $lastProviderId = (int)$row['id'];
        $phone = decryptSensitiveData((string)$row['phone']);
        $address = decryptSensitiveData((string)$row['address']);
        $update = $pdo->prepare('UPDATE provider_profiles SET phone = :phone, address = :address WHERE id = :id');
        $update->execute(['phone' => encryptSensitiveData($phone), 'address' => encryptSensitiveData($address), 'id' => $lastProviderId]);
        $counts['provider_fields']++;
    }
} while (count($rows) === 200);

$lastRequestId = 0;
do {
    $stmt = $pdo->prepare(
        "SELECT id, address FROM service_requests WHERE id > :id AND address IS NOT NULL
         AND address NOT LIKE 'enc:v1:%' ORDER BY id LIMIT 200"
    );
    $stmt->execute(['id' => $lastRequestId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $lastRequestId = (int)$row['id'];
        $update = $pdo->prepare('UPDATE service_requests SET address = :address WHERE id = :id');
        $update->execute(['address' => encryptSensitiveData((string)$row['address']), 'id' => $lastRequestId]);
        $counts['request_addresses']++;
    }
} while (count($rows) === 200);

printf("Encrypted provider records: %d\nEncrypted request addresses: %d\n", $counts['provider_fields'], $counts['request_addresses']);
