<?php
declare(strict_types=1);

require __DIR__ . '/includes/session.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/config/database.php';
requireCustomer();

$imageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$imageId || $imageId < 1) {
    http_response_code(404);
    exit;
}

$stmt = getDatabaseConnection()->prepare(
    'SELECT i.stored_name, i.mime_type, i.file_size
     FROM request_images i
     INNER JOIN service_requests r ON r.id = i.request_id
     WHERE i.id = :image_id AND r.customer_id = :customer_id
     LIMIT 1'
);
$stmt->execute(['image_id' => $imageId, 'customer_id' => (int)getUserId()]);
$image = $stmt->fetch();
if (!$image || !in_array($image['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

$uploadRoot = realpath(__DIR__ . '/uploads/requests');
$filePath = $uploadRoot === false ? false : realpath($uploadRoot . DIRECTORY_SEPARATOR . basename((string)$image['stored_name']));
if ($filePath === false || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $image['mime_type']);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: inline; filename="request-image"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
